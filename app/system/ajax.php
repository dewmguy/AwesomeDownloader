<?php // ajax.php

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

define('DATA_DIR', rtrim(getenv('DOWNLOADER_DATA_DIR') ?: '/data', '/'));
define('TEMP_DIR', rtrim(getenv('DOWNLOADER_TEMP_DIR') ?: DATA_DIR . '/temp', '/'));
define('FINAL_DIR', rtrim(getenv('DOWNLOADER_FINAL_DIR') ?: DATA_DIR . '/downloads', '/'));
define('DATABASE_PATH', getenv('DOWNLOADER_DATABASE_PATH') ?: DATA_DIR . '/app.sqlite');
define('WORK_DIR', TEMP_DIR . '/work');
define('LOCK_DIR', DATA_DIR . '/locks');
define('QUEUE_LOCK', LOCK_DIR . '/queue.lock');
define('URL_VALIDATION_LOCK_DIR', LOCK_DIR . '/url-validation');
define('CLEANUP_LOCK', LOCK_DIR . '/cleanup.lock');
define('GIF_MAX_SECONDS', (int)(getenv('DOWNLOADER_GIF_MAX_SECONDS') ?: 600));
define('REENCODE_MAX_SECONDS', (int)(getenv('DOWNLOADER_REENCODE_MAX_SECONDS') ?: 7200));
define('MAX_BATCH_ITEMS', (int)(getenv('DOWNLOADER_MAX_BATCH_ITEMS') ?: 20));
define('PLAYLIST_MAX_ITEMS', (int)(getenv('DOWNLOADER_PLAYLIST_MAX_ITEMS') ?: 50));
define('URL_MAX_LENGTH', 2048);
define('URL_VALIDATION_TIMEOUT', max(5, (int)(getenv('DOWNLOADER_URL_VALIDATION_TIMEOUT') ?: 25)));
define('URL_VALIDATION_CACHE_SECONDS', max(30, (int)(getenv('DOWNLOADER_URL_VALIDATION_CACHE_SECONDS') ?: 600)));
define('URL_VALIDATION_CONCURRENCY', max(1, (int)(getenv('DOWNLOADER_URL_VALIDATION_CONCURRENCY') ?: 3)));
define('URL_VALIDATION_LOCK_PARTITIONS', 256);
define('URL_VALIDATION_CACHE_VERSION', 3);
define('TRANSCRIBER_URL', rtrim(getenv('DOWNLOADER_TRANSCRIBER_URL') ?: 'http://127.0.0.1:8000', '/'));
define('TRANSCRIBER_SHARED_TEMP_DIR', rtrim(getenv('DOWNLOADER_TRANSCRIBER_SHARED_TEMP_DIR') ?: TEMP_DIR, '/'));
define('TRANSCRIPTION_TIMEOUT', (int)(getenv('DOWNLOADER_TRANSCRIPTION_TIMEOUT') ?: 14400));
define('CONTENT_RETENTION_SECONDS', 72 * 60 * 60);
define('VISITOR_COOKIE_NAME', 'awesome_downloader_visitor');
define('SUPPORTED_EXTENSIONS', ['mp3', 'mp4', 'gif', 'webm', 'txt']);
define('ENCODE_EXTENSIONS', ['mp4', 'mov', 'mkv', 'avi', 'flv', 'webm']);
define('VALID_MODES', ['base', 'h264', 'gif', 'mp3', 'transcribe']);
define('OUTPUT_TEMPLATE', getenv('DOWNLOADER_OUTPUT_TEMPLATE') ?: '%(playlist_index|)s%(playlist_index& - |)s%(title).180B [%(id)s].%(ext)s');

