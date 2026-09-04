<?php // ajax.php

error_reporting(E_ALL);

define('TEMP_DIR', getenv('DOWNLOADER_TEMP_DIR') ?: '/var/www/html/temp');
define('FINAL_DIR', getenv('DOWNLOADER_FINAL_DIR') ?: '/var/www/html/download');
define('JOB_DIR', TEMP_DIR . '/jobs');
define('WORK_DIR', TEMP_DIR . '/work');
define('QUEUE_LOCK', TEMP_DIR . '/queue.lock');
define('URL_VALIDATION_CACHE_DIR', TEMP_DIR . '/url-validation');
define('URL_VALIDATION_LOCK', TEMP_DIR . '/url-validation.lock');
define('GIF_MAX_SECONDS', (int)(getenv('DOWNLOADER_GIF_MAX_SECONDS') ?: 600));
define('REENCODE_MAX_SECONDS', (int)(getenv('DOWNLOADER_REENCODE_MAX_SECONDS') ?: 7200));
define('MAX_BATCH_ITEMS', (int)(getenv('DOWNLOADER_MAX_BATCH_ITEMS') ?: 20));
define('PLAYLIST_MAX_ITEMS', (int)(getenv('DOWNLOADER_PLAYLIST_MAX_ITEMS') ?: 50));
define('URL_MAX_LENGTH', 2048);
define('URL_VALIDATION_TIMEOUT', max(5, (int)(getenv('DOWNLOADER_URL_VALIDATION_TIMEOUT') ?: 25)));
define('URL_VALIDATION_CACHE_SECONDS', max(30, (int)(getenv('DOWNLOADER_URL_VALIDATION_CACHE_SECONDS') ?: 600)));
define('TRANSCRIBER_URL', rtrim(getenv('DOWNLOADER_TRANSCRIBER_URL') ?: 'http://downloader-transcriber:8000', '/'));
define('TRANSCRIBER_SHARED_TEMP_DIR', rtrim(getenv('DOWNLOADER_TRANSCRIBER_SHARED_TEMP_DIR') ?: '/work', '/'));
define('TRANSCRIPTION_TIMEOUT', (int)(getenv('DOWNLOADER_TRANSCRIPTION_TIMEOUT') ?: 14400));
define('SUPPORTED_EXTENSIONS', ['mp3', 'mp4', 'gif', 'webm', 'txt']);
define('ENCODE_EXTENSIONS', ['mp4', 'mov', 'mkv', 'avi', 'flv', 'webm']);
define('VALID_MODES', ['base', 'h264', 'gif', 'mp3', 'transcribe']);
define('OUTPUT_TEMPLATE', getenv('DOWNLOADER_OUTPUT_TEMPLATE') ?: '%(playlist_index|)s%(playlist_index& - |)s%(title).180B [%(id)s].%(ext)s');

function listDownloads() {
  $files = getFilteredFiles(FINAL_DIR, SUPPORTED_EXTENSIONS);
  if (!empty($files)) {
    usort($files, fn($a, $b) => filemtime(FINAL_DIR . DIRECTORY_SEPARATOR . $b) - filemtime(FINAL_DIR . DIRECTORY_SEPARATOR . $a));
    echo implode('', array_map(fn($file) => formatFileListItem($file), $files));
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
  return !is_dir($dir . DIRECTORY_SEPARATOR . $file) && in_array($ext, $extensions, true);
}

function formatFileListItem($file) {
  $escapedFile = htmlspecialchars($file, ENT_QUOTES);
  $encodedFile = rawurlencode($file);
  $previewClass = strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'txt' ? ' transcript' : '';
  return sprintf(
    '<li><a target="_blank" download href="/download/%1$s">' .
    '<i class="link fa-solid fa-floppy-disk"></i></a> ' .
    '<i data-file="%2$s" class="link delete fa-solid fa-trash"></i>' .
    '<p class="link play%3$s" data-file="%2$s">%2$s</p></li>',
    $encodedFile,
    $escapedFile,
    $previewClass
  );
}

function deleteFile($fileName) {
  $filePath = FINAL_DIR . DIRECTORY_SEPARATOR . basename($fileName);
  return file_exists($filePath) && unlink($filePath);
}

function durationToSeconds($duration) {
  $parts = explode(':', $duration);
  $seconds = 0;
  foreach ($parts as $part) $seconds = $seconds * 60 + (float)$part;
  return $seconds;
}

function parseDurationOutput($output) {
  $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $output)), fn($line) => $line !== ''));
  if (empty($lines)) return null;
  $durations = [];
  foreach ($lines as $line) {
    if (is_numeric($line)) $durations[] = (float)$line;
    elseif (preg_match('/^\d+(?::\d+){1,2}(?:\.\d+)?$/', $line)) $durations[] = durationToSeconds($line);
    else return null;
  }
  return $durations;
}

