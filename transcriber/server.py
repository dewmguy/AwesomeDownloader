import json
import os
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path


MODEL_NAME = os.getenv("WHISPER_MODEL", "small")
COMPUTE_TYPE = os.getenv("WHISPER_COMPUTE_TYPE", "int8")
MODEL_CACHE = Path(os.getenv("WHISPER_MODEL_CACHE", "/models")).resolve()
WORK_ROOT = Path(os.getenv("TRANSCRIBER_WORK_ROOT", "/work/work")).resolve()
THREADS = max(1, int(os.getenv("WHISPER_THREADS", "3")))
PORT = int(os.getenv("TRANSCRIBER_PORT", "8000"))
ALLOWED_AUDIO_EXTENSIONS = {".mp3", ".m4a", ".wav", ".webm"}
_model = None
_transcription_lock = threading.Lock()


def get_model():
    global _model
    if _model is None:
        from faster_whisper import WhisperModel

        _model = WhisperModel(
            MODEL_NAME,
            device="cpu",
            compute_type=COMPUTE_TYPE,
            cpu_threads=THREADS,
            num_workers=1,
            download_root=str(MODEL_CACHE),
        )
    return _model


def resolve_work_path(raw_path, *, output=False):
    if not isinstance(raw_path, str) or not raw_path:
        raise ValueError("A work path is required.")
    path = Path(raw_path)
    if not path.is_absolute():
        raise ValueError("Work paths must be absolute.")
    try:
        resolved = path.resolve(strict=not output)
    except FileNotFoundError as exc:
        raise ValueError("The input audio file does not exist.") from exc
    try:
        resolved.relative_to(WORK_ROOT)
    except ValueError as exc:
        raise ValueError("The requested path is outside the job workspace.") from exc

    if output:
        if resolved.suffix.lower() != ".txt" or not resolved.parent.is_dir():
            raise ValueError("Transcript output must be a .txt file in an existing job workspace.")
    elif resolved.suffix.lower() not in ALLOWED_AUDIO_EXTENSIONS or not resolved.is_file():
        raise ValueError("The input must be a supported audio file.")
    return resolved


def format_timestamp(seconds):
    total_seconds = max(0, int(seconds))
    hours, remainder = divmod(total_seconds, 3600)
    minutes, seconds = divmod(remainder, 60)
    return f"{hours:02d}:{minutes:02d}:{seconds:02d}"


def format_transcript(source_name, segments, info):
    language = getattr(info, "language", "unknown")
    probability = float(getattr(info, "language_probability", 0.0))
    duration = float(getattr(info, "duration", 0.0))
    lines = [
        f"Transcript: {source_name}",
        f"Language: {language} ({probability:.0%} confidence)",
        f"Model: Whisper {MODEL_NAME} ({COMPUTE_TYPE})",
        f"Audio duration: {format_timestamp(duration)}",
        "",
    ]
    for segment in segments:
        text = str(getattr(segment, "text", "")).strip()
        if text:
            lines.append(f"[{format_timestamp(getattr(segment, 'start', 0))}] {text}")
    lines.append("")
    return "\n".join(lines)


def transcribe_file(input_path, output_path):
    started = time.perf_counter()
    segments, info = get_model().transcribe(
        str(input_path),
        beam_size=5,
        vad_filter=True,
        condition_on_previous_text=True,
    )
    segments = list(segments)
    transcript = format_transcript(input_path.name, segments, info)
    temporary_path = output_path.with_suffix(output_path.suffix + ".tmp")
    temporary_path.write_text(transcript, encoding="utf-8")
    os.replace(temporary_path, output_path)
    return {
        "model": MODEL_NAME,
        "language": getattr(info, "language", "unknown"),
        "segments": len(segments),
        "audio_duration_seconds": round(float(getattr(info, "duration", 0.0)), 3),
        "elapsed_seconds": round(time.perf_counter() - started, 3),
    }


class TranscriberHandler(BaseHTTPRequestHandler):
    server_version = "AwesomeDownloaderTranscriber/1.0"

    def log_message(self, message_format, *args):
        print(f"{self.address_string()} - {message_format % args}", flush=True)

    def send_json(self, status, payload):
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path != "/health":
            self.send_json(404, {"error": "Not found."})
            return
        self.send_json(200, {"status": "ready", "model": MODEL_NAME, "compute_type": COMPUTE_TYPE, "threads": THREADS})

    def do_POST(self):
        if self.path != "/transcribe":
            self.send_json(404, {"error": "Not found."})
            return
        try:
            content_length = int(self.headers.get("Content-Length", "0"))
            if content_length < 2 or content_length > 65536:
                raise ValueError("The request body is invalid.")
            payload = json.loads(self.rfile.read(content_length))
            input_path = resolve_work_path(payload.get("input_path"))
            output_path = resolve_work_path(payload.get("output_path"), output=True)
            # Keep inference sequential on this shared CPU while allowing health
            # checks to be served by another request thread.
            with _transcription_lock:
                result = transcribe_file(input_path, output_path)
            self.send_json(200, {"status": "complete", **result})
        except (ValueError, json.JSONDecodeError) as exc:
            self.send_json(400, {"error": str(exc)})
        except Exception as exc:
            print(f"Transcription error: {type(exc).__name__}: {exc}", flush=True)
            self.send_json(500, {"error": "Transcription failed."})


def main():
    MODEL_CACHE.mkdir(parents=True, exist_ok=True)
    WORK_ROOT.mkdir(parents=True, exist_ok=True)
    print(f"Loading Whisper {MODEL_NAME} on {THREADS} CPU threads with {COMPUTE_TYPE}...", flush=True)
    get_model()
    print(f"Transcriber ready on port {PORT}.", flush=True)
    ThreadingHTTPServer(("0.0.0.0", PORT), TranscriberHandler).serve_forever()


if __name__ == "__main__":
    main()