function base64UrlEncode($bytes) {
  return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function isValidVisitorToken($token) {
  return is_string($token) && preg_match('/^[A-Za-z0-9_-]{43}$/', $token);
}

function visitorIdForToken($token) {
  return hash('sha256', $token);
}

function requestIsSecure() {
  if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
  $forwardedProtocol = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
  return $forwardedProtocol === 'https';
}

function setVisitorCookieUntil($token, $expiresAt) {
  if (PHP_SAPI === 'cli' || headers_sent()) return;
  setcookie(VISITOR_COOKIE_NAME, $token, [
    'expires' => min((int)$expiresAt, time() + CONTENT_RETENTION_SECONDS),
    'path' => '/',
    'secure' => requestIsSecure(),
    'httponly' => true,
    'samesite' => 'Lax'
  ]);
}

function getVisitorToken($refreshCookie = false) {
  $token = $_COOKIE[VISITOR_COOKIE_NAME] ?? null;
  $isNew = !isValidVisitorToken($token);
  if ($isNew) $token = base64UrlEncode(random_bytes(32));
  if ($isNew || $refreshCookie) setVisitorCookieUntil($token, time() + CONTENT_RETENTION_SECONDS);
  return $token;
}

function getCurrentVisitorId($refreshCookie = false) {
  return visitorIdForToken(getVisitorToken($refreshCookie));
}

function isValidRecordId($id) {
  return is_string($id) && (bool)preg_match('/^[a-f0-9]{24}$/', $id);
}

function database() {
  static $connection = null;
  if ($connection instanceof PDO) return $connection;

  $databaseDirectory = dirname(DATABASE_PATH);
  if (!is_dir($databaseDirectory) && !@mkdir($databaseDirectory, 0775, true) && !is_dir($databaseDirectory)) {
    throw new RuntimeException('The database directory could not be created.');
  }

  $connection = new PDO('sqlite:' . DATABASE_PATH, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false
  ]);
  $connection->exec('PRAGMA busy_timeout = 5000');
  $connection->exec('PRAGMA journal_mode = WAL');
  $connection->exec('PRAGMA synchronous = NORMAL');
  $connection->exec('PRAGMA foreign_keys = ON');
  $connection->exec(
    'CREATE TABLE IF NOT EXISTS jobs (
      id TEXT PRIMARY KEY,
      owner_id TEXT NOT NULL,
      state TEXT NOT NULL,
      created_at REAL NOT NULL,
      updated_at INTEGER NOT NULL,
      record_json TEXT NOT NULL
    );
    CREATE INDEX IF NOT EXISTS jobs_state_created_idx ON jobs (state, created_at, id);
    CREATE INDEX IF NOT EXISTS jobs_updated_idx ON jobs (updated_at);
    CREATE TABLE IF NOT EXISTS files (
      id TEXT PRIMARY KEY,
      owner_id TEXT,
      is_public INTEGER NOT NULL DEFAULT 0,
      created_at INTEGER NOT NULL,
      published_at INTEGER,
      expires_at INTEGER NOT NULL,
      storage_name TEXT NOT NULL UNIQUE,
      record_json TEXT NOT NULL
    );
    CREATE INDEX IF NOT EXISTS files_expires_idx ON files (expires_at);
    CREATE INDEX IF NOT EXISTS files_visibility_idx ON files (is_public, owner_id, expires_at);
    CREATE TABLE IF NOT EXISTS validation_cache (
      cache_key TEXT PRIMARY KEY,
      created_at INTEGER NOT NULL,
      result_json TEXT NOT NULL
    );
    CREATE INDEX IF NOT EXISTS validation_cache_created_idx ON validation_cache (created_at);'
  );
  $connection->exec('PRAGMA user_version = 1');
  return $connection;
}

function readFileMetadata($fileId) {
  if (!isValidRecordId($fileId)) return null;
  $statement = database()->prepare('SELECT record_json FROM files WHERE id = :id');
  $statement->execute([':id' => $fileId]);
  $record = json_decode((string)$statement->fetchColumn(), true);
  return is_array($record) && ($record['id'] ?? null) === $fileId ? $record : null;
}

function writeFileMetadata($record) {
  if (!isValidRecordId($record['id'] ?? null)) return false;
  $storageName = basename((string)($record['storageName'] ?? ''));
  if (!preg_match('/^[a-f0-9]{24}\.[a-z0-9]{2,5}$/', $storageName)) return false;
  $json = json_encode($record, JSON_UNESCAPED_SLASHES);
  if ($json === false) return false;
  $statement = database()->prepare(
    'INSERT INTO files (id, owner_id, is_public, created_at, published_at, expires_at, storage_name, record_json)
     VALUES (:id, :owner_id, :is_public, :created_at, :published_at, :expires_at, :storage_name, :record_json)
     ON CONFLICT(id) DO UPDATE SET
       owner_id = excluded.owner_id,
       is_public = excluded.is_public,
       created_at = excluded.created_at,
       published_at = excluded.published_at,
       expires_at = excluded.expires_at,
       storage_name = excluded.storage_name,
       record_json = excluded.record_json'
  );
  return $statement->execute([
    ':id' => $record['id'],
    ':owner_id' => $record['ownerId'] ?? null,
    ':is_public' => empty($record['public']) ? 0 : 1,
    ':created_at' => (int)($record['createdAt'] ?? time()),
    ':published_at' => isset($record['publishedAt']) ? (int)$record['publishedAt'] : null,
    ':expires_at' => (int)($record['expiresAt'] ?? 0),
    ':storage_name' => $storageName,
    ':record_json' => $json
  ]);
}