function getMediaDurations($url) {
  $result = executeCommand([
    'yt-dlp', '--yes-playlist', '--playlist-end', (string)PLAYLIST_MAX_ITEMS,
    '--skip-download', '--print', '%(duration)s', $url
  ]);
  $durations = parseDurationOutput($result['stdout'] ?? '');
  if ($result['exitCode'] === 0 && $durations !== null) return $durations;
  error_log("Failed to retrieve duration metadata for media: $url");
  if (!empty($result['stderr'])) error_log($result['stderr']);
  return null;
}

function invalidUrlResult($message) {
  return ['valid' => false, 'message' => $message];
}

function validateUrlStructure($url) {
  $url = trim((string)$url);
  if ($url === '' || strlen($url) > URL_MAX_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $url)) {
    return invalidUrlResult('Enter a complete web URL no longer than ' . URL_MAX_LENGTH . ' characters.');
  }

  $parts = parse_url($url);
  $scheme = strtolower((string)($parts['scheme'] ?? ''));
  if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
    return invalidUrlResult('Enter a complete http:// or https:// URL.');
  }
  if (isset($parts['user']) || isset($parts['pass'])) {
    return invalidUrlResult('URLs containing a username or password are not allowed.');
  }
  if (isset($parts['port']) && !in_array((int)$parts['port'], [80, 443], true)) {
    return invalidUrlResult('Only standard web ports are allowed.');
  }

  $host = strtolower(rtrim(trim((string)($parts['host'] ?? ''), '[]'), '.'));
  if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
    return invalidUrlResult('Use a public media-site address instead of a direct IP address.');
  }
  if (strlen($host) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $host)) {
    return invalidUrlResult('The URL hostname is invalid.');
  }

  foreach (['localhost', 'local', 'internal', 'home', 'lan', 'test', 'invalid', 'example', 'onion'] as $privateSuffix) {
    if ($host === $privateSuffix || str_ends_with($host, '.' . $privateSuffix)) {
      return invalidUrlResult('Local and reserved hostnames are not allowed.');
    }
  }
  foreach (['example.com', 'example.net', 'example.org', 'home.arpa'] as $reservedHost) {
    if ($host === $reservedHost || str_ends_with($host, '.' . $reservedHost)) {
      return invalidUrlResult('Local and reserved hostnames are not allowed.');
    }
  }

  return ['valid' => true, 'message' => 'The URL format is valid.', 'url' => $url, 'host' => $host];
}

function resolveHostAddresses($host) {
  $addresses = [];
  if (function_exists('dns_get_record')) {
    $records = @dns_get_record($host, DNS_A | DNS_AAAA);
    if (is_array($records)) {
      foreach ($records as $record) {
        if (!empty($record['ip'])) $addresses[] = $record['ip'];
        if (!empty($record['ipv6'])) $addresses[] = $record['ipv6'];
      }
    }
  }
  if (empty($addresses) && function_exists('gethostbynamel')) {
    $ipv4Addresses = @gethostbynamel($host);
    if (is_array($ipv4Addresses)) $addresses = array_merge($addresses, $ipv4Addresses);
  }
  return array_values(array_unique($addresses));
}

function isIpInCidr($address, $network, $prefixLength) {
  $addressBytes = @inet_pton($address);
  $networkBytes = @inet_pton($network);
  if ($addressBytes === false || $networkBytes === false || strlen($addressBytes) !== strlen($networkBytes)) return false;

  $fullBytes = intdiv($prefixLength, 8);
  if ($fullBytes > 0 && substr($addressBytes, 0, $fullBytes) !== substr($networkBytes, 0, $fullBytes)) return false;
  $remainingBits = $prefixLength % 8;
  if ($remainingBits === 0) return true;
  $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
  return (ord($addressBytes[$fullBytes]) & $mask) === (ord($networkBytes[$fullBytes]) & $mask);
}

