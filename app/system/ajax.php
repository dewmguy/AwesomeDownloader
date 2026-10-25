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
define('URL_VALIDATION_CACHE_VERSION', 8);
define('REDDIT_REDIRECT_LIMIT', 5);
define('REDDIT_RESOLUTION_TIMEOUT', min(20, URL_VALIDATION_TIMEOUT));
define('URL_EVENT_RETENTION_SECONDS', 72 * 60 * 60);
define('URL_EVENT_RATE_LIMIT', 500);
define('APP_VERSION', getenv('APP_VERSION') ?: '2026.10.24');
define('APP_COMMIT', getenv('APP_COMMIT') ?: 'unknown');
define('APP_UPDATED_AT', getenv('APP_UPDATED_AT') ?: '2026-10-24');
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
    CREATE INDEX IF NOT EXISTS validation_cache_created_idx ON validation_cache (created_at);
    CREATE TABLE IF NOT EXISTS url_events (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      owner_id TEXT NOT NULL,
      request_id TEXT,
      created_at REAL NOT NULL,
      event_type TEXT NOT NULL,
      url TEXT,
      status TEXT,
      message TEXT,
      details_json TEXT
    );
    CREATE INDEX IF NOT EXISTS url_events_created_idx ON url_events (created_at);
    CREATE INDEX IF NOT EXISTS url_events_owner_created_idx ON url_events (owner_id, created_at);'
  );
  $connection->exec('PRAGMA user_version = 2');
  return $connection;
}

function sanitizedUrlEventText($value, $maxLength) {
  $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', trim((string)$value));
  if (!is_string($value)) return '';
  return strlen($value) > $maxLength ? substr($value, 0, $maxLength) : $value;
}

function recordUrlEvent($ownerId, $eventType, $url = null, $status = null, $message = null, $details = [], $requestId = null, $createdAt = null) {
  $ownerId = sanitizedUrlEventText($ownerId, 128);
  $eventType = strtolower(sanitizedUrlEventText($eventType, 64));
  if ($ownerId === '' || !preg_match('/^[a-z0-9_-]+$/i', $eventType)) return false;

  try {
    $createdAt = $createdAt ?? microtime(true);
    $rate = database()->prepare('SELECT count(*) FROM url_events WHERE owner_id = :owner_id AND created_at >= :cutoff');
    $rate->execute([':owner_id' => $ownerId, ':cutoff' => $createdAt - 600]);
    if ((int)$rate->fetchColumn() >= URL_EVENT_RATE_LIMIT) return false;

    $safeDetails = [];
    if (is_array($details)) {
      foreach (array_slice($details, 0, 20, true) as $key => $value) {
        $key = sanitizedUrlEventText($key, 64);
        if ($key === '' || (!is_scalar($value) && $value !== null)) continue;
        $safeDetails[$key] = is_string($value) ? sanitizedUrlEventText($value, 1000) : $value;
      }
    }
    $detailsJson = empty($safeDetails) ? null : json_encode($safeDetails, JSON_UNESCAPED_SLASHES);
    $statement = database()->prepare(
      'INSERT INTO url_events (owner_id, request_id, created_at, event_type, url, status, message, details_json)
       VALUES (:owner_id, :request_id, :created_at, :event_type, :url, :status, :message, :details_json)'
    );
    return $statement->execute([
      ':owner_id' => $ownerId,
      ':request_id' => ($requestId = sanitizedUrlEventText($requestId, 128)) === '' ? null : $requestId,
      ':created_at' => $createdAt,
      ':event_type' => $eventType,
      ':url' => ($url = sanitizedUrlEventText($url, URL_MAX_LENGTH)) === '' ? null : $url,
      ':status' => ($status = sanitizedUrlEventText($status, 64)) === '' ? null : $status,
      ':message' => ($message = sanitizedUrlEventText($message, 2000)) === '' ? null : $message,
      ':details_json' => $detailsJson === false ? null : $detailsJson
    ]);
  }
  catch (Throwable $error) {
    error_log('URL diagnostic event could not be recorded: ' . $error->getMessage());
    return false;
  }
}

