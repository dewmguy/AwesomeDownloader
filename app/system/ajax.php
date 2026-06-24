<?php // ajax.php

error_reporting(E_ALL);

define('TEMP_DIR', '/var/www/html/temp');
define('FINAL_DIR', '/var/www/html/download');
define('JOB_DIR', TEMP_DIR . '/jobs');
define('WORK_DIR', TEMP_DIR . '/work');
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

function enqueueDownload() {
  ensureRuntimeDirs();

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

  $jobId = createJobId();
  $job = [
    'id' => $jobId,
    'url' => $url,
    'audio' => $isMp3,
    'video' => $isGif,
    'reencode' => $reEncode,
    'state' => 'queued',
    'message' => 'Download queued.',
    'createdAt' => time(),
    'updatedAt' => time()
  ];

  if (!writeJob($jobId, $job)) {
    sendJsonResponse(2, "Failed to create download job.");
    return;
  }

  launchJobWorker($jobId);
  sendJsonResponse(0, "Download queued.", ['jobId' => $jobId, 'state' => 'queued']);
}

function createJobId() {
  return bin2hex(random_bytes(12));
}

function ensureRuntimeDirs() {
  foreach ([TEMP_DIR, FINAL_DIR, JOB_DIR, WORK_DIR] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0775, true);
  }
}

function getJobPath($jobId) {
  if (!preg_match('/^[a-f0-9]{24}$/', $jobId)) return null;
  return JOB_DIR . DIRECTORY_SEPARATOR . $jobId . '.json';
}

function readJob($jobId) {
  $path = getJobPath($jobId);
  if ($path === null || !is_file($path)) return null;

  $job = json_decode(file_get_contents($path), true);
  return is_array($job) ? $job : null;
}

function writeJob($jobId, $job) {
  $path = getJobPath($jobId);
  if ($path === null) return false;

  $job['updatedAt'] = time();
  return file_put_contents($path, json_encode($job, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

function updateJob($jobId, $changes) {
  $job = readJob($jobId);
  if ($job === null) return false;

  foreach ($changes as $key => $value) { $job[$key] = $value; }
  return writeJob($jobId, $job);
}

function sendJobStatus($jobId) {
  $job = readJob($jobId);
  if ($job === null) {
    sendJsonResponse(2, "Job not found.");
    return;
  }

  unset($job['url']);
  sendJsonResponse(0, $job['message'] ?? 'Job status loaded.', ['job' => $job]);
}

function launchJobWorker($jobId) {
  $command = sprintf(
    'php %s run-job %s > /dev/null 2>&1 &',
    escapeshellarg(__FILE__),
    escapeshellarg($jobId)
  );
  exec($command);
}

function runJob($jobId) {
  ensureRuntimeDirs();

  $job = readJob($jobId);
  if ($job === null) {
    error_log("Download job not found: $jobId");
    return 1;
  }

  updateJob($jobId, ['state' => 'running', 'message' => 'Starting download.']);
  $workDir = createWorkDir($jobId);
  if ($workDir === null) {
    failJob($jobId, null, "Failed to create temporary work directory.");
    return 1;
  }

  $result = processDownload($jobId, $job, $workDir);
  cleanupWorkDir($workDir);
  return $result ? 0 : 1;
}

function processDownload($jobId, $job, $workDir) {
  $url = $job['url'];
  $isGif = (int)$job['video'];
  $isMp3 = (int)$job['audio'];
  $reEncode = (int)$job['reencode'];

  if ($isGif || $reEncode) {
    updateJob($jobId, ['state' => 'checking', 'message' => 'Checking media duration.']);
    $duration = getMediaDuration($url);
    if ($duration === null) {
      failJob($jobId, null, "Failed to retrieve media duration.");
      return false;
    }
    if ($isGif && $duration > 600) {
      failJob($jobId, null, "Media is too long for GIF conversion. Maximum allowed is 10 minutes.");
      return false;
    }
    if ($reEncode && $duration > 7200) {
      failJob($jobId, null, "Media is too long for AVC re-encoding. Maximum allowed is 2 hours.");
      return false;
    }
  }

  updateJob($jobId, ['state' => 'downloading', 'message' => 'Downloading media.']);
  $command = buildYtDlpCommand($url, $isMp3, $workDir);
  $executionResult = executeCommand($command);

  if ($executionResult['exitCode'] !== 0) {
    failJob($jobId, $executionResult, "Download failed.");
    return false;
  }

  if ($reEncode) {
    updateJob($jobId, ['state' => 'encoding', 'message' => 'Re-encoding video to H264.']);
    if (!reEncodeFiles($workDir)) {
      failJob($jobId, null, "AVC re-encode failed.");
      return false;
    }
  }

  if ($isGif) {
    updateJob($jobId, ['state' => 'encoding', 'message' => 'Converting video to GIF.']);
    if (!handleGifConversion($workDir, FINAL_DIR)) {
      failJob($jobId, null, "GIF conversion failed.");
      return false;
    }
  }

  updateJob($jobId, ['state' => 'finalizing', 'message' => 'Moving processed files.']);
  moveProcessedFiles($workDir, FINAL_DIR);
  updateJob($jobId, ['state' => 'complete', 'message' => 'Download complete.', 'completedAt' => time()]);
  return true;
}

function failJob($jobId, $executionResult, $message) {
  $changes = ['state' => 'failed', 'message' => $message, 'completedAt' => time()];
  if (is_array($executionResult)) {
    $changes['exitCode'] = $executionResult['exitCode'] ?? null;
    $changes['stderr'] = tailText($executionResult['stderr'] ?? '');
  }
  updateJob($jobId, $changes);
}

function tailText($text, $limit = 4000) {
  if (strlen($text) <= $limit) return $text;
  return substr($text, -$limit);
}

function createWorkDir($jobId) {
  $workDir = WORK_DIR . DIRECTORY_SEPARATOR . $jobId;
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
    $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . $file;
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $file;
    if (is_file($sourcePath)) rename($sourcePath, $targetPath);
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
  if (!headers_sent()) header('Content-Type: application/json');
  echo json_encode(array_merge(['status' => $status, 'message' => $message], $additionalData));
}

if (PHP_SAPI === 'cli') {
  $command = $argv[1] ?? '';
  if ($command === 'run-job' && isset($argv[2])) exit(runJob($argv[2]));
  fwrite(STDERR, "Unsupported CLI command.\n");
  exit(1);
}

// Route requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['file'])) { echo deleteFile($_POST['file']) ? 'true' : 'false'; }
  elseif (isset($_POST['url'])) { echo filter_var($_POST['url'], FILTER_VALIDATE_URL) ? 'true' : 'false'; }
  elseif (isset($_POST['download'])) { enqueueDownload(); }
  else { sendJsonResponse(2, "Invalid Request"); }
}
elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['refresh']) && $_GET['refresh'] === "downloads") { listDownloads(); }
elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['job'])) { sendJobStatus($_GET['job']); }
else { sendJsonResponse(2, "Unsupported Request Method."); }

?>