function isPublicIpAddress($address) {
  if (!filter_var($address, FILTER_VALIDATE_IP)) return false;

  $blockedNetworks = str_contains($address, ':')
    ? [
      ['::', 96], ['::1', 128], ['::ffff:0:0', 96], ['64:ff9b::', 96],
      ['100::', 64], ['2001::', 23], ['2001:db8::', 32], ['2002::', 16],
      ['fc00::', 7], ['fe80::', 10], ['fec0::', 10], ['ff00::', 8]
    ]
    : [
      ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8],
      ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24],
      ['192.88.99.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24],
      ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4]
    ];
  foreach ($blockedNetworks as [$network, $prefixLength]) {
    if (isIpInCidr($address, $network, $prefixLength)) return false;
  }
  return true;
}

function validatePublicUrlTarget($url, $resolver = null) {
  $structure = validateUrlStructure($url);
  if (!$structure['valid']) return $structure;

  try {
    $addresses = $resolver === null ? resolveHostAddresses($structure['host']) : $resolver($structure['host']);
  }
  catch (Throwable $error) {
    $addresses = [];
  }
  if (!is_array($addresses) || empty($addresses)) {
    return invalidUrlResult('The URL hostname could not be resolved.');
  }
  foreach ($addresses as $address) {
    if (!is_string($address) || !isPublicIpAddress($address)) {
      return invalidUrlResult('The URL must resolve only to public internet addresses.');
    }
  }
  return $structure;
}

function buildYtDlpValidationCommand($url) {
  return [
    'yt-dlp', '--yes-playlist', '--playlist-end', '1', '--skip-download',
    '--dump-single-json', '--no-warnings', '--socket-timeout', '10',
    '--retries', '1', '--extractor-retries', '1', $url
  ];
}

function parseYtDlpValidationOutput($output) {
  $metadata = json_decode(trim((string)$output), true);
  if (!is_array($metadata) || empty($metadata['id'])) return null;

  $type = (string)($metadata['_type'] ?? 'video');
  $hasMedia = !empty($metadata['url']) || !empty($metadata['formats']) || !empty($metadata['requested_formats']);
  if (in_array($type, ['playlist', 'multi_video'], true)) {
    $hasMedia = isset($metadata['entries']) && is_array($metadata['entries']) && count($metadata['entries']) > 0;
  }
  if (!$hasMedia) return null;

  $extractor = trim((string)($metadata['extractor_key'] ?? $metadata['extractor'] ?? 'yt-dlp'));
  if (!preg_match('/^[a-z0-9 _.-]{1,80}$/i', $extractor)) $extractor = 'yt-dlp';
  return ['extractor' => $extractor];
}

function formatExtractorName($extractor) {
  $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $extractor));
  if (str_starts_with($normalized, 'youtube')) return 'YouTube';
  if ($normalized === 'generic') return 'yt-dlp';
  return $extractor;
}

function urlValidationCachePath($url) {
  return URL_VALIDATION_CACHE_DIR . DIRECTORY_SEPARATOR . hash('sha256', $url) . '.json';
}

function readUrlValidationCache($url) {
  $path = urlValidationCachePath($url);
  if (!is_file($path)) return null;
  $result = json_decode((string)file_get_contents($path), true);
  if (!is_array($result) || !isset($result['valid'], $result['message'])) return null;
  $cacheSeconds = $result['valid'] ? URL_VALIDATION_CACHE_SECONDS : min(60, URL_VALIDATION_CACHE_SECONDS);
  return filemtime($path) < time() - $cacheSeconds ? null : $result;
}

function writeUrlValidationCache($url, $result) {
  if (!is_dir(URL_VALIDATION_CACHE_DIR) && !@mkdir(URL_VALIDATION_CACHE_DIR, 0775, true) && !is_dir(URL_VALIDATION_CACHE_DIR)) return;
  $path = urlValidationCachePath($url);
  $temporaryPath = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
  if (file_put_contents($temporaryPath, json_encode($result), LOCK_EX) !== false) rename($temporaryPath, $path);
  else @unlink($temporaryPath);
}

