<?php // ajax.php

error_reporting(E_ALL);

define('TEMP_DIR', '/var/www/html/temp');
define('FINAL_DIR', '/var/www/html/download');
define('SUPPORTED_EXTENSIONS', ['mp3', 'mp4', 'gif', 'webm']);
define('ENCODE_EXTENSIONS', ['mov', 'mkv', 'avi', 'flv']);
define('OUTPUT_TEMPLATE', '%(title).180B [%(id)s].%(ext)s');

function listDownloads() {
  $files = getFilteredFiles(FINAL_DIR, SUPPORTED_EXTENSIONS);
  if (!empty($files)) {
    usort($files, fn($a, $b) => filemtime(FINAL_DIR . DIRECTORY_SEPARATOR . $b) - filemtime(FINAL_DIR . DIRECTORY_SEPARATOR . $a));
    $htmlFiles = array_map(fn($file) => formatFileListItem($file), $files);
    echo implode('', $htmlFiles);
  }
  else { echo "<li>No downloads found.</li>"; }
}

function getFilteredFiles($dir, $extensions) {
  if (!is_dir($dir)) return [];

  $files = array_diff(scandir($dir), ['.', '..']);
  return array_filter($files, fn($file) => isValidFile($dir, $file, $extensions));
}

function isValidFile($dir, $file, $extensions) {
  $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
  return !is_dir($dir . DIRECTORY_SEPARATOR . $file) && in_array($ext, $extensions);
}

function formatFileListItem($file) {
  $escapedFile = htmlspecialchars($file, ENT_QUOTES);
  $encodedFile = rawurlencode($file);
  return sprintf(
    '<li><a target="_blank" download href="/download/%1$s">' .
    '<i class="link fa-solid fa-floppy-disk"></i></a> ' .
    '<i data-file="%2$s" class="link delete fa-solid fa-trash"></i>' .
    '<p class="link play" data-file="%2$s">%2$s</p></li>',
    $encodedFile,
    $escapedFile
  );
}

function deleteFile($fileName) {
  $filePath = FINAL_DIR . DIRECTORY_SEPARATOR . basename($fileName);
  return file_exists($filePath) && unlink($filePath);
}

function durationToSeconds($duration) {
  $parts = explode(':', $duration);
  $seconds = 0;
  foreach ($parts as $part) { $seconds = $seconds * 60 + (int)$part; }
  return $seconds;
}

function getMediaDuration($url) {
  $command = sprintf("yt-dlp --get-duration %s 2>&1", escapeshellarg($url));
  exec($command, $output, $retCode);
  if ($retCode === 0 && !empty($output)) { return durationToSeconds(trim(end($output))); }
  error_log("Failed to retrieve duration metadata for media: $url");
  return null;
}

function downloadVideo() {
  if (!is_dir(TEMP_DIR)) mkdir(TEMP_DIR, 0775, true);
  if (!is_dir(FINAL_DIR)) mkdir(FINAL_DIR, 0775, true);

  $url = $_POST['download'] ?? '';
  if (!filter_var($url, FILTER_VALIDATE_URL)) {
    sendJsonResponse(2, "Invalid URL");
    return;
  }

  $isGif = (int)($_POST['video'] ?? 0);
  $isMp3 = (int)($_POST['audio'] ?? 0);
  $reEncode = (int)($_POST['reencode'] ?? 0);

  if ($isGif + $isMp3 + $reEncode > 1) {
    error_log("More than one encoding selection was passed.");
    sendJsonResponse(2, "Cannot process more than one selection of encoding.");
    return;
  }

  if ($isGif || $reEncode) {
    $duration = getMediaDuration($url);
    if ($duration === null) {
      sendJsonResponse(2, "Failed to retrieve media duration.");
      return;
    }
    if ($isGif && $duration > 600) {
      sendJsonResponse(2, "Media is too long for GIF conversion. Maximum allowed is 10 minutes.");
      return;
    }
    if ($reEncode && $duration > 7200) {
      sendJsonResponse(2, "Media is too long for AVC re-encoding. Maximum allowed is 2 hours.");
      return;
    }
  }

  $workDir = createWorkDir();
  if ($workDir === null) {
    sendJsonResponse(2, "Failed to create temporary work directory.");
    return;
  }

  $command = buildYtDlpCommand($url, $isMp3, $workDir);
  $executionResult = executeCommand($command);

  if ($executionResult['exitCode'] !== 0) {
    cleanupWorkDir($workDir);
    sendJsonResponse(2, "Process initiation failed.", $executionResult);
    return;
  }

  if ($reEncode && !reEncodeFiles($workDir)) {
    cleanupWorkDir($workDir);
    sendJsonResponse(2, "AVC Re-Encode Failed");
    return;
  }

  if ($isGif && !handleGifConversion($workDir, FINAL_DIR)) {
    cleanupWorkDir($workDir);
    sendJsonResponse(2, "GIF Conversion Failed");
    return;
  }

  moveProcessedFiles($workDir, FINAL_DIR);
  cleanupWorkDir($workDir);
  sendJsonResponse(0, "Download and processing successful.", $executionResult);
}