function getRecordFilePath($record) {
  $storageName = basename((string)($record['storageName'] ?? ''));
  if (!preg_match('/^[a-f0-9]{24}\.[a-z0-9]{2,5}$/', $storageName)) return null;
  return FINAL_DIR . DIRECTORY_SEPARATOR . $storageName;
}

function listFileMetadata() {
  $records = [];
  $statement = database()->query('SELECT record_json FROM files ORDER BY created_at DESC, id');
  foreach ($statement as $row) {
    $record = json_decode((string)$row['record_json'], true);
    if (is_array($record) && isset($record['id'])) $records[] = $record;
  }
  return $records;
}

function getVisibleDownloads($ownerId, $now = null) {
  $now = $now ?? time();
  $private = [];
  $public = [];
  foreach (listFileMetadata() as $record) {
    $filePath = getRecordFilePath($record);
    if ($filePath === null || !is_file($filePath) || (int)($record['expiresAt'] ?? 0) <= $now) continue;
    if (!empty($record['public'])) $public[] = $record;
    elseif (hash_equals((string)($record['ownerId'] ?? ''), (string)$ownerId)) $private[] = $record;
  }
  $newestFirst = fn($a, $b) => ((int)($b['publishedAt'] ?? $b['createdAt'] ?? 0)) <=> ((int)($a['publishedAt'] ?? $a['createdAt'] ?? 0));
  usort($private, $newestFirst);
  usort($public, $newestFirst);
  return ['private' => $private, 'public' => $public];
}

function listDownloads() {
  ensureRuntimeDirs();
  purgeExpiredContent();
  $token = getVisitorToken();
  $ownerId = visitorIdForToken($token);
  $downloads = getVisibleDownloads($ownerId);
  if (!empty($downloads['private'])) {
    $latestExpiry = max(array_map(fn($record) => (int)$record['expiresAt'], $downloads['private']));
    setVisitorCookieUntil($token, $latestExpiry);
  }
  sendJsonResponse(0, 'Downloads loaded.', [
    'privateHtml' => empty($downloads['private'])
      ? '<li class="empty-downloads">No private downloads yet.</li>'
      : implode('', array_map(fn($record) => formatFileListItem($record, true), $downloads['private'])),
    'publicHtml' => empty($downloads['public'])
      ? '<li class="empty-downloads">No public downloads right now.</li>'
      : implode('', array_map(fn($record) => formatFileListItem($record, false), $downloads['public'])),
    'privateCount' => count($downloads['private']),
    'publicCount' => count($downloads['public'])
  ]);
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

function formatFileListItem($record, $canManage = false) {
  $fileId = htmlspecialchars((string)$record['id'], ENT_QUOTES);
  $fileName = htmlspecialchars((string)$record['name'], ENT_QUOTES);
  $previewClass = strtolower(pathinfo((string)$record['name'], PATHINFO_EXTENSION)) === 'txt' ? ' transcript' : '';
  $actions = sprintf(
    '<a class="file-action save-file" download href="/system/ajax.php?media=%1$s&amp;download=1" aria-label="Save %2$s" title="Save to your device"><i class="fa-solid fa-download" aria-hidden="true"></i><span>Save</span></a>',
    $fileId,
    $fileName
  );
  if ($canManage) {
    $actions .= sprintf(
      '<button type="button" class="file-action publish" data-file-id="%1$s" aria-label="Make %2$s public" title="Make public for 72 hours"><i class="fa-solid fa-earth-americas" aria-hidden="true"></i><span>Public</span></button>' .
      '<button type="button" class="file-action delete" data-file-id="%1$s" aria-label="Delete %2$s" title="Delete"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>',
      $fileId,
      $fileName
    );
  }
  return sprintf(
    '<li data-file-id="%1$s"><button type="button" class="link play%3$s" data-file-id="%1$s" data-file-name="%2$s">%2$s</button><span class="file-actions">%4$s</span></li>',
    $fileId,
    $fileName,
    $previewClass,
    $actions
  );
}

function deleteOwnedFile($fileId, $ownerId) {
  $record = readFileMetadata($fileId);
  if ($record === null || !empty($record['public']) || !hash_equals((string)($record['ownerId'] ?? ''), (string)$ownerId)) return false;
  $filePath = getRecordFilePath($record);
  if ($filePath !== null && is_file($filePath) && !unlink($filePath)) return false;
  $statement = database()->prepare('DELETE FROM files WHERE id = :id');
  return $statement->execute([':id' => $fileId]) && $statement->rowCount() === 1;
}

function publishOwnedFile($fileId, $ownerId, $now = null) {
  $now = $now ?? time();
  $record = readFileMetadata($fileId);
  if ($record === null || !empty($record['public']) || !hash_equals((string)($record['ownerId'] ?? ''), (string)$ownerId)) return false;
  $filePath = getRecordFilePath($record);
  if ($filePath === null || !is_file($filePath)) return false;
  $record['public'] = true;
  $record['ownerId'] = null;
  $record['publishedAt'] = $now;
  $record['expiresAt'] = $now + CONTENT_RETENTION_SECONDS;
  return writeFileMetadata($record);
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

  $extractor = trim((string)($metadata['extractor_key'] ?? $metadata['extractor'] ?? 'media'));
  if (!preg_match('/^[a-z0-9 _.-]{1,80}$/i', $extractor)) $extractor = 'media';
  return ['extractor' => $extractor];
}

function formatExtractorName($extractor) {
  $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $extractor));
  if (str_starts_with($normalized, 'youtube')) return 'YouTube';
  if (in_array($normalized, ['generic', 'media'], true)) return 'this media service';
  return $extractor;
}