function validateMediaUrl($url, $resolver = null, $runner = null, $useCache = true) {
  $target = validatePublicUrlTarget($url, $resolver);
  if (!$target['valid']) return $target;
  $url = $target['url'];

  if (!ensureRuntimeDirs()) return invalidUrlResult('URL validation is temporarily unavailable.');
  if ($useCache) {
    $cached = readUrlValidationCache($url);
    if ($cached !== null) return $cached;
  }

  $lockHandle = @fopen(URL_VALIDATION_LOCK, 'c');
  if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    if (is_resource($lockHandle)) fclose($lockHandle);
    return invalidUrlResult('The URL checker is busy. Try again in a moment.');
  }

  if ($useCache) {
    $cached = readUrlValidationCache($url);
    if ($cached !== null) {
      flock($lockHandle, LOCK_UN);
      fclose($lockHandle);
      return $cached;
    }
  }

  $command = buildYtDlpValidationCommand($url);
  $execution = $runner === null
    ? executeCommand($command, URL_VALIDATION_TIMEOUT)
    : $runner($command, URL_VALIDATION_TIMEOUT);
  $metadata = ($execution['exitCode'] ?? 1) === 0
    ? parseYtDlpValidationOutput($execution['stdout'] ?? '')
    : null;

  if (!empty($execution['timedOut'])) {
    $result = invalidUrlResult('The media site took too long to validate. Try again.');
  }
  elseif ($metadata === null) {
    $result = invalidUrlResult('yt-dlp could not find downloadable media at this URL.');
  }
  else {
    $extractorName = formatExtractorName($metadata['extractor']);
    $result = [
      'valid' => true,
      'message' => 'Supported by ' . $extractorName . '.',
      'extractor' => $metadata['extractor']
    ];
  }

  if ($useCache) writeUrlValidationCache($url, $result);
  flock($lockHandle, LOCK_UN);
  fclose($lockHandle);
  return $result;
}

function normalizeQueueItems($rawItems) {
  if (is_string($rawItems)) $rawItems = json_decode($rawItems, true);
  if (!is_array($rawItems)) return ['items' => [], 'errors' => ['The queue payload is invalid.']];
  if (count($rawItems) < 1) return ['items' => [], 'errors' => ['Add at least one URL.']];
  if (count($rawItems) > MAX_BATCH_ITEMS) return ['items' => [], 'errors' => ['A batch can contain at most ' . MAX_BATCH_ITEMS . ' URLs.']];

  $items = [];
  $errors = [];
  foreach ($rawItems as $index => $rawItem) {
    $number = $index + 1;
    $url = trim((string)($rawItem['url'] ?? ''));
    $mode = strtolower(trim((string)($rawItem['mode'] ?? 'base')));
    $urlValidation = validateUrlStructure($url);
    if (!$urlValidation['valid']) $errors[] = "URL $number: " . $urlValidation['message'];
    if (!in_array($mode, VALID_MODES, true)) $errors[] = "URL $number has an invalid download choice.";
    $items[] = ['url' => $url, 'mode' => $mode];
  }
  return ['items' => $items, 'errors' => $errors];
}

function legacyQueueItem() {
  $mode = 'base';
  if ((int)($_POST['reencode'] ?? 0) === 1) $mode = 'h264';
  if ((int)($_POST['video'] ?? 0) === 1) $mode = 'gif';
  if ((int)($_POST['audio'] ?? 0) === 1) $mode = 'mp3';
  return [['url' => $_POST['download'] ?? '', 'mode' => $mode]];
}

function enqueueDownloads($rawItems) {
  ensureRuntimeDirs();
  $normalized = normalizeQueueItems($rawItems);
  if (!empty($normalized['errors'])) {
    sendJsonResponse(2, implode(' ', $normalized['errors']));
    return;
  }

  $validationResults = [];
  $validationErrors = [];
  foreach ($normalized['items'] as $index => $item) {
    $cacheKey = hash('sha256', $item['url']);
    if (!isset($validationResults[$cacheKey])) $validationResults[$cacheKey] = validateMediaUrl($item['url']);
    if (!$validationResults[$cacheKey]['valid']) {
      $validationErrors[] = 'URL ' . ($index + 1) . ': ' . $validationResults[$cacheKey]['message'];
    }
  }
  if (!empty($validationErrors)) {
    sendJsonResponse(2, implode(' ', $validationErrors));
    return;
  }

  $createdJobs = [];
  foreach ($normalized['items'] as $queueIndex => $item) {
    $jobId = createJobId();
    $job = [
      'id' => $jobId,
      'url' => $item['url'],
      'mode' => $item['mode'],
      'state' => 'queued',
      'message' => 'Waiting in queue.',
      'createdAt' => microtime(true) + ($queueIndex / 1000),
      'updatedAt' => time()
    ];
    if (!writeJob($jobId, $job)) {
      failCreatedJobs($createdJobs, 'The batch could not be queued.');
      sendJsonResponse(2, 'Failed to create the download queue.');
      return;
    }
    $createdJobs[] = $job;
  }

  launchQueueWorker();
  sendJsonResponse(0, count($createdJobs) . (count($createdJobs) === 1 ? ' item queued.' : ' items queued.'), [
    'jobIds' => array_column($createdJobs, 'id'),
    'jobs' => array_map(fn($job) => formatJobForClient($job), $createdJobs),
    'state' => 'queued'
  ]);
}