function listUrlEvents($limit = 100) {
  $limit = max(1, min(1000, (int)$limit));
  $statement = database()->query(
    'SELECT id, owner_id, request_id, created_at, event_type, url, status, message, details_json
     FROM url_events ORDER BY id DESC LIMIT ' . $limit
  );
  $events = $statement->fetchAll();
  foreach ($events as &$event) {
    $event['details'] = json_decode((string)($event['details_json'] ?? ''), true) ?: null;
    unset($event['details_json']);
  }
  return $events;
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

function invalidUrlResult($message, $diagnostic = []) {
  return ['valid' => false, 'message' => $message] + (is_array($diagnostic) ? $diagnostic : []);
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

function redditPostDetails($url) {
  $parts = parse_url(trim((string)$url));
  if (!is_array($parts)) return null;

  $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
  if (!in_array($host, ['reddit.com', 'www.reddit.com', 'old.reddit.com', 'new.reddit.com'], true)) return null;

  $path = (string)($parts['path'] ?? '');
  if (!preg_match('~^/r/[a-z0-9_]+/comments/([a-z0-9]+)(?:/([a-z0-9_-]*))?/?$~i', $path, $matches)) return null;

  $postId = strtolower($matches[1]);
  $slug = rawurldecode((string)($matches[2] ?? ''));
  $title = preg_replace('/\s+/u', ' ', trim(str_replace(['-', '_'], ' ', $slug)));
  if (!is_string($title) || $title === '') $title = 'Reddit video ' . $postId;
  else $title = ucfirst($title);

  return [
    'postId' => $postId,
    'mediaEmbedUrl' => 'https://www.redditmedia.com/mediaembed/' . $postId,
    'postEmbedUrl' => 'https://embed.reddit.com' . rtrim($path, '/') . '/',
    'sourceTitle' => $title
  ];
}

function htmlTagAttribute($tag, $name) {
  $quotedName = preg_quote($name, '~');
  if (preg_match('~\b' . $quotedName . '\s*=\s*(["\'])(.*?)\1~is', (string)$tag, $match)) {
    return html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
  }
  if (preg_match('~\b' . $quotedName . '\s*=\s*([^\s>]+)~i', (string)$tag, $match)) {
    return html_entity_decode(trim($match[1], "\"'"), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  }
  return null;
}

function cleanRedditTitle($title, $fallback) {
  $title = html_entity_decode(strip_tags((string)$title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $title = preg_replace('/\s+/u', ' ', trim($title));
  return is_string($title) && $title !== '' ? $title : $fallback;
}

function redditExternalCandidate($url) {
  $url = trim(html_entity_decode((string)$url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
  if (str_starts_with($url, '//')) $url = 'https:' . $url;
  $parts = parse_url($url);
  if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) return null;
  $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
  if ($host === '') return null;
  if ($host === 'out.reddit.com' && isset($parts['query'])) {
    parse_str((string)$parts['query'], $query);
    foreach (['url', 'destination', 'dest'] as $key) {
      if (!empty($query[$key]) && is_string($query[$key])) return redditExternalCandidate($query[$key]);
    }
  }
  foreach (['reddit.com', 'redd.it', 'redditmedia.com', 'redditstatic.com', 'v.redd.it'] as $redditHost) {
    if ($host === $redditHost || str_ends_with($host, '.' . $redditHost)) return null;
  }
  return $url;
}

function parseRedditPostMetadata($html, $fallbackTitle) {
  if (!is_string($html) || $html === '' || strlen($html) > 2 * 1024 * 1024) {
    return ['kind' => 'missing', 'reason' => 'missing', 'sourceTitle' => $fallbackTitle];
  }

  $title = $fallbackTitle;
  $metaExternalUrls = [];
  if (preg_match_all('~<meta\b[^>]*>~i', $html, $metaTags)) {
    foreach ($metaTags[0] as $tag) {
      $property = strtolower((string)(htmlTagAttribute($tag, 'property') ?? htmlTagAttribute($tag, 'name') ?? ''));
      if ($property === 'og:title' || $property === 'twitter:title') {
        $title = cleanRedditTitle(htmlTagAttribute($tag, 'content'), $title);
      }
      if (in_array($property, ['og:video', 'og:video:url', 'og:video:secure_url', 'twitter:player'], true)) {
        $metaExternalUrls[] = htmlTagAttribute($tag, 'content');
      }
    }
  }
  if (preg_match('~<a[^>]+id=["\']embed-title["\'][^>]*>.*?<h1[^>]*>(.*?)</h1>~is', $html, $titleMatch)) {
    $title = cleanRedditTitle($titleMatch[1], $title);
  }

  if (preg_match('~\bdata-hls-url\s*=\s*(["\'])(.*?)\1~is', $html, $hlsMatch)) {
    $hlsUrl = html_entity_decode($hlsMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match('~^https://v\.redd\.it/([a-z0-9]+)/HLSPlaylist\.m3u8(?:\?.*)?$~i', $hlsUrl, $pathMatch)) {
      return [
        'kind' => 'native',
        'downloadUrl' => 'https://v.redd.it/' . strtolower($pathMatch[1]) . '/HLSPlaylist.m3u8',
        'sourceTitle' => $title
      ];
    }
  }

  $post = null;
  if (preg_match('~<shreddit-screenview-data\b[^>]*\bdata=(["\'])(.*?)\1~is', $html, $contextMatch)) {
    $context = json_decode(html_entity_decode($contextMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
    if (is_array($context) && isset($context['post']) && is_array($context['post'])) $post = $context['post'];
  }
  if ($post !== null) {
    $title = cleanRedditTitle($post['title'] ?? '', $title);
    $type = strtolower((string)($post['type'] ?? $post['post_type'] ?? ''));
    $postUrls = [];
    foreach (['url_overridden_by_dest', 'outbound_url', 'destination_url', 'external_url', 'content_href', 'url'] as $key) {
      if (!empty($post[$key]) && is_string($post[$key])) $postUrls[] = html_entity_decode($post[$key], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if ($type === 'video') {
      foreach ($postUrls as $postUrl) {
        if (!preg_match('~^https://v\.redd\.it/([a-z0-9]+)/?$~i', $postUrl, $nativeMatch)) continue;
        return [
          'kind' => 'native',
          'downloadUrl' => 'https://v.redd.it/' . strtolower($nativeMatch[1]) . '/HLSPlaylist.m3u8',
          'sourceTitle' => $title
        ];
      }
    }
    if (in_array($type, ['gallery', 'image_gallery'], true)) return ['kind' => 'missing', 'reason' => 'gallery', 'sourceTitle' => $title];
    if (in_array($type, ['self', 'text', 'selfpost'], true)) return ['kind' => 'missing', 'reason' => 'self', 'sourceTitle' => $title];
    if (in_array($type, ['deleted', 'private', 'removed', 'unavailable'], true) || !empty($post['is_deleted']) || !empty($post['is_private'])) {
      return ['kind' => 'missing', 'reason' => 'unavailable', 'sourceTitle' => $title];
    }
    foreach ($postUrls as $postUrl) {
      $externalUrl = redditExternalCandidate($postUrl);
      if ($externalUrl !== null) return ['kind' => 'external', 'outboundUrl' => $externalUrl, 'sourceTitle' => $title];
    }
  }

  if (preg_match_all('~<shreddit-post\b[^>]*>~i', $html, $postTags)) {
    foreach ($postTags[0] as $tag) {
      $type = strtolower((string)(htmlTagAttribute($tag, 'post-type') ?? htmlTagAttribute($tag, 'type') ?? ''));
      if ((string)htmlTagAttribute($tag, 'is-gallery') === 'true' || $type === 'gallery') {
        return ['kind' => 'missing', 'reason' => 'gallery', 'sourceTitle' => $title];
      }
      if ((string)htmlTagAttribute($tag, 'is-self') === 'true' || in_array($type, ['self', 'text'], true)) {
        return ['kind' => 'missing', 'reason' => 'self', 'sourceTitle' => $title];
      }
      if ((string)htmlTagAttribute($tag, 'is-deleted') === 'true' || (string)htmlTagAttribute($tag, 'is-private') === 'true') {
        return ['kind' => 'missing', 'reason' => 'unavailable', 'sourceTitle' => $title];
      }
      foreach (['content-href', 'outbound-url', 'data-url'] as $attribute) {
        $externalUrl = redditExternalCandidate(htmlTagAttribute($tag, $attribute));
        if ($externalUrl !== null) return ['kind' => 'external', 'outboundUrl' => $externalUrl, 'sourceTitle' => $title];
      }
    }
  }

  foreach ($metaExternalUrls as $candidate) {
    $externalUrl = redditExternalCandidate($candidate);
    if ($externalUrl !== null) return ['kind' => 'external', 'outboundUrl' => $externalUrl, 'sourceTitle' => $title];
  }

  if (preg_match_all('~<iframe\b[^>]*>~i', $html, $iframeTags)) {
    foreach ($iframeTags[0] as $tag) {
      $externalUrl = redditExternalCandidate(htmlTagAttribute($tag, 'src'));
      if ($externalUrl !== null) return ['kind' => 'external', 'outboundUrl' => $externalUrl, 'sourceTitle' => $title];
    }
  }

  if (preg_match_all('~<a\b[^>]*>~i', $html, $anchorTags)) {
    foreach ($anchorTags[0] as $tag) {
      $class = strtolower((string)htmlTagAttribute($tag, 'class'));
      $slot = strtolower((string)htmlTagAttribute($tag, 'slot'));
      if (!str_contains($class, 'title') && !in_array($slot, ['full-post-link', 'outbound-link'], true)) continue;
      $externalUrl = redditExternalCandidate(htmlTagAttribute($tag, 'href'));
      if ($externalUrl !== null) return ['kind' => 'external', 'outboundUrl' => $externalUrl, 'sourceTitle' => $title];
    }
  }

  $lowerHtml = strtolower($html);
  if (str_contains($lowerHtml, 'this post was deleted') || str_contains($lowerHtml, 'this post is private') || str_contains($lowerHtml, 'post is unavailable')) {
    return ['kind' => 'missing', 'reason' => 'unavailable', 'sourceTitle' => $title];
  }
  return ['kind' => 'missing', 'reason' => 'missing', 'sourceTitle' => $title];
}

function normalizeRedirectIdentity($url) {
  $parts = parse_url((string)$url);
  if (!is_array($parts)) return trim((string)$url);
  $scheme = strtolower((string)($parts['scheme'] ?? ''));
  $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
  $port = isset($parts['port']) && !(($scheme === 'http' && $parts['port'] === 80) || ($scheme === 'https' && $parts['port'] === 443)) ? ':' . $parts['port'] : '';
  $path = $parts['path'] ?? '/';
  $query = isset($parts['query']) ? '?' . $parts['query'] : '';
  return $scheme . '://' . $host . $port . ($path === '' ? '/' : $path) . $query;
}

function normalizeRedirectPath($path) {
  $segments = [];
  foreach (explode('/', (string)$path) as $segment) {
    if ($segment === '' || $segment === '.') continue;
    if ($segment === '..') array_pop($segments);
    else $segments[] = $segment;
  }
  return '/' . implode('/', $segments);
}

function resolveRedirectLocation($baseUrl, $location) {
  $location = trim(html_entity_decode((string)$location, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
  if ($location === '' || preg_match('/[\x00-\x1F\x7F]/', $location)) return null;
  if (preg_match('~^https?://~i', $location)) return $location;
  $base = parse_url($baseUrl);
  if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) return null;
  $origin = strtolower($base['scheme']) . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
  if (str_starts_with($location, '//')) return strtolower($base['scheme']) . ':' . $location;
  if (str_starts_with($location, '?')) return $origin . ($base['path'] ?? '/') . $location;
  if (str_starts_with($location, '#')) return $baseUrl;
  $locationParts = parse_url($location);
  if ($locationParts === false || isset($locationParts['scheme']) || isset($locationParts['host'])) return null;
  $query = isset($locationParts['query']) ? '?' . $locationParts['query'] : '';
  $relativePath = $locationParts['path'] ?? '';
  if (str_starts_with($relativePath, '/')) return $origin . normalizeRedirectPath($relativePath) . $query;
  $baseDirectory = rtrim(str_replace('\\', '/', dirname($base['path'] ?? '/')), '/');
  return $origin . normalizeRedirectPath($baseDirectory . '/' . $relativePath) . $query;
}

function redirectLocationFromExecution($execution) {
  if (isset($execution['location']) && is_string($execution['location'])) return trim($execution['location']);
  $headers = (string)($execution['headers'] ?? $execution['stdout'] ?? '');
  if (!preg_match_all('/^location:\s*(.+?)\s*$/mi', $headers, $matches) || empty($matches[1])) return null;
  return trim((string)end($matches[1]));
}

function resolveBoundedRedirects($url, $resolver = null, $fetcher = null, $limit = REDDIT_REDIRECT_LIMIT, $deadline = null) {
  $current = $url;
  $visited = [];
  $chain = [];
  $deadline = $deadline ?? microtime(true) + REDDIT_RESOLUTION_TIMEOUT;
  for ($hop = 0; $hop <= $limit; $hop++) {
    $target = validatePublicUrlTarget($current, $resolver);
    if (!$target['valid']) {
      return invalidUrlResult('The Reddit destination redirects to an unsafe or private network address.', [
        'diagnosticStage' => 'reddit_redirect_target',
        'diagnosticDetail' => $target['message'] ?? 'Redirect target rejected.',
        'redirectChain' => $chain
      ]);
    }
    $current = $target['url'];
    $identity = normalizeRedirectIdentity($current);
    if (isset($visited[$identity])) {
      return invalidUrlResult('The Reddit destination contains a redirect loop.', [
        'diagnosticStage' => 'reddit_redirect_loop',
        'diagnosticDetail' => 'A redirect target was visited more than once.',
        'redirectChain' => $chain
      ]);
    }
    $visited[$identity] = true;
    $chain[] = $current;

    $remaining = $deadline - microtime(true);
    if ($remaining <= 0) {
      return invalidUrlResult('The Reddit destination took too long to resolve. Try again.', [
        'diagnosticStage' => 'reddit_redirect_timeout',
        'diagnosticDetail' => 'The total Reddit resolution deadline was reached.',
        'redirectChain' => $chain
      ]);
    }
    $probeTimeout = max(1, min(4, (int)ceil($remaining)));

    $execution = $fetcher === null
      ? executeCommand([
        'curl', '--silent', '--show-error', '--compressed', '--head', '--max-redirs', '0',
        '--max-time', (string)$probeTimeout, '--user-agent', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/141.0 Safari/537.36',
        '--dump-header', '-', '--output', '/dev/null', $current
      ], $probeTimeout + 1)
      : $fetcher($current, $probeTimeout, 'redirect_probe');
    if (!is_array($execution) || ($execution['exitCode'] ?? 1) !== 0) {
      return invalidUrlResult('The Reddit destination could not be reached reliably. Try again in a moment.', [
        'diagnosticStage' => 'reddit_redirect_probe',
        'diagnosticDetail' => sanitizedValidationError($execution['stderr'] ?? ''),
        'redirectChain' => $chain
      ]);
    }
    $location = redirectLocationFromExecution($execution);
    if ($location === null || $location === '') {
      return ['valid' => true, 'url' => $current, 'redirectChain' => $chain];
    }
    if ($hop === $limit) {
      return invalidUrlResult('The Reddit destination uses too many redirects.', [
        'diagnosticStage' => 'reddit_redirect_limit',
        'diagnosticDetail' => 'The redirect limit of ' . $limit . ' was exceeded.',
        'redirectChain' => $chain
      ]);
    }
    $next = resolveRedirectLocation($current, $location);
    if ($next === null) {
      return invalidUrlResult('The Reddit destination returned an invalid redirect.', [
        'diagnosticStage' => 'reddit_redirect_location',
        'diagnosticDetail' => 'The redirect Location header was invalid.',
        'redirectChain' => $chain
      ]);
    }
    $current = $next;
  }
  return invalidUrlResult('The Reddit destination uses too many redirects.');
}

function redditMissingMediaResult($reason, $diagnostic = []) {
  $messages = [
    'self' => 'This Reddit post is a self-post and does not link to downloadable media.',
    'gallery' => 'This Reddit post is a gallery, not a single supported media link.',
    'unavailable' => 'This Reddit post is deleted, private, or unavailable.',
    'missing' => 'No outbound media link was found in this Reddit post.'
  ];
  return invalidUrlResult($messages[$reason] ?? $messages['missing'], $diagnostic);
}

function resolveRedditPost($url, $resolver = null, $fetcher = null) {
  $post = redditPostDetails($url);
  if ($post === null) return null;

  $deadline = microtime(true) + REDDIT_RESOLUTION_TIMEOUT;
  $metadata = null;
  $fetchFailures = [];
  foreach ([
    ['stage' => 'media_embed', 'url' => $post['mediaEmbedUrl'], 'maxBytes' => 512 * 1024],
    ['stage' => 'post_embed', 'url' => $post['postEmbedUrl'], 'maxBytes' => 2 * 1024 * 1024]
  ] as $endpoint) {
    $remaining = $deadline - microtime(true);
    if ($remaining <= 0) break;
    $timeout = max(1, min(8, (int)ceil($remaining)));
    $embedTarget = validatePublicUrlTarget($endpoint['url'], $resolver);
    if (!$embedTarget['valid']) {
      return invalidUrlResult('Reddit could not be reached safely.', [
        'diagnosticStage' => 'reddit_' . $endpoint['stage'] . '_target',
        'diagnosticDetail' => $embedTarget['message'] ?? 'Reddit metadata target rejected.'
      ]);
    }
    $execution = $fetcher === null
      ? executeCommand([
        'curl', '--fail-with-body', '--silent', '--show-error', '--compressed',
        '--max-time', (string)$timeout, '--max-filesize', (string)$endpoint['maxBytes'],
        '--user-agent', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/141.0 Safari/537.36',
        '--header', 'Accept-Language: en-US,en;q=0.9',
        '--header', 'Accept: text/html', $embedTarget['url']
      ], $timeout + 1)
      : $fetcher($embedTarget['url'], $timeout, $endpoint['stage']);
    if (!is_array($execution) || ($execution['exitCode'] ?? 1) !== 0) {
      if ($fetcher === null && is_array($execution)) logValidationFailure($url, $execution, 0);
      $fetchFailures[] = [
        'stage' => $endpoint['stage'],
        'detail' => sanitizedValidationError($execution['stderr'] ?? ''),
        'exitCode' => (int)($execution['exitCode'] ?? 1),
        'timedOut' => !empty($execution['timedOut'])
      ];
      continue;
    }
    $candidate = parseRedditPostMetadata($execution['stdout'] ?? '', $post['sourceTitle']);
    if (($candidate['kind'] ?? '') !== 'missing' || ($candidate['reason'] ?? '') !== 'missing') {
      $metadata = $candidate;
      break;
    }
    $metadata = $candidate;
  }

  if ($metadata === null) {
    $lastFailure = end($fetchFailures) ?: [];
    return invalidUrlResult('Reddit could not be reached reliably. Try again in a moment.', [
      'diagnosticStage' => 'reddit_metadata_fetch',
      'diagnosticDetail' => $lastFailure['detail'] ?? 'Every Reddit metadata endpoint failed.',
      'exitCode' => $lastFailure['exitCode'] ?? 1,
      'timedOut' => !empty($lastFailure['timedOut'])
    ]);
  }
  if (($metadata['kind'] ?? '') === 'missing') {
    return redditMissingMediaResult($metadata['reason'] ?? 'missing', [
      'diagnosticStage' => 'reddit_metadata_' . ($metadata['reason'] ?? 'missing'),
      'diagnosticDetail' => 'Reddit metadata did not contain a usable native or outbound media URL.'
    ]);
  }
  if (($metadata['kind'] ?? '') === 'external') {
    $redirectResult = resolveBoundedRedirects($metadata['outboundUrl'], $resolver, $fetcher, REDDIT_REDIRECT_LIMIT, $deadline);
    if (!$redirectResult['valid']) return $redirectResult;
    return [
      'valid' => true,
      'kind' => 'external',
      'message' => 'Reddit destination resolved.',
      'downloadUrl' => $redirectResult['url'],
      'sourceTitle' => $metadata['sourceTitle'],
      'originalUrl' => $url,
      'redirectChain' => $redirectResult['redirectChain']
    ];
  }

  $media = $metadata;

  $remaining = $deadline - microtime(true);
  if ($remaining <= 0) return invalidUrlResult('Reddit took too long to resolve. Try again.', [
    'diagnosticStage' => 'reddit_resolution_timeout',
    'diagnosticDetail' => 'The total Reddit resolution deadline was reached before media verification.'
  ]);
  $timeout = max(1, min(8, (int)ceil($remaining)));

  $mediaTarget = validatePublicUrlTarget($media['downloadUrl'], $resolver);
  if (!$mediaTarget['valid']) return invalidUrlResult('The Reddit video location could not be verified safely.', [
    'diagnosticStage' => 'reddit_media_target',
    'diagnosticDetail' => $mediaTarget['message'] ?? 'Media target rejected.'
  ]);
  $media['downloadUrl'] = $mediaTarget['url'];

  $manifestExecution = $fetcher === null
    ? executeCommand([
      'curl', '--fail-with-body', '--silent', '--show-error', '--compressed',
      '--max-time', (string)$timeout, '--max-filesize', (string)(256 * 1024),
      '--user-agent', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/141.0 Safari/537.36',
      '--header', 'Accept: application/vnd.apple.mpegurl', $mediaTarget['url']
    ], $timeout + 1)
    : $fetcher($mediaTarget['url'], $timeout, 'manifest');
  $manifest = is_array($manifestExecution) ? ltrim((string)($manifestExecution['stdout'] ?? '')) : '';
  if (!is_array($manifestExecution) || ($manifestExecution['exitCode'] ?? 1) !== 0 || !str_starts_with($manifest, '#EXTM3U')) {
    if ($fetcher === null && is_array($manifestExecution)) logValidationFailure($url, $manifestExecution, 0);
    return invalidUrlResult('Reddit exposed a video, but its media stream could not be reached. Try again in a moment.', [
      'diagnosticStage' => 'reddit_manifest_fetch',
      'diagnosticDetail' => ($manifestExecution['exitCode'] ?? 1) === 0
        ? 'The media response was not an HLS manifest.'
        : sanitizedValidationError($manifestExecution['stderr'] ?? ''),
      'exitCode' => (int)($manifestExecution['exitCode'] ?? 1),
      'timedOut' => !empty($manifestExecution['timedOut'])
    ]);
  }
  return ['valid' => true, 'kind' => 'native', 'message' => 'Reddit video resolved.', 'originalUrl' => $url] + $media;
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
  $title = normalizedOutputTitle($metadata['title'] ?? $metadata['fulltitle'] ?? $metadata['playlist_title'] ?? $metadata['id']);
  if ($title === null) $title = 'Media ' . substr((string)$metadata['id'], 0, 32);
  return [
    'extractor' => $extractor,
    'sourceTitle' => $title,
    'isPlaylist' => in_array($type, ['playlist', 'multi_video'], true)
  ];
}

function formatExtractorName($extractor) {
  $normalized = strtolower(preg_replace('/[^a-z0-9]/i', '', $extractor));
  if (str_starts_with($normalized, 'youtube')) return 'YouTube';
  if (in_array($normalized, ['generic', 'media'], true)) return 'this media service';
  return $extractor;
}

function providerNameForUrl($url) {
  $host = strtolower(rtrim((string)(parse_url((string)$url, PHP_URL_HOST) ?: 'external provider'), '.'));
  $providers = [
    'youtube.com' => 'YouTube', 'youtu.be' => 'YouTube', 'vimeo.com' => 'Vimeo',
    'streamable.com' => 'Streamable', 'tiktok.com' => 'TikTok', 'x.com' => 'X',
    'twitter.com' => 'X', 'instagram.com' => 'Instagram', 'twitch.tv' => 'Twitch',
    'soundcloud.com' => 'SoundCloud', 'redgifs.com' => 'RedGIFs', 'imgur.com' => 'Imgur'
  ];
  foreach ($providers as $domain => $name) {
    if ($host === $domain || str_ends_with($host, '.' . $domain)) return $name;
  }
  return preg_match('/^[a-z0-9.-]{1,253}$/', $host) ? $host : 'external provider';
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

function validationDiagnosticDetails($validation, $durationMs) {
  return [
    'durationMs' => max(0, (int)$durationMs),
    'source' => $validation['extractor'] ?? null,
    'title' => $validation['sourceTitle'] ?? null,
    'stage' => $validation['diagnosticStage'] ?? null,
    'detail' => $validation['diagnosticDetail'] ?? null,
    'exitCode' => $validation['exitCode'] ?? null,
    'timedOut' => $validation['timedOut'] ?? null,
    'destination' => $validation['downloadUrl'] ?? null,
    'provider' => $validation['destinationProvider'] ?? null,
    'redirects' => isset($validation['redirectChain']) && is_array($validation['redirectChain'])
      ? implode(' -> ', $validation['redirectChain'])
      : null
  ];
}

function validateMediaUrl($url, $resolver = null, $runner = null, $useCache = true, $redditFetcher = null) {
  $structure = validateUrlStructure($url);
  if (!$structure['valid']) return $structure;
  $target = redditPostDetails($structure['url']) === null
    ? validatePublicUrlTarget($structure['url'], $resolver)
    : $structure;
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
    $redditMedia = resolveRedditPost($url, $resolver, $redditFetcher);
    if ($redditMedia !== null && empty($redditMedia['valid'])) {
      return $redditMedia;
    }

    if ($redditMedia !== null && ($redditMedia['kind'] ?? 'native') === 'native') {
      $result = [
        'valid' => true,
        'message' => 'Verified Reddit link.',
        'extractor' => 'Reddit',
        'sourceTitle' => normalizedOutputTitle($redditMedia['sourceTitle'] ?? 'Reddit video'),
        'isPlaylist' => false,
        'downloadUrl' => $redditMedia['downloadUrl'],
        'originalUrl' => $url,
        'wrappedBy' => 'Reddit'
      ];
      if ($useCache) writeUrlValidationCache($url, $result);
      return $result;
    }

    $isRedditExternal = $redditMedia !== null && ($redditMedia['kind'] ?? '') === 'external';
    $validationUrl = $isRedditExternal ? $redditMedia['downloadUrl'] : $url;
    $command = buildYtDlpValidationCommand($validationUrl);
    $startedAt = microtime(true);
    $execution = $runner === null
      ? executeCommand($command, URL_VALIDATION_TIMEOUT)
      : $runner($command, URL_VALIDATION_TIMEOUT);
    $metadata = ($execution['exitCode'] ?? 1) === 0
      ? parseYtDlpValidationOutput($execution['stdout'] ?? '')
      : null;

    if ($metadata === null) {
      if ($runner === null) logValidationFailure($url, $execution, microtime(true) - $startedAt);
      $unsupported = str_contains(strtolower((string)($execution['stderr'] ?? '')), 'unsupported url');
      if ($isRedditExternal && $unsupported) {
        $providerName = providerNameForUrl($validationUrl);
        $result = invalidUrlResult('This Reddit post links to ' . $providerName . ', which this downloader does not support.');
        $result['destinationProvider'] = $providerName;
      }
      else $result = validationFailureResult($execution);
      $result['diagnosticStage'] = 'media_extractor';
      $result['diagnosticDetail'] = sanitizedValidationError($execution['stderr'] ?? '');
      $result['exitCode'] = (int)($execution['exitCode'] ?? 1);
      $result['timedOut'] = !empty($execution['timedOut']);
      if ($isRedditExternal) {
        $result['downloadUrl'] = $validationUrl;
        $result['originalUrl'] = $url;
        $result['wrappedBy'] = 'Reddit';
        $result['destinationProvider'] = $result['destinationProvider'] ?? providerNameForUrl($validationUrl);
        $result['redirectChain'] = $redditMedia['redirectChain'] ?? [];
      }
    }
    else {
      $extractor = $metadata['extractor'];
      $extractorName = formatExtractorName($extractor);
      $result = [
        'valid' => true,
        'message' => $isRedditExternal
          ? ($extractorName === 'this media service' ? 'Verified external media link from Reddit.' : 'Verified ' . $extractorName . ' link from Reddit.')
          : ($extractorName === 'this media service' ? 'Verified media link.' : 'Verified ' . $extractorName . ' link.'),
        'extractor' => $extractor,
        'sourceTitle' => normalizedOutputTitle($isRedditExternal ? $redditMedia['sourceTitle'] : $metadata['sourceTitle']),
        'isPlaylist' => !empty($metadata['isPlaylist'])
      ];
      if ($isRedditExternal) {
        $result['downloadUrl'] = $validationUrl;
        $result['originalUrl'] = $url;
        $result['wrappedBy'] = 'Reddit';
        $result['destinationProvider'] = $extractorName === 'this media service' ? providerNameForUrl($validationUrl) : $extractorName;
        $result['redirectChain'] = $redditMedia['redirectChain'] ?? [];
      }
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
    if (!isset($validationResults[$cacheKey])) {
      $startedAt = microtime(true);
      recordUrlEvent($ownerId, 'queue_validation_requested', $item['url'], 'checking', 'Queue submission revalidation started.');
      $validationResults[$cacheKey] = validateMediaUrl($item['url']);
      recordUrlEvent(
        $ownerId,
        'queue_validation_completed',
        $item['url'],
        $validationResults[$cacheKey]['valid'] ? 'valid' : 'invalid',
        $validationResults[$cacheKey]['message'] ?? 'Validation completed.',
        validationDiagnosticDetails($validationResults[$cacheKey], (microtime(true) - $startedAt) * 1000)
      );
    }
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
    $validation = $validationResults[hash('sha256', $item['url'])];
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
    if (!empty($validation['downloadUrl'])) $job['downloadUrl'] = $validation['downloadUrl'];
    if (!empty($validation['sourceTitle'])) $job['sourceTitle'] = $validation['sourceTitle'];
    if (!empty($validation['extractor'])) $job['extractor'] = $validation['extractor'];
    if (!empty($validation['isPlaylist'])) $job['isPlaylist'] = true;
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
    'url' => $job['url'] ?? '',
    'title' => $job['sourceTitle'] ?? 'Untitled media',
    'source' => $job['extractor'] ?? 'media',
    'isPlaylist' => !empty($job['isPlaylist']),
    'mode' => getJobMode($job),
    'state' => $job['state'] ?? 'unknown',
    'message' => $job['message'] ?? 'Job status loaded.',
    'outputCount' => (int)($job['outputCount'] ?? 0),
    'outputNames' => array_values(array_filter($job['outputNames'] ?? [], 'is_string')),
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
  $url = $job['downloadUrl'] ?? $job['url'];
  $sourceTitle = empty($job['isPlaylist']) ? ($job['sourceTitle'] ?? null) : null;
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
  $executionResult = executeCommand(buildYtDlpCommand($url, $mode, $workDir, $sourceTitle));
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
    'outputNames' => array_column($movedFiles, 'name'),
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

function normalizedOutputTitle($title) {
  if (!is_string($title) || trim($title) === '') return null;
  $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $title = preg_replace('~[/\\\\\x00-\x1F\x7F]+~u', ' ', $title);
  $title = preg_replace('/\s+/u', ' ', trim((string)$title));
  if ($title === '') return null;
  if (preg_match('/^.{1,180}/us', $title, $match)) $title = $match[0];
  return $title;
}

function outputTemplateForTitle($title) {
  $title = normalizedOutputTitle($title);
  if ($title === null) return OUTPUT_TEMPLATE;
  return str_replace('%', '%%', $title) . '.%(ext)s';
}

function buildYtDlpCommand($url, $mode, $workDir, $sourceTitle = null) {
  $command = ['yt-dlp', '--yes-playlist', '--playlist-end', (string)PLAYLIST_MAX_ITEMS];
  if (in_array($mode, ['mp3', 'transcribe'], true)) {
    array_push($command, '-x', '--audio-format', 'mp3', '--audio-quality', '0');
  }
  else {
    array_push($command, '--merge-output-format', 'mp4');
  }
  array_push($command, '--no-mtime', '-o', outputTemplateForTitle($sourceTitle), '-P', $workDir, $url);
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
  $stem = pathinfo($file, PATHINFO_FILENAME);
  $outputFile = $directory . '/' . $stem . '.mp4';
  $temporaryOutput = $directory . '/' . $stem . '.h264-' . bin2hex(random_bytes(4)) . '.mp4';
  $result = executeCommand(['ffmpeg', '-y', '-i', $file, '-c:v', 'libx264', '-c:a', 'aac', $temporaryOutput]);
  if ($result['exitCode'] === 0) {
    if (!unlink($file) || !rename($temporaryOutput, $outputFile)) {
      @unlink($temporaryOutput);
      return false;
    }
    return is_file($outputFile);
  }
  @unlink($temporaryOutput);
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
    return ['files' => 0, 'jobs' => 0, 'cache' => 0, 'urlEvents' => 0, 'work' => 0];
  }

  $removed = ['files' => 0, 'jobs' => 0, 'cache' => 0, 'urlEvents' => 0, 'work' => 0];
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
  $deleteUrlEvents = database()->prepare('DELETE FROM url_events WHERE created_at <= :cutoff');
  $deleteUrlEvents->execute([':cutoff' => $now - URL_EVENT_RETENTION_SECONDS]);
  $removed['urlEvents'] = $deleteUrlEvents->rowCount();
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

function appBuildInfo() {
  $version = preg_match('/^[A-Za-z0-9._-]{1,40}$/', APP_VERSION) ? APP_VERSION : 'unknown';
  $commit = preg_match('/^[a-f0-9]{7,40}$/i', APP_COMMIT) ? strtolower(APP_COMMIT) : 'unknown';
  $updatedAt = preg_match('/^\d{4}-\d{2}-\d{2}$/', APP_UPDATED_AT) ? APP_UPDATED_AT : 'unknown';
  return ['version' => $version, 'commit' => $commit, 'updatedAt' => $updatedAt];
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
  if ($command === 'validation-log') {
    foreach (listUrlEvents($argv[2] ?? 100) as $event) echo json_encode($event, JSON_UNESCAPED_SLASHES) . "\n";
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
    $ownerId = getCurrentVisitorId(true);
    $requestId = $_POST['requestId'] ?? null;
    $startedAt = microtime(true);
    recordUrlEvent($ownerId, 'validation_requested', $_POST['validateUrl'], 'checking', 'URL validation started.', [], $requestId);
    $validation = validateMediaUrl($_POST['validateUrl']);
    recordUrlEvent(
      $ownerId,
      'validation_completed',
      $_POST['validateUrl'],
      $validation['valid'] ? 'valid' : 'invalid',
      $validation['message'],
      validationDiagnosticDetails($validation, (microtime(true) - $startedAt) * 1000),
      $requestId
    );
    sendJsonResponse($validation['valid'] ? 0 : 2, $validation['message'], [
      'valid' => $validation['valid'],
      'title' => $validation['sourceTitle'] ?? null,
      'source' => $validation['extractor'] ?? null,
      'isPlaylist' => !empty($validation['isPlaylist'])
    ]);
  }
  elseif (isset($_POST['urlEvent'])) {
    $allowedEvents = ['field_focus', 'field_blur', 'field_paste', 'field_changed', 'field_removed', 'edit_started', 'status_selected', 'status_recorded'];
    $eventType = strtolower(trim((string)$_POST['urlEvent']));
    if (!in_array($eventType, $allowedEvents, true)) {
      sendJsonResponse(2, 'Invalid URL event.');
    }
    else {
      recordUrlEvent(
        getCurrentVisitorId(true),
        $eventType,
        $_POST['url'] ?? null,
        $_POST['validationState'] ?? null,
        $_POST['message'] ?? null,
        ['trigger' => $_POST['trigger'] ?? null],
        $_POST['requestId'] ?? null
      );
      sendJsonResponse(0, 'URL event recorded.');
    }
  }
  elseif (isset($_POST['queue'])) { enqueueDownloads($_POST['queue']); }
  elseif (isset($_POST['download'])) { enqueueDownloads(legacyQueueItem()); }
  else { sendJsonResponse(2, 'Invalid Request'); }
}
elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['media'])) { serveMediaFile($_GET['media'], getCurrentVisitorId(), isset($_GET['download'])); }
elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['refresh']) && $_GET['refresh'] === 'downloads') { listDownloads(); }
elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['jobs'])) { sendJobsStatus($_GET['jobs'], getCurrentVisitorId()); }
elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['job'])) { sendJobStatus($_GET['job'], getCurrentVisitorId()); }
elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['version'])) { sendJsonResponse(0, 'Build information loaded.', appBuildInfo()); }
else { sendJsonResponse(2, 'Unsupported Request Method.'); }

?>