function queueUrlIdentity($url) {
  $url = trim((string)$url);
  $parts = parse_url($url);
  if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) return $url;

  $scheme = strtolower($parts['scheme']);
  $host = strtolower($parts['host']);
  $port = isset($parts['port']) && !(($scheme === 'http' && $parts['port'] === 80) || ($scheme === 'https' && $parts['port'] === 443))
    ? ':' . $parts['port']
    : '';
  $path = $parts['path'] ?? '/';
  if ($path === '') $path = '/';
  $query = isset($parts['query']) ? '?' . $parts['query'] : '';
  $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
  return $scheme . '://' . $host . $port . $path . $query . $fragment;
}

function readUrlValidationCache($url) {
  $statement = database()->prepare(
    'SELECT result_json FROM validation_cache WHERE cache_key = :cache_key AND created_at >= :cutoff'
  );
  $statement->execute([
    ':cache_key' => hash('sha256', $url),
    ':cutoff' => time() - URL_VALIDATION_CACHE_SECONDS
  ]);
  $result = json_decode((string)$statement->fetchColumn(), true);
  if (!is_array($result) || ($result['cacheVersion'] ?? 0) !== URL_VALIDATION_CACHE_VERSION || empty($result['valid']) || !isset($result['message'])) return null;
  return $result;
}

function writeUrlValidationCache($url, $result) {
  if (empty($result['valid'])) return;
  $result['cacheVersion'] = URL_VALIDATION_CACHE_VERSION;
  $json = json_encode($result, JSON_UNESCAPED_SLASHES);
  if ($json === false) return;
  $statement = database()->prepare(
    'INSERT INTO validation_cache (cache_key, created_at, result_json)
     VALUES (:cache_key, :created_at, :result_json)
     ON CONFLICT(cache_key) DO UPDATE SET created_at = excluded.created_at, result_json = excluded.result_json'
  );
  $statement->execute([
    ':cache_key' => hash('sha256', $url),
    ':created_at' => time(),
    ':result_json' => $json
  ]);
}

function acquireValidationLock($path) {
  $handle = @fopen($path, 'c');
  if ($handle !== false && flock($handle, LOCK_EX | LOCK_NB)) return $handle;
  if (is_resource($handle)) fclose($handle);
  return null;
}

function releaseValidationLock($handle) {
  if (!is_resource($handle)) return;
  flock($handle, LOCK_UN);
  fclose($handle);
}

function acquireUrlValidationLock($url) {
  $partition = hexdec(substr(hash('sha256', $url), 0, 8)) % URL_VALIDATION_LOCK_PARTITIONS;
  return acquireValidationLock(URL_VALIDATION_LOCK_DIR . DIRECTORY_SEPARATOR . 'url-' . $partition . '.lock');
}

function acquireUrlValidationSlot() {
  for ($slot = 0; $slot < URL_VALIDATION_CONCURRENCY; $slot++) {
    $handle = acquireValidationLock(URL_VALIDATION_LOCK_DIR . DIRECTORY_SEPARATOR . 'slot-' . $slot . '.lock');
    if ($handle !== null) return $handle;
  }
  return null;
}