function failCreatedJobs($jobs, $message) {
  foreach ($jobs as $job) updateJob($job['id'], ['state' => 'failed', 'message' => $message, 'completedAt' => time()]);
}

function createJobId() { return bin2hex(random_bytes(12)); }

function ensureRuntimeDirs() {
  $ready = true;
  foreach ([TEMP_DIR, FINAL_DIR, JOB_DIR, WORK_DIR, URL_VALIDATION_CACHE_DIR] as $dir) {
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) $ready = false;
  }
  return $ready;
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
  $temporaryPath = $path . '.tmp';
  if (file_put_contents($temporaryPath, json_encode($job, JSON_PRETTY_PRINT), LOCK_EX) === false) return false;
  return rename($temporaryPath, $path);
}

function updateJob($jobId, $changes) {
  $job = readJob($jobId);
  if ($job === null) return false;
  foreach ($changes as $key => $value) $job[$key] = $value;
  return writeJob($jobId, $job);
}

function getJobMode($job) {
  if (isset($job['mode']) && in_array($job['mode'], VALID_MODES, true)) return $job['mode'];
  if ((int)($job['audio'] ?? 0) === 1) return 'mp3';
  if ((int)($job['video'] ?? 0) === 1) return 'gif';
  if ((int)($job['reencode'] ?? 0) === 1) return 'h264';
  return 'base';
}

function formatJobForClient($job) {
  return [
    'id' => $job['id'],
    'mode' => getJobMode($job),
    'state' => $job['state'] ?? 'unknown',
    'message' => $job['message'] ?? 'Job status loaded.',
    'outputCount' => (int)($job['outputCount'] ?? 0),
    'createdAt' => $job['createdAt'] ?? null,
    'completedAt' => $job['completedAt'] ?? null
  ];
}

function sendJobStatus($jobId) {
  $job = readJob($jobId);
  if ($job === null) {
    sendJsonResponse(2, 'Job not found.');
    return;
  }
  sendJsonResponse(0, $job['message'] ?? 'Job status loaded.', ['job' => formatJobForClient($job)]);
}

function sendJobsStatus($jobIds) {
  $ids = array_values(array_unique(array_filter(explode(',', $jobIds), fn($id) => preg_match('/^[a-f0-9]{24}$/', $id))));
  if (empty($ids) || count($ids) > MAX_BATCH_ITEMS * 5) {
    sendJsonResponse(2, 'The job list is invalid.');
    return;
  }
  $jobs = [];
  foreach ($ids as $id) {
    $job = readJob($id);
    if ($job !== null) $jobs[] = formatJobForClient($job);
  }
  sendJsonResponse(0, 'Queue status loaded.', ['jobs' => $jobs]);
}

function launchQueueWorker() {
  $command = sprintf('php %s run-queue > /dev/null 2>&1 &', escapeshellarg(__FILE__));
  exec($command);
}

function findQueuedJobIds() {
  $jobs = [];
  foreach (glob(JOB_DIR . '/*.json') ?: [] as $path) {
    $job = json_decode(file_get_contents($path), true);
    if (is_array($job) && ($job['state'] ?? '') === 'queued' && isset($job['id'])) $jobs[] = $job;
  }
  usort($jobs, fn($a, $b) => (($a['createdAt'] ?? 0) <=> ($b['createdAt'] ?? 0)) ?: strcmp($a['id'], $b['id']));
  return array_column($jobs, 'id');
}