function createWorkDir() {
  $workDir = TEMP_DIR . DIRECTORY_SEPARATOR . 'job-' . bin2hex(random_bytes(8));
  return mkdir($workDir, 0775, true) ? $workDir : null;
}

function convertToGif($inputFile, $outputFile) {
  $command = sprintf(
    "ffmpeg -i %s -vf \"fps=10,scale=320:-1:flags=lanczos\" -c:v gif %s",
    escapeshellarg($inputFile),
    escapeshellarg($outputFile)
  );
  exec($command, $output, $retCode);
  return $retCode === 0;
}

function handleGifConversion($tempDir, $finalDir) {
  $videoFile = glob($tempDir . '/*.mp4');
  if (empty($videoFile)) {
    error_log("No MP4 files found in temporary directory for GIF conversion.");
    return false;
  }
  $inputFile = $videoFile[0];
  $outputFile = $finalDir . '/' . pathinfo($inputFile, PATHINFO_FILENAME) . '.gif';
  if (!convertToGif($inputFile, $outputFile)) {
    error_log("GIF conversion failed for file: $inputFile");
    return false;
  }
  unlink($inputFile);
  return true;
}

function buildYtDlpCommand($url, $isMp3, $workDir) {
  $urlEscaped = escapeshellarg($url);
  $outputPath = "-o " . escapeshellarg(OUTPUT_TEMPLATE) . " -P " . escapeshellarg($workDir);
  $flags = $isMp3 ? "-x --audio-format mp3 --audio-quality 0" : "--merge-output-format mp4";
  return sprintf("yt-dlp %s --no-mtime %s %s", $flags, $urlEscaped, $outputPath);
}

function executeCommand($command) {
  $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
  if ($process === false) return ['exitCode' => 1, 'stderr' => 'Failed to open process.'];

  $stdout = stream_get_contents($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  array_map('fclose', $pipes);
  $exitCode = proc_close($process);

  return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
}

function reEncodeFiles($directory) {
  $files = glob($directory . '/*.{mp4,mov,mkv,avi,flv}', GLOB_BRACE);
  foreach ($files as $file) {
    $filePath = escapeshellarg($file);
    $codec = getVideoCodec($filePath);

    if ($codec !== 'h264') {
      if (!reEncodeFile($file, $directory)) {
        error_log("Error re-encoding file: $file");
        error_log("File Path: $file");
        error_log("Codec: $codec");
        return false;
      }
    }
  }
  return true;
}

function getVideoCodec($file) {
  $ffprobeCommand = sprintf("ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of csv=p=0 %s", $file);
  exec($ffprobeCommand, $output, $retCode);

  if ($retCode === 0 && isset($output[0])) {
    return trim($output[0]);
  }

  error_log("Unable to determine codec for file: $file");
  error_log("ffprobe Command: $ffprobeCommand");
  return null;
}

function reEncodeFile($file, $directory) {
  $filePath = escapeshellarg($file);
  $outputFile = escapeshellarg($directory . '/' . pathinfo($file, PATHINFO_FILENAME) . '_avc.mp4');

  $ffmpegCommand = "ffmpeg -i $filePath -c:v libx264 -c:a aac -strict experimental $outputFile";
  exec($ffmpegCommand, $output, $retCode);

  if ($retCode === 0) {
    unlink($file);
    return true;
  }

  error_log("FFmpeg Command: $ffmpegCommand");
  error_log("FFmpeg Output: " . implode("\n", $output));
  error_log("Return Code: $retCode");
  return false;
}

function moveProcessedFiles($sourceDir, $targetDir) {
  $files = array_diff(scandir($sourceDir), ['.', '..']);
  foreach ($files as $file) {
    rename($sourceDir . DIRECTORY_SEPARATOR . $file, $targetDir . DIRECTORY_SEPARATOR . $file);
  }
}

function cleanupWorkDir($directory) {
  if (!is_dir($directory)) return;

  $files = array_diff(scandir($directory), ['.', '..']);
  foreach ($files as $file) {
    $path = $directory . DIRECTORY_SEPARATOR . $file;
    if (is_dir($path)) cleanupWorkDir($path);
    else unlink($path);
  }
  rmdir($directory);
}

function sendJsonResponse($status, $message, $additionalData = []) {
  echo json_encode(array_merge(['status' => $status, 'message' => $message], $additionalData));
}

// Route requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['file'])) { echo deleteFile($_POST['file']) ? 'true' : 'false'; }
  elseif (isset($_POST['url'])) { echo filter_var($_POST['url'], FILTER_VALIDATE_URL) ? 'true' : 'false'; }
  elseif (isset($_POST['download'])) { downloadVideo(); }
  else { sendJsonResponse(2, "Invalid Request"); }
}
elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['refresh']) && $_GET['refresh'] === "downloads") { listDownloads(); }
else { sendJsonResponse(2, "Unsupported Request Method."); }

?>