function validationFailureResult($execution) {
  if (!empty($execution['timedOut'])) {
    return invalidUrlResult('The media site took too long to validate. Try again.');
  }

  $error = strtolower((string)($execution['stderr'] ?? ''));
  foreach (['private video', 'login required', 'authentication is required', 'sign in to confirm', 'age-restricted'] as $privateMarker) {
    if (str_contains($error, $privateMarker)) {
      return invalidUrlResult('This media is not publicly accessible without signing in.');
    }
  }
  foreach (['http error 403', 'forbidden', 'http error 429', 'too many requests', 'rate limit', 'unable to connect', 'proxyerror', 'proxy error', 'timed out', 'timeout', 'connection reset', 'remote end closed', 'network is unreachable', 'temporary failure'] as $transientMarker) {
    if (str_contains($error, $transientMarker)) {
      return invalidUrlResult('The media site could not be reached reliably. Try again in a moment.');
    }
  }
  if (str_contains($error, 'unsupported url')) {
    return invalidUrlResult('This link does not contain media this downloader can process.');
  }
  return invalidUrlResult('This link could not be validated right now. Try again.');
}

function sanitizedValidationError($error) {
  $sanitized = preg_replace('~https?://\S+~i', '[url]', (string)$error);
  $sanitized = preg_replace('/\s+/', ' ', trim((string)$sanitized));
  return $sanitized === '' ? 'no extractor detail' : tailText($sanitized, 1000);
}

function logValidationFailure($url, $execution, $elapsedSeconds) {
  $host = (string)(parse_url($url, PHP_URL_HOST) ?: 'unknown');
  $exitCode = (int)($execution['exitCode'] ?? 1);
  $timedOut = !empty($execution['timedOut']) ? 'yes' : 'no';
  $durationMs = max(0, (int)round($elapsedSeconds * 1000));
  $detail = sanitizedValidationError($execution['stderr'] ?? '');
  error_log("Media URL validation failed host=$host exit=$exitCode timedOut=$timedOut durationMs=$durationMs detail=$detail");
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

  $urlLock = acquireUrlValidationLock($url);
  if ($urlLock === null) {
    return invalidUrlResult('This URL is already being checked. Try again in a moment.');
  }

  if ($useCache) {
    $cached = readUrlValidationCache($url);
    if ($cached !== null) {
      releaseValidationLock($urlLock);
      return $cached;
    }
  }

  $slotLock = acquireUrlValidationSlot();
  if ($slotLock === null) {
    releaseValidationLock($urlLock);
    return invalidUrlResult('The URL checker is busy. Try again in a moment.');
  }

  try {
    $command = buildYtDlpValidationCommand($url);
    $startedAt = microtime(true);
    $execution = $runner === null
      ? executeCommand($command, URL_VALIDATION_TIMEOUT)
      : $runner($command, URL_VALIDATION_TIMEOUT);
    $metadata = ($execution['exitCode'] ?? 1) === 0
      ? parseYtDlpValidationOutput($execution['stdout'] ?? '')
      : null;

    if ($metadata === null) {
      if ($runner === null) logValidationFailure($url, $execution, microtime(true) - $startedAt);
      $result = validationFailureResult($execution);
    }
    else {
      $extractorName = formatExtractorName($metadata['extractor']);
      $result = [
        'valid' => true,
        'message' => $extractorName === 'this media service' ? 'Verified media link.' : 'Verified ' . $extractorName . ' link.',
        'extractor' => $metadata['extractor']
      ];
    }

    if ($useCache) writeUrlValidationCache($url, $result);
    return $result;
  }
  finally {
    releaseValidationLock($slotLock);
    releaseValidationLock($urlLock);
  }
}