function runQueue() {
  ensureRuntimeDirs();
  $lock = fopen(QUEUE_LOCK, 'c');
  if ($lock === false) return 1;
  if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    return 0;
  }

  $idlePasses = 0;
  while ($idlePasses < 2) {
    $jobIds = findQueuedJobIds();
    if (empty($jobIds)) {
      $idlePasses++;
      usleep(500000);
      continue;
    }
    $idlePasses = 0;
    foreach ($jobIds as $jobId) runJob($jobId);
  }

  flock($lock, LOCK_UN);
  fclose($lock);
  return 0;
}

function runJob($jobId) {
  ensureRuntimeDirs();
  $job = readJob($jobId);
  if ($job === null) {
    error_log("Download job not found: $jobId");
    return 1;
  }
  if (($job['state'] ?? '') !== 'queued') return 0;

  updateJob($jobId, ['state' => 'running', 'message' => 'Starting download.']);
  $workDir = createWorkDir($jobId);
  if ($workDir === null) {
    failJob($jobId, null, 'Failed to create temporary work directory.');
    return 1;
  }
  $result = processDownload($jobId, $job, $workDir);
  cleanupWorkDir($workDir);
  return $result ? 0 : 1;
}

function processDownload($jobId, $job, $workDir) {
  $url = $job['url'];
  $mode = getJobMode($job);

  if (in_array($mode, ['gif', 'h264'], true)) {
    updateJob($jobId, ['state' => 'checking', 'message' => 'Checking media duration.']);
    $durations = getMediaDurations($url);
    if ($durations === null) {
      failJob($jobId, null, 'Failed to retrieve every media duration.');
      return false;
    }
    if ($mode === 'gif' && max($durations) > GIF_MAX_SECONDS) {
      failJob($jobId, null, 'At least one video is too long for GIF conversion. Maximum allowed is 10 minutes.');
      return false;
    }
    if ($mode === 'h264' && max($durations) > REENCODE_MAX_SECONDS) {
      failJob($jobId, null, 'At least one video is too long for H264 re-encoding. Maximum allowed is 2 hours.');
      return false;
    }
  }

  updateJob($jobId, ['state' => 'downloading', 'message' => $mode === 'transcribe' ? 'Downloading audio for transcription.' : 'Downloading media or playlist.']);
  $executionResult = executeCommand(buildYtDlpCommand($url, $mode, $workDir));
  if ($executionResult['exitCode'] !== 0) {
    failJob($jobId, $executionResult, 'Download failed.');
    return false;
  }

  if ($mode === 'h264') {
    updateJob($jobId, ['state' => 'encoding', 'message' => 'Re-encoding videos to H264.']);
    if (!reEncodeFiles($workDir)) {
      failJob($jobId, null, 'H264 re-encode failed.');
      return false;
    }
  }
  if ($mode === 'gif') {
    updateJob($jobId, ['state' => 'encoding', 'message' => 'Converting videos to GIF.']);
    if (!handleGifConversion($workDir)) {
      failJob($jobId, null, 'GIF conversion failed.');
      return false;
    }
  }
  if ($mode === 'transcribe') {
    updateJob($jobId, ['state' => 'transcribing', 'message' => 'Creating written transcript.']);
    if (!transcribeAudioFiles($jobId, $workDir)) {
      failJob($jobId, null, 'Transcription failed.');
      return false;
    }
  }

  updateJob($jobId, ['state' => 'finalizing', 'message' => 'Saving completed files.']);
  $movedFiles = moveProcessedFiles($workDir, FINAL_DIR);
  if (empty($movedFiles)) {
    failJob($jobId, null, 'No completed files were produced.');
    return false;
  }
  $noun = $mode === 'transcribe' ? 'transcript' : 'download';
  $message = count($movedFiles) === 1 ? ucfirst($noun) . ' complete.' : count($movedFiles) . ' ' . $noun . 's complete.';
  updateJob($jobId, [
    'state' => 'complete',
    'message' => $message,
    'outputCount' => count($movedFiles),
    'completedAt' => time()
  ]);
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
  return strlen($text) <= $limit ? $text : substr($text, -$limit);
}

function createWorkDir($jobId) {
  $workDir = WORK_DIR . DIRECTORY_SEPARATOR . $jobId;
  return mkdir($workDir, 0775, true) ? $workDir : null;
}

