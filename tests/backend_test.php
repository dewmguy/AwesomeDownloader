<?php

$testRoot = sys_get_temp_dir() . '/awesome-downloader-tests-' . bin2hex(random_bytes(4));
putenv("DOWNLOADER_TEMP_DIR=$testRoot/temp");
putenv("DOWNLOADER_FINAL_DIR=$testRoot/download");
define('DOWNLOADER_LIBRARY_ONLY', true);
require dirname(__DIR__) . '/app/system/ajax.php';

$failures = [];
function check($condition, $message) {
  global $failures;
  if (!$condition) $failures[] = $message;
}

$batch = normalizeQueueItems(json_encode([
  ['url' => 'https://www.youtube.com/watch?v=one', 'mode' => 'base'],
  ['url' => 'https://www.youtube.com/playlist?list=two', 'mode' => 'mp3'],
  ['url' => 'https://vimeo.com/three', 'mode' => 'transcribe']
]));
check(count($batch['items']) === 3 && empty($batch['errors']), 'Valid mixed-mode queue was rejected.');

$invalid = normalizeQueueItems([['url' => 'file:///etc/passwd', 'mode' => 'unknown']]);
check(count($invalid['errors']) === 2, 'Invalid URL and mode were not both rejected.');

check(!validateUrlStructure('https://user:secret@youtube.com/watch?v=test')['valid'], 'Credential-bearing URLs were accepted.');
check(!validateUrlStructure('https://youtube.com:8443/watch?v=test')['valid'], 'Nonstandard web ports were accepted.');
check(!validateUrlStructure('http://127.0.0.1/video')['valid'], 'Direct IP URLs were accepted.');
check(!validateUrlStructure('http://media.internal/video')['valid'], 'Reserved internal hostnames were accepted.');
check(!validateUrlStructure('https://media.example.com/video')['valid'], 'Reserved example domains were accepted.');
foreach (['127.0.0.1', '10.0.0.1', '100.64.0.1', '169.254.1.1', '192.0.2.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '::1', 'fc00::1', 'fe80::1', '2001:db8::1', 'ff02::1'] as $blockedAddress) {
  check(!isPublicIpAddress($blockedAddress), "Special-use address $blockedAddress was treated as public.");
}
check(isPublicIpAddress('8.8.8.8') && isPublicIpAddress('2001:4860:4860::8888'), 'Public addresses were rejected.');

$publicTarget = validatePublicUrlTarget('https://media.public-site.net/video', fn($host) => ['8.8.8.8', '2001:4860:4860::8888']);
check($publicTarget['valid'], 'A URL resolving only to public addresses was rejected.');
$privateTarget = validatePublicUrlTarget('https://media.public-site.net/video', fn($host) => ['8.8.8.8', '10.0.0.5']);
check(!$privateTarget['valid'], 'A hostname with a private DNS answer was accepted.');
$unresolvedTarget = validatePublicUrlTarget('https://media.public-site.net/video', fn($host) => []);
check(!$unresolvedTarget['valid'], 'An unresolvable hostname was accepted.');

$validationCommand = buildYtDlpValidationCommand('https://www.youtube.com/watch?v=test');
check(in_array('--dump-single-json', $validationCommand, true) && in_array('--skip-download', $validationCommand, true), 'yt-dlp validation is not metadata-only.');
check(in_array('--playlist-end', $validationCommand, true) && in_array('1', $validationCommand, true), 'yt-dlp validation does not limit playlist inspection.');
check(end($validationCommand) === 'https://www.youtube.com/watch?v=test', 'Validation URL is not passed as one final argv value.');

$videoMetadata = parseYtDlpValidationOutput(json_encode([
  'id' => 'test', 'extractor_key' => 'Youtube', 'formats' => [['format_id' => '18']]
]));
check(($videoMetadata['extractor'] ?? '') === 'Youtube', 'Valid yt-dlp video metadata was rejected.');
check(formatExtractorName('YoutubeTab') === 'YouTube', 'YouTube extractor labels are not formatted for users.');
$playlistMetadata = parseYtDlpValidationOutput(json_encode([
  '_type' => 'playlist', 'id' => 'playlist', 'extractor_key' => 'YoutubeTab', 'entries' => [['id' => 'one']]
]));
check(($playlistMetadata['extractor'] ?? '') === 'YoutubeTab', 'Valid yt-dlp playlist metadata was rejected.');
check(parseYtDlpValidationOutput('{"id":"empty","formats":[]}') === null, 'Metadata without downloadable media was accepted.');

$runnerTimeout = null;
$supported = validateMediaUrl(
  'https://media.public-site.net/video',
  fn($host) => ['8.8.8.8'],
  function($command, $timeout) use (&$runnerTimeout) {
    $runnerTimeout = $timeout;
    return ['exitCode' => 0, 'stdout' => '{"id":"one","extractor_key":"Example","url":"https://cdn.public-site.net/one.mp4"}', 'stderr' => '', 'timedOut' => false];
  },
  false
);
check($supported['valid'] && ($supported['extractor'] ?? '') === 'Example', 'A supported public media URL failed validation.');
check($runnerTimeout === URL_VALIDATION_TIMEOUT, 'The compatibility runner did not receive the validation timeout.');
$cacheRuns = 0;
$cacheRunner = function($command, $timeout) use (&$cacheRuns) {
  $cacheRuns++;
  return ['exitCode' => 0, 'stdout' => '{"id":"cached","extractor_key":"Example","url":"https://cdn.public-site.net/cached.mp4"}', 'stderr' => '', 'timedOut' => false];
};
$cacheUrl = 'https://media.public-site.net/cached-video';
validateMediaUrl($cacheUrl, fn($host) => ['8.8.8.8'], $cacheRunner, true);
validateMediaUrl($cacheUrl, fn($host) => ['8.8.8.8'], $cacheRunner, true);
check($cacheRuns === 1, 'Successful compatibility validation was not reused from cache.');
$unsupported = validateMediaUrl(
  'https://media.public-site.net/page',
  fn($host) => ['8.8.8.8'],
  fn($command, $timeout) => ['exitCode' => 1, 'stdout' => '', 'stderr' => 'unsupported', 'timedOut' => false],
  false
);
check(!$unsupported['valid'] && !str_contains(strtolower($unsupported['message']), 'yt-dlp'), 'An unsupported page exposed an implementation detail or passed validation.');

$command = buildYtDlpCommand('https://www.youtube.com/playlist?list=test', 'transcribe', '/tmp/work');
check(in_array('--yes-playlist', $command, true), 'Playlist downloads are not explicitly enabled.');
check(in_array('--playlist-end', $command, true) && in_array((string)PLAYLIST_MAX_ITEMS, $command, true), 'Playlist limit is missing.');
check(in_array('--audio-format', $command, true) && in_array('mp3', $command, true), 'Transcription does not download audio.');
check(end($command) === 'https://www.youtube.com/playlist?list=test', 'URL is not passed as one final argv value.');
check(str_contains(OUTPUT_TEMPLATE, '%(playlist_index|)s%(playlist_index& - |)s'), 'Playlist filename numbering is missing.');

$processResult = executeCommand([PHP_BINARY, '-r', 'fwrite(STDOUT, "out"); fwrite(STDERR, "err");']);
check($processResult['exitCode'] === 0, 'File-backed process execution failed.');
check(($processResult['stdout'] ?? '') === 'out' && ($processResult['stderr'] ?? '') === 'err', 'Process output streams were not captured separately.');

$timeoutStart = microtime(true);
$timeoutResult = executeCommand([PHP_BINARY, '-r', 'sleep(3);'], 1);
check($timeoutResult['exitCode'] === 124 && $timeoutResult['timedOut'], 'Timed process execution was not terminated.');
check(microtime(true) - $timeoutStart < 2.5, 'Timed process execution exceeded its bound.');

$durations = parseDurationOutput("12.5\n01:02\n1:02:03\n");
check($durations === [12.5, 62.0, 3723.0], 'Playlist duration parsing is incorrect.');
check(parseDurationOutput("12\nNA\n") === null, 'Unknown playlist duration did not fail closed.');

check(isValidVisitorToken(base64UrlEncode(str_repeat('a', 32))), 'A generated anonymous visitor token was rejected.');
check(!isValidVisitorToken('short-or-unsafe'), 'An invalid anonymous visitor token was accepted.');
check(visitorIdForToken('visitor-a') !== visitorIdForToken('visitor-b'), 'Distinct visitor tokens produced the same owner identity.');

ensureRuntimeDirs();
$now = time();
$ownerA = hash('sha256', 'owner-a');
$ownerB = hash('sha256', 'owner-b');
$sourceDir = $testRoot . '/source';
mkdir($sourceDir, 0775, true);
file_put_contents($sourceDir . '/Example & notes.txt', 'private transcript');
$moved = moveProcessedFiles($sourceDir, FINAL_DIR, $ownerA, $now);
check(count($moved) === 1, 'A completed output was not registered.');
$record = $moved[0] ?? [];
check(($record['name'] ?? '') === 'Example & notes.txt', 'The user-facing filename was not preserved in metadata.');
check((bool)preg_match('/^[a-f0-9]{24}\.txt$/', $record['storageName'] ?? ''), 'The physical filename is not opaque.');
check(is_file(FINAL_DIR . '/' . ($record['storageName'] ?? 'missing')), 'The registered output is missing from protected storage.');

$visibleA = getVisibleDownloads($ownerA, $now + 1);
$visibleB = getVisibleDownloads($ownerB, $now + 1);
check(count($visibleA['private']) === 1 && empty($visibleB['private']), 'Private output visibility is not owner-isolated.');
check(getAccessibleFileRecord($record['id'], $ownerA, $now + 1) !== null, 'The owner cannot access a private output.');
check(getAccessibleFileRecord($record['id'], $ownerB, $now + 1) === null, 'A different visitor can access a private output.');

$privateItem = formatFileListItem($record, true);
check(str_contains($privateItem, 'play transcript'), 'Transcript preview class is missing.');
check(str_contains($privateItem, '/system/ajax.php?media=' . $record['id']), 'Controlled media URL is missing.');
check(!str_contains($privateItem, '/download/'), 'A direct storage URL was exposed.');
check(str_contains($privateItem, '>Save</span>') && str_contains($privateItem, 'class="file-action publish"'), 'Private file actions are incomplete.');

check(publishOwnedFile($record['id'], $ownerA, $now + 10), 'The owner could not make a private output public.');
$published = readFileMetadata($record['id']);
check(!empty($published['public']) && array_key_exists('ownerId', $published) && $published['ownerId'] === null, 'Publishing did not detach the private owner.');
check(($published['expiresAt'] ?? 0) === $now + 10 + CONTENT_RETENTION_SECONDS, 'Publishing did not start a new 72-hour retention window.');
check(getAccessibleFileRecord($record['id'], $ownerB, $now + 11) !== null, 'A public output is not visible to another visitor.');
check(!publishOwnedFile($record['id'], $ownerA, $now + 20), 'A public output was allowed to change sharing state again.');
check(!deleteOwnedFile($record['id'], $ownerA), 'A public output was allowed to return to owner control through deletion.');
$publicItem = formatFileListItem($published, false);
check(!str_contains($publicItem, 'class="file-action publish"') && !str_contains($publicItem, 'class="file-action delete"'), 'Public output exposes private management actions.');

mkdir($sourceDir . '-delete', 0775, true);
file_put_contents($sourceDir . '-delete/delete-me.mp3', 'audio');
$privateDelete = moveProcessedFiles($sourceDir . '-delete', FINAL_DIR, $ownerA, $now)[0];
check(!deleteOwnedFile($privateDelete['id'], $ownerB), 'A different visitor deleted a private output.');
check(deleteOwnedFile($privateDelete['id'], $ownerA), 'The owner could not delete a private output.');

mkdir($sourceDir . '-expired', 0775, true);
file_put_contents($sourceDir . '-expired/expired.mp4', 'video');
$expired = moveProcessedFiles($sourceDir . '-expired', FINAL_DIR, $ownerA, $now - CONTENT_RETENTION_SECONDS - 10)[0];
file_put_contents(FINAL_DIR . '/legacy-old.mp3', 'legacy');
touch(FINAL_DIR . '/legacy-old.mp3', $now - CONTENT_RETENTION_SECONDS - 10);
file_put_contents(FINAL_DIR . '/abandoned.part', 'partial media');
touch(FINAL_DIR . '/abandoned.part', $now - CONTENT_RETENTION_SECONDS - 10);
$oldJobId = str_repeat('a', 24);
file_put_contents(getJobPath($oldJobId), json_encode(['id' => $oldJobId, 'ownerId' => $ownerA, 'state' => 'complete', 'updatedAt' => $now - CONTENT_RETENTION_SECONDS - 10]));
touch(getJobPath($oldJobId), $now - CONTENT_RETENTION_SECONDS - 10);
$oldCache = URL_VALIDATION_CACHE_DIR . '/old.json';
file_put_contents($oldCache, '{}');
touch($oldCache, $now - CONTENT_RETENTION_SECONDS - 10);
$cleanup = purgeExpiredContent($now);
check(readFileMetadata($expired['id']) === null && !is_file(FINAL_DIR . '/' . $expired['storageName']), 'Expired tracked output was not permanently removed.');
check(!is_file(FINAL_DIR . '/legacy-old.mp3'), 'Expired legacy output was not permanently removed.');
check(!is_file(FINAL_DIR . '/abandoned.part'), 'Expired partial output was not permanently removed.');
check(!is_file(getJobPath($oldJobId)) && !is_file($oldCache), 'Expired job or validation metadata was not removed.');
check(($cleanup['files'] ?? 0) >= 3 && ($cleanup['jobs'] ?? 0) >= 1 && ($cleanup['cache'] ?? 0) >= 1, 'Cleanup counters do not reflect expired data.');

check(jobBelongsToOwner(['ownerId' => $ownerA], $ownerA), 'Owner job authorization failed.');
check(!jobBelongsToOwner(['ownerId' => $ownerA], $ownerB), 'Another visitor passed job authorization.');

cleanupWorkDir($testRoot);
if (!empty($failures)) {
  fwrite(STDERR, implode("\n", $failures) . "\n");
  exit(1);
}
echo "Backend tests passed.\n";