function normalizeQueueItems($rawItems) {
  if (is_string($rawItems)) $rawItems = json_decode($rawItems, true);
  if (!is_array($rawItems)) return ['items' => [], 'errors' => ['The queue payload is invalid.']];
  if (count($rawItems) < 1) return ['items' => [], 'errors' => ['Add at least one URL.']];
  if (count($rawItems) > MAX_BATCH_ITEMS) return ['items' => [], 'errors' => ['A batch can contain at most ' . MAX_BATCH_ITEMS . ' URLs.']];

  $items = [];
  $errors = [];
  $queuedOutputs = [];
  foreach ($rawItems as $index => $rawItem) {
    $number = $index + 1;
    $url = trim((string)($rawItem['url'] ?? ''));
    $mode = strtolower(trim((string)($rawItem['mode'] ?? 'base')));
    $urlValidation = validateUrlStructure($url);
    if (!$urlValidation['valid']) $errors[] = "URL $number: " . $urlValidation['message'];
    if (!in_array($mode, VALID_MODES, true)) $errors[] = "URL $number has an invalid download choice.";
    if ($urlValidation['valid'] && in_array($mode, VALID_MODES, true)) {
      $outputKey = queueUrlIdentity($url) . "\n" . $mode;
      if (isset($queuedOutputs[$outputKey])) {
        $errors[] = "URL $number duplicates URL " . $queuedOutputs[$outputKey] . ' with the same output choice.';
      }
      else $queuedOutputs[$outputKey] = $number;
    }
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

function enqueueDownloads($rawItems, $ownerId = null) {
  ensureRuntimeDirs();
  $ownerId = $ownerId ?? getCurrentVisitorId(true);
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
      'ownerId' => $ownerId,
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
  foreach ([DATA_DIR, TEMP_DIR, FINAL_DIR, WORK_DIR, LOCK_DIR, URL_VALIDATION_LOCK_DIR] as $dir) {
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) $ready = false;
  }
  if ($ready) {
    try {
      database();
    }
    catch (Throwable $error) {
      error_log('Runtime database initialization failed: ' . $error->getMessage());
      $ready = false;
    }
  }
  return $ready;
}

function readJob($jobId) {
  if (!isValidRecordId($jobId)) return null;
  $statement = database()->prepare('SELECT record_json FROM jobs WHERE id = :id');
  $statement->execute([':id' => $jobId]);
  $job = json_decode((string)$statement->fetchColumn(), true);
  return is_array($job) ? $job : null;
}

function writeJob($jobId, $job) {
  if (!isValidRecordId($jobId) || ($job['id'] ?? null) !== $jobId) return false;
  $job['updatedAt'] = time();
  $json = json_encode($job, JSON_UNESCAPED_SLASHES);
  if ($json === false) return false;
  $statement = database()->prepare(
    'INSERT INTO jobs (id, owner_id, state, created_at, updated_at, record_json)
     VALUES (:id, :owner_id, :state, :created_at, :updated_at, :record_json)
     ON CONFLICT(id) DO UPDATE SET
       owner_id = excluded.owner_id,
       state = excluded.state,
       created_at = excluded.created_at,
       updated_at = excluded.updated_at,
       record_json = excluded.record_json'
  );
  return $statement->execute([
    ':id' => $jobId,
    ':owner_id' => (string)($job['ownerId'] ?? ''),
    ':state' => (string)($job['state'] ?? 'unknown'),
    ':created_at' => (float)($job['createdAt'] ?? microtime(true)),
    ':updated_at' => (int)$job['updatedAt'],
    ':record_json' => $json
  ]);
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

function jobBelongsToOwner($job, $ownerId) {
  return isset($job['ownerId']) && hash_equals((string)$job['ownerId'], (string)$ownerId);
}

function sendJobStatus($jobId, $ownerId) {
  $job = readJob($jobId);
  if ($job === null || !jobBelongsToOwner($job, $ownerId)) {
    sendJsonResponse(2, 'Job not found.');
    return;
  }
  sendJsonResponse(0, $job['message'] ?? 'Job status loaded.', ['job' => formatJobForClient($job)]);
}

function sendJobsStatus($jobIds, $ownerId) {
  $ids = array_values(array_unique(array_filter(explode(',', $jobIds), fn($id) => preg_match('/^[a-f0-9]{24}$/', $id))));
  if (empty($ids) || count($ids) > MAX_BATCH_ITEMS * 5) {
    sendJsonResponse(2, 'The job list is invalid.');
    return;
  }
  $jobs = [];
  foreach ($ids as $id) {
    $job = readJob($id);
    if ($job !== null && jobBelongsToOwner($job, $ownerId)) $jobs[] = formatJobForClient($job);
  }
  sendJsonResponse(0, 'Queue status loaded.', ['jobs' => $jobs]);
}

function launchQueueWorker() {
  $command = sprintf('php %s run-queue > /dev/null 2>&1 &', escapeshellarg(__FILE__));
  exec($command);
}

function findQueuedJobIds() {
  $statement = database()->query("SELECT id FROM jobs WHERE state = 'queued' ORDER BY created_at, id");
  return $statement->fetchAll(PDO::FETCH_COLUMN);
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

  updateJob($jobId, ['state' => 'running', 'message' => 'Starting processing.']);
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
  $ownerId = (string)($job['ownerId'] ?? '');
  if (!preg_match('/^[a-f0-9]{64}$/', $ownerId)) {
    failJob($jobId, null, 'This job has no valid owner.');
    return false;
  }

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

  updateJob($jobId, ['state' => 'downloading', 'message' => $mode === 'transcribe' ? 'Retrieving audio for transcription.' : 'Retrieving media or playlist.']);
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
  $movedFiles = moveProcessedFiles($workDir, FINAL_DIR, $ownerId);
  if (empty($movedFiles)) {
    failJob($jobId, null, 'No completed files were produced.');
    return false;
  }
  $message = count($movedFiles) === 1 ? 'One file is ready to save.' : count($movedFiles) . ' files are ready to save.';
  updateJob($jobId, [
    'state' => 'complete',
    'message' => $message,
    'outputCount' => count($movedFiles),
    'outputIds' => array_column($movedFiles, 'id'),
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

function moveProcessedFiles($sourceDir, $targetDir, $ownerId, $now = null) {
  $now = $now ?? time();
  $moved = [];
  foreach (array_diff(scandir($sourceDir), ['.', '..']) as $file) {
    $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . $file;
    if (!is_file($sourcePath)) continue;
    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!in_array($extension, SUPPORTED_EXTENSIONS, true)) continue;
    $fileId = bin2hex(random_bytes(12));
    $storageName = $fileId . '.' . $extension;
    $targetPath = $targetDir . DIRECTORY_SEPARATOR . $storageName;
    $record = [
      'id' => $fileId,
      'storageName' => $storageName,
      'name' => basename($file),
      'ownerId' => $ownerId,
      'public' => false,
      'createdAt' => $now,
      'expiresAt' => $now + CONTENT_RETENTION_SECONDS
    ];
    if (!rename($sourcePath, $targetPath)) continue;
    if (!writeFileMetadata($record)) {
      @rename($targetPath, $sourcePath);
      continue;
    }
    $moved[] = $record;
  }
  return $moved;
}

function getAccessibleFileRecord($fileId, $ownerId, $now = null) {
  $now = $now ?? time();
  $record = readFileMetadata($fileId);
  if ($record === null || (int)($record['expiresAt'] ?? 0) <= $now) return null;
  $isOwner = empty($record['public']) && hash_equals((string)($record['ownerId'] ?? ''), (string)$ownerId);
  if (empty($record['public']) && !$isOwner) return null;
  $filePath = getRecordFilePath($record);
  if ($filePath === null || !is_file($filePath)) return null;
  $record['filePath'] = $filePath;
  return $record;
}

function contentTypeForFile($fileName) {
  return match (strtolower(pathinfo($fileName, PATHINFO_EXTENSION))) {
    'mp3' => 'audio/mpeg',
    'mp4' => 'video/mp4',
    'gif' => 'image/gif',
    'webm' => 'video/webm',
    'txt' => 'text/plain; charset=utf-8',
    default => 'application/octet-stream'
  };
}

function contentDispositionHeader($fileName, $forceDownload) {
  $safeAsciiName = preg_replace('/[^A-Za-z0-9._ -]/', '_', basename($fileName));
  $safeAsciiName = trim((string)$safeAsciiName, '. ');
  if ($safeAsciiName === '') $safeAsciiName = 'media-file';
  $disposition = $forceDownload ? 'attachment' : 'inline';
  return $disposition . '; filename="' . addcslashes($safeAsciiName, '"\\') . '"; filename*=UTF-8\'\'' . rawurlencode(basename($fileName));
}

function serveMediaFile($fileId, $ownerId, $forceDownload = false) {
  purgeExpiredContent();
  $record = getAccessibleFileRecord($fileId, $ownerId);
  if ($record === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'File not found.';
    return;
  }

  $path = $record['filePath'];
  $size = filesize($path);
  if ($size === 0) {
    http_response_code(200);
    header('Content-Type: ' . contentTypeForFile($record['name']));
    header('Content-Disposition: ' . contentDispositionHeader($record['name'], $forceDownload));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: 0');
    return;
  }
  $start = 0;
  $end = max(0, $size - 1);
  $status = 200;
  $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
  if ($range !== '') {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $matches) || ($matches[1] === '' && $matches[2] === '')) {
      http_response_code(416);
      header('Content-Range: bytes */' . $size);
      return;
    }
    if ($matches[1] === '') {
      $suffixLength = min((int)$matches[2], $size);
      $start = max(0, $size - $suffixLength);
    }
    else {
      $start = (int)$matches[1];
      if ($matches[2] !== '') $end = min($end, (int)$matches[2]);
    }
    if ($start > $end || $start >= $size) {
      http_response_code(416);
      header('Content-Range: bytes */' . $size);
      return;
    }
    $status = 206;
  }

  http_response_code($status);
  header('Content-Type: ' . contentTypeForFile($record['name']));
  header('Content-Disposition: ' . contentDispositionHeader($record['name'], $forceDownload));
  header('Accept-Ranges: bytes');
  header('Cache-Control: private, no-store');
  header('X-Content-Type-Options: nosniff');
  header('Content-Length: ' . ($end - $start + 1));
  if ($status === 206) header("Content-Range: bytes $start-$end/$size");

  $handle = fopen($path, 'rb');
  if ($handle === false) return;
  fseek($handle, $start);
  $remaining = $end - $start + 1;
  while ($remaining > 0 && !feof($handle)) {
    $chunk = fread($handle, min(1024 * 1024, $remaining));
    if ($chunk === false || $chunk === '') break;
    echo $chunk;
    $remaining -= strlen($chunk);
    if (connection_aborted()) break;
  }
  fclose($handle);
}

function purgeExpiredContent($now = null) {
  $now = $now ?? time();
  ensureRuntimeDirs();
  $lock = @fopen(CLEANUP_LOCK, 'c');
  if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    if (is_resource($lock)) fclose($lock);
    return ['files' => 0, 'jobs' => 0, 'cache' => 0, 'work' => 0];
  }

  $removed = ['files' => 0, 'jobs' => 0, 'cache' => 0, 'work' => 0];
  $knownStorageNames = [];
  $fileRows = database()->query('SELECT id, record_json FROM files')->fetchAll();
  $deleteFileRecord = database()->prepare('DELETE FROM files WHERE id = :id');
  foreach ($fileRows as $fileRow) {
    $record = json_decode((string)$fileRow['record_json'], true);
    $filePath = is_array($record) ? getRecordFilePath($record) : null;
    $expired = !is_array($record) || (int)($record['expiresAt'] ?? 0) <= $now || $filePath === null;
    if (!$expired && is_file($filePath)) {
      $knownStorageNames[basename($filePath)] = true;
      continue;
    }
    if ($filePath !== null && is_file($filePath)) @unlink($filePath);
    $deleteFileRecord->execute([':id' => $fileRow['id']]);
    if ($deleteFileRecord->rowCount() === 1) $removed['files']++;
  }

  $cutoff = $now - CONTENT_RETENTION_SECONDS;
  foreach (array_diff(scandir(FINAL_DIR), ['.', '..', '.gitkeep']) as $file) {
    $path = FINAL_DIR . DIRECTORY_SEPARATOR . $file;
    if (is_file($path) && !isset($knownStorageNames[$file]) && filemtime($path) <= $cutoff && @unlink($path)) $removed['files']++;
  }
  $deleteJobs = database()->prepare('DELETE FROM jobs WHERE updated_at <= :cutoff');
  $deleteJobs->execute([':cutoff' => $cutoff]);
  $removed['jobs'] = $deleteJobs->rowCount();
  $deleteCache = database()->prepare('DELETE FROM validation_cache WHERE created_at <= :cutoff');
  $deleteCache->execute([':cutoff' => $cutoff]);
  $removed['cache'] = $deleteCache->rowCount();
  foreach (glob(WORK_DIR . '/*', GLOB_ONLYDIR) ?: [] as $path) {
    if (filemtime($path) <= $cutoff) {
      cleanupWorkDir($path);
      if (!is_dir($path)) $removed['work']++;
    }
  }

  flock($lock, LOCK_UN);
  fclose($lock);
  return $removed;
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
  if ($command === 'cleanup') {
    echo json_encode(purgeExpiredContent()) . "\n";
    exit(0);
  }
  fwrite(STDERR, "Unsupported CLI command.\n");
  exit(1);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['deleteFileId'])) {
    $deleted = deleteOwnedFile($_POST['deleteFileId'], getCurrentVisitorId());
    sendJsonResponse($deleted ? 0 : 2, $deleted ? 'File permanently deleted.' : 'That private file was not found.');
  }
  elseif (isset($_POST['publishFileId'])) {
    $published = publishOwnedFile($_POST['publishFileId'], getCurrentVisitorId());
    sendJsonResponse($published ? 0 : 2, $published ? 'File is public for the next 72 hours.' : 'That private file was not found.');
  }
  elseif (isset($_POST['validateUrl'])) {
    $validation = validateMediaUrl($_POST['validateUrl']);
    sendJsonResponse($validation['valid'] ? 0 : 2, $validation['message'], [
      'valid' => $validation['valid']
    ]);
  }
  elseif (isset($_POST['queue'])) { enqueueDownloads($_POST['queue']); }
  elseif (isset($_POST['download'])) { enqueueDownloads(legacyQueueItem()); }
  else { sendJsonResponse(2, 'Invalid Request'); }
}
elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['media'])) { serveMediaFile($_GET['media'], getCurrentVisitorId(), isset($_GET['download'])); }
elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['refresh']) && $_GET['refresh'] === 'downloads') { listDownloads(); }
elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['jobs'])) { sendJobsStatus($_GET['jobs'], getCurrentVisitorId()); }
elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['job'])) { sendJobStatus($_GET['job'], getCurrentVisitorId()); }
else { sendJsonResponse(2, 'Unsupported Request Method.'); }

?>