function buildYtDlpCommand($url, $mode, $workDir) {
  $command = ['yt-dlp', '--yes-playlist', '--playlist-end', (string)PLAYLIST_MAX_ITEMS];
  if (in_array($mode, ['mp3', 'transcribe'], true)) {
    array_push($command, '-x', '--audio-format', 'mp3', '--audio-quality', '0');
  }
  else {
    array_push($command, '--merge-output-format', 'mp4');
  }
  array_push($command, '--no-mtime', '-o', OUTPUT_TEMPLATE, '-P', $workDir, $url);
  return $command;
}

function executeCommand($command, $timeoutSeconds = null) {
  $stdoutFile = tmpfile();
  $stderrFile = tmpfile();
  if ($stdoutFile === false || $stderrFile === false) {
    if (is_resource($stdoutFile)) fclose($stdoutFile);
    if (is_resource($stderrFile)) fclose($stderrFile);
    return ['exitCode' => 1, 'stderr' => 'Failed to create process output files.'];
  }

  // File-backed output prevents a verbose downloader from filling one pipe while
  // PHP is blocked reading the other one.
  $process = @proc_open($command, [['pipe', 'r'], $stdoutFile, $stderrFile], $pipes);
  if ($process === false) {
    fclose($stdoutFile);
    fclose($stderrFile);
    return ['exitCode' => 1, 'stderr' => 'Failed to open process.'];
  }
  fclose($pipes[0]);
  $timedOut = false;
  $observedExitCode = null;
  if ($timeoutSeconds === null) {
    $exitCode = proc_close($process);
  }
  else {
    $deadline = microtime(true) + max(1, (int)$timeoutSeconds);
    while (true) {
      $status = proc_get_status($process);
      if (!$status['running']) {
        $observedExitCode = $status['exitcode'];
        break;
      }
      if (microtime(true) >= $deadline) {
        $timedOut = true;
        proc_terminate($process);
        usleep(250000);
        $status = proc_get_status($process);
        if ($status['running']) proc_terminate($process, 9);
        break;
      }
      usleep(100000);
    }
    $closedExitCode = proc_close($process);
    $exitCode = $timedOut ? 124 : ($observedExitCode !== null && $observedExitCode >= 0 ? $observedExitCode : $closedExitCode);
  }
  rewind($stdoutFile);
  rewind($stderrFile);
  $stdout = stream_get_contents($stdoutFile);
  $stderr = stream_get_contents($stderrFile);
  fclose($stdoutFile);
  fclose($stderrFile);
  return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr, 'timedOut' => $timedOut];
}

function getVideoFiles($directory) {
  return glob($directory . '/*.{mp4,mov,mkv,avi,flv,webm}', GLOB_BRACE) ?: [];
}

function convertToGif($inputFile, $outputFile) {
  $result = executeCommand(['ffmpeg', '-y', '-i', $inputFile, '-vf', 'fps=10,scale=320:-1:flags=lanczos', '-c:v', 'gif', $outputFile]);
  return $result['exitCode'] === 0;
}

function handleGifConversion($directory) {
  $videoFiles = getVideoFiles($directory);
  if (empty($videoFiles)) return false;
  foreach ($videoFiles as $inputFile) {
    $outputFile = $directory . '/' . pathinfo($inputFile, PATHINFO_FILENAME) . '.gif';
    if (!convertToGif($inputFile, $outputFile)) return false;
    unlink($inputFile);
  }
  return true;
}

function reEncodeFiles($directory) {
  $files = getVideoFiles($directory);
  if (empty($files)) return false;
  foreach ($files as $file) {
    $codec = getVideoCodec($file);
    if ($codec !== 'h264' && !reEncodeFile($file, $directory)) return false;
  }
  return true;
}

