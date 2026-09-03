FROM python:3.12-slim-bookworm

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    WHISPER_MODEL=small \
    WHISPER_COMPUTE_TYPE=int8 \
    WHISPER_THREADS=3 \
    WHISPER_MODEL_CACHE=/models \
    TRANSCRIBER_WORK_ROOT=/work/work

WORKDIR /srv/transcriber

COPY requirements.txt .
RUN pip install --no-cache-dir --requirement requirements.txt

RUN python -c "from faster_whisper import WhisperModel; WhisperModel('small', device='cpu', compute_type='int8', download_root='/models')"

COPY server.py .
RUN mkdir -p /work/work && chown -R www-data:www-data /models /work /srv/transcriber

USER www-data
EXPOSE 8000

HEALTHCHECK --interval=15s --timeout=5s --start-period=180s --retries=3 \
  CMD ["python", "-c", "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8000/health', timeout=3)"]

CMD ["python", "server.py"]