function getVideoCodec($file) {
  $result = executeCommand(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=codec_name', '-of', 'csv=p=0', $file]);
  $output = array_values(array_filter(explode("\n", trim($result['stdout'] ?? ''))));
  if ($result['exitCode'] === 0 && isset($output[0])) return trim($output[0]);
  return null;
}

function reEncodeFile($file, $directory) {
  $outputFile = $directory . '/' . pathinfo($file, PATHINFO_FILENAME) . '_h264.mp4';
  $result = executeCommand(['ffmpeg', '-y', '-i', $file, '-c:v', 'libx264', '-c:a', 'aac', $outputFile]);
  if ($result['exitCode'] === 0) {
    unlink($file);
    return true;
  }
  return false;
}

function getAudioFiles($directory) {
  return glob($directory . '/*.{mp3,m4a,wav,webm}', GLOB_BRACE) ?: [];
}

function transcriberPathFor($localPath) {
  $normalizedTemp = str_replace('\\', '/', rtrim(TEMP_DIR, '/\\'));
  $normalizedPath = str_replace('\\', '/', $localPath);
  if (!str_starts_with($normalizedPath, $normalizedTemp . '/')) return null;
  return TRANSCRIBER_SHARED_TEMP_DIR . substr($normalizedPath, strlen($normalizedTemp));
}

function transcribeAudioFiles($jobId, $directory) {
  $audioFiles = getAudioFiles($directory);
  if (empty($audioFiles)) return false;
  foreach ($audioFiles as $index => $audioFile) {
    updateJob($jobId, ['message' => 'Creating transcript ' . ($index + 1) . ' of ' . count($audioFiles) . '.']);
    $outputFile = $directory . '/' . pathinfo($audioFile, PATHINFO_FILENAME) . '.txt';
    $inputPath = transcriberPathFor($audioFile);
    $outputPath = transcriberPathFor($outputFile);
    if ($inputPath === null || $outputPath === null) return false;
    $payload = json_encode(['input_path' => $inputPath, 'output_path' => $outputPath]);
    $result = executeCommand([
      'curl', '--fail-with-body', '--silent', '--show-error', '--max-time', (string)TRANSCRIPTION_TIMEOUT,
      '--header', 'Content-Type: application/json', '--data', $payload, TRANSCRIBER_URL . '/transcribe'
    ]);
    if ($result['exitCode'] !== 0 || !is_file($outputFile)) {
      error_log('Transcriber request failed: ' . tailText($result['stderr'] ?? $result['stdout'] ?? ''));
      return false;
    }
    unlink($audioFile);
  }
  return true;
}

function uniqueTargetPath($targetDir, $fileName) {
  $path = $targetDir . DIRECTORY_SEPARATOR . $fileName;
  if (!file_exists($path)) return $path;
  $extension = pathinfo($fileName, PATHINFO_EXTENSION);
  $baseName = pathinfo($fileName, PATHINFO_FILENAME);
  for ($suffix = 2; $suffix < 1000; $suffix++) {
    $candidateName = $baseName . ' (' . $suffix . ')' . ($extension === '' ? '' : '.' . $extension);
    $candidate = $targetDir . DIRECTORY_SEPARATOR . $candidateName;
    if (!file_exists($candidate)) return $candidate;
  }
  return null;
}

function moveProcessedFiles($sourceDir, $targetDir) {
  $moved = [];
  foreach (array_diff(scandir($sourceDir), ['.', '..']) as $file) {
    $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . $file;
    if (!is_file($sourcePath)) continue;
    $targetPath = uniqueTargetPath($targetDir, $file);
    if ($targetPath !== null && rename($sourcePath, $targetPath)) $moved[] = basename($targetPath);
  }
  return $moved;
}

function cleanupWorkDir($directory) {
  if (!is_dir($directory)) return;
  foreach (array_diff(scandir($directory), ['.', '..']) as $file) {
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

if (defined('DOWNLOADER_LIBRARY_ONLY')) return;

if (PHP_SAPI === 'cli') {
  $command = $argv[1] ?? '';
  if ($command === 'run-queue') exit(runQueue());
  if ($command === 'run-job' && isset($argv[2])) exit(runJob($argv[2]));
  fwrite(STDERR, "Unsupported CLI command.\n");
  exit(1);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['file'])) { echo deleteFile($_POST['file']) ? 'true' : 'false'; }
  elseif (isset($_POST['validateUrl'])) {
    $validation = validateMediaUrl($_POST['validateUrl']);
    sendJsonResponse($validation['valid'] ? 0 : 2, $validation['message'], [
      'valid' => $validation['valid'],
      'extractor' => $validation['extractor'] ?? null
    ]);
  }
  elseif (isset($_POST['queue'])) { enqueueDownloads($_POST['queue']); }
  elseif (isset($_POST['download'])) { enqueueDownloads(legacyQueueItem()); }
  else { sendJsonResponse(2, 'Invalid Request'); }
}
elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['refresh']) && $_GET['refresh'] === 'downloads') { listDownloads(); }
elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['jobs'])) { sendJobsStatus($_GET['jobs']); }
elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['job'])) { sendJobStatus($_GET['job']); }
else { sendJsonResponse(2, 'Unsupported Request Method.'); }

?>
