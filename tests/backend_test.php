<?php

$testRoot = sys_get_temp_dir() . '/awesome-downloader-tests-' . bin2hex(random_bytes(4));
putenv("DOWNLOADER_DATA_DIR=$testRoot/data");
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

$duplicateOutput = normalizeQueueItems([
  ['url' => 'https://YOUTUBE.com:443/watch?v=one', 'mode' => 'mp3'],
  ['url' => 'https://youtube.com/watch?v=one', 'mode' => 'mp3']
]);
check(count($duplicateOutput['errors']) === 1 && str_contains($duplicateOutput['errors'][0], 'duplicates URL 1'), 'The same URL and output choice was accepted twice.');
$distinctOutputs = normalizeQueueItems([
  ['url' => 'https://youtube.com/watch?v=one', 'mode' => 'mp3'],
  ['url' => 'https://youtube.com/watch?v=one', 'mode' => 'gif']
]);
check(empty($distinctOutputs['errors']), 'The same URL was not allowed to use different output choices.');

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

$redditUrl = 'https://www.reddit.com/r/example/comments/abc123/a_public_video/';
$redditPost = redditPostDetails($redditUrl);
check(($redditPost['mediaEmbedUrl'] ?? '') === 'https://www.redditmedia.com/mediaembed/abc123', 'A canonical Reddit post URL did not map to its media embed URL.');
check(($redditPost['sourceTitle'] ?? '') === 'A public video', 'The Reddit post slug was not converted to a usable title.');
check(redditPostDetails('https://www.reddit.com/r/example/s/share-code') === null, 'An unsupported Reddit share URL was treated as a canonical post.');
$redditMediaEmbedHtml = <<<'HTML'
<div id="video-abc123" data-hls-url="https://v.redd.it/media123/HLSPlaylist.m3u8?a=signature&amp;v=1" data-link-url="https://v.redd.it/media123"></div>
HTML;
$parsedRedditVideo = parseRedditMediaEmbedVideo($redditMediaEmbedHtml, $redditPost['sourceTitle']);
check(($parsedRedditVideo['downloadUrl'] ?? '') === 'https://v.redd.it/media123/HLSPlaylist.m3u8', 'The Reddit media embed playlist was not extracted.');
check(($parsedRedditVideo['sourceTitle'] ?? '') === 'A public video', 'The Reddit slug title was not retained with its video.');
check(parseRedditMediaEmbedVideo('<p>No native video</p>', 'Example') === null, 'A Reddit media embed without native video was accepted.');
check(parseRedditMediaEmbedVideo('<div data-hls-url="https://v.redd.it.attacker.example/media123/HLSPlaylist.m3u8"></div>', 'Example') === null, 'A lookalike Reddit media hostname was accepted.');

$validationCommand = buildYtDlpValidationCommand('https://www.youtube.com/watch?v=test');
check(in_array('--dump-single-json', $validationCommand, true) && in_array('--skip-download', $validationCommand, true), 'yt-dlp validation is not metadata-only.');
check(in_array('--playlist-end', $validationCommand, true) && in_array('1', $validationCommand, true), 'yt-dlp validation does not limit playlist inspection.');
check(end($validationCommand) === 'https://www.youtube.com/watch?v=test', 'Validation URL is not passed as one final argv value.');

$videoMetadata = parseYtDlpValidationOutput(json_encode([
  'id' => 'test', 'title' => 'A verified video', 'extractor_key' => 'Youtube', 'formats' => [['format_id' => '18']]
]));
check(($videoMetadata['extractor'] ?? '') === 'Youtube', 'Valid yt-dlp video metadata was rejected.');
check(($videoMetadata['sourceTitle'] ?? '') === 'A verified video' && empty($videoMetadata['isPlaylist']), 'Video title metadata was not retained.');
check(formatExtractorName('YoutubeTab') === 'YouTube', 'YouTube extractor labels are not formatted for users.');
$playlistMetadata = parseYtDlpValidationOutput(json_encode([
  '_type' => 'playlist', 'id' => 'playlist', 'title' => 'A verified playlist', 'extractor_key' => 'YoutubeTab', 'entries' => [['id' => 'one']]
]));
check(($playlistMetadata['extractor'] ?? '') === 'YoutubeTab', 'Valid yt-dlp playlist metadata was rejected.');
check(($playlistMetadata['sourceTitle'] ?? '') === 'A verified playlist' && !empty($playlistMetadata['isPlaylist']), 'Playlist metadata was not identified.');
check(parseYtDlpValidationOutput('{"id":"empty","formats":[]}') === null, 'Metadata without downloadable media was accepted.');
check(normalizedOutputTitle('<b>A public / 100% video</b>') === 'A public 100% video', 'Metadata titles are not normalized to their saved filename.');

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
$redditRunnerCalled = false;
$redditFetches = [];
$redditResolvedHosts = [];
$redditSupported = validateMediaUrl(
  $redditUrl,
  function($host) use (&$redditResolvedHosts) {
    $redditResolvedHosts[] = $host;
    return ['8.8.8.8'];
  },
  function($command, $timeout) use (&$redditRunnerCalled) {
    $redditRunnerCalled = true;
    return ['exitCode' => 1, 'stdout' => '', 'stderr' => 'Reddit must not use the generic probe.', 'timedOut' => false];
  },
  false,
  function($fetchUrl, $timeout, $stage) use (&$redditFetches, $redditMediaEmbedHtml) {
    $redditFetches[$stage] = $fetchUrl;
    return ['exitCode' => 0, 'stdout' => $stage === 'media_embed' ? $redditMediaEmbedHtml : "#EXTM3U\n#EXT-X-VERSION:3\n", 'stderr' => '', 'timedOut' => false];
  }
);
check($redditSupported['valid'] && ($redditSupported['extractor'] ?? '') === 'Reddit', 'A public native Reddit video failed media-embed validation.');
check(($redditFetches['media_embed'] ?? '') === $redditPost['mediaEmbedUrl'], 'The Reddit resolver fetched an unexpected endpoint.');
check(($redditFetches['manifest'] ?? '') === 'https://v.redd.it/media123/HLSPlaylist.m3u8', 'The Reddit fallback did not verify the resolved media playlist.');
check(!in_array('www.reddit.com', $redditResolvedHosts, true) && in_array('www.redditmedia.com', $redditResolvedHosts, true) && in_array('v.redd.it', $redditResolvedHosts, true), 'Reddit validation resolved a submitted host it never contacts or skipped a contacted host.');
check(!$redditRunnerCalled, 'Reddit validation still performs the slower generic extractor probe.');
check(($redditSupported['downloadUrl'] ?? '') === ($redditFetches['manifest'] ?? null), 'The validated Reddit media URL was not retained for processing.');
check(($redditSupported['sourceTitle'] ?? '') === 'A public video', 'The validated Reddit title was not normalized to its saved filename.');
$redditNoVideo = resolveRedditVideo(
  $redditUrl,
  fn($host) => ['8.8.8.8'],
  fn($embedUrl, $timeout) => ['exitCode' => 0, 'stdout' => '<p>No native video</p>', 'stderr' => '', 'timedOut' => false]
);
check(!$redditNoVideo['valid'] && str_contains($redditNoVideo['message'], 'native video'), 'A Reddit post without public native video media received an unclear error.');
$redditBrokenManifest = resolveRedditVideo(
  $redditUrl,
  fn($host) => ['8.8.8.8'],
  fn($fetchUrl, $timeout, $stage) => ['exitCode' => 0, 'stdout' => $stage === 'media_embed' ? $redditMediaEmbedHtml : '<html>not media</html>', 'stderr' => '', 'timedOut' => false]
);
check(!$redditBrokenManifest['valid'] && str_contains($redditBrokenManifest['message'], 'media stream'), 'An invalid Reddit HLS manifest was accepted or explained unclearly.');
check(($redditBrokenManifest['diagnosticStage'] ?? '') === 'reddit_manifest_fetch', 'The Reddit manifest failure was not identified in diagnostics.');
$cacheRuns = 0;
$cacheRunner = function($command, $timeout) use (&$cacheRuns) {
  $cacheRuns++;
  return ['exitCode' => 0, 'stdout' => '{"id":"cached","extractor_key":"Example","url":"https://cdn.public-site.net/cached.mp4"}', 'stderr' => '', 'timedOut' => false];
};
$cacheUrl = 'https://media.public-site.net/cached-video';
validateMediaUrl($cacheUrl, fn($host) => ['8.8.8.8'], $cacheRunner, true);
validateMediaUrl($cacheUrl, fn($host) => ['8.8.8.8'], $cacheRunner, true);
check($cacheRuns === 1, 'Successful compatibility validation was not reused from cache.');
$transientRuns = 0;
$transientRunner = function($command, $timeout) use (&$transientRuns) {
  $transientRuns++;
  if ($transientRuns === 1) {
    return ['exitCode' => 1, 'stdout' => '', 'stderr' => 'HTTP Error 429: Too Many Requests', 'timedOut' => false];
  }
  return ['exitCode' => 0, 'stdout' => '{"id":"retry","extractor_key":"Example","url":"https://cdn.public-site.net/retry.mp4"}', 'stderr' => '', 'timedOut' => false];
};
$transientUrl = 'https://media.public-site.net/transient-video';
$transientFailure = validateMediaUrl($transientUrl, fn($host) => ['8.8.8.8'], $transientRunner, true);
$transientRetry = validateMediaUrl($transientUrl, fn($host) => ['8.8.8.8'], $transientRunner, true);
check(!$transientFailure['valid'] && str_contains($transientFailure['message'], 'reached reliably'), 'A transient validation failure was not identified for the user.');
check($transientRetry['valid'] && $transientRuns === 2, 'A transient validation failure was cached instead of allowing an immediate retry.');
check(sanitizedValidationError('failed at https://media.public-site.net/watch?token=secret with timeout') === 'failed at [url] with timeout', 'Validation logging did not remove a submitted URL.');
$authenticationFailure = validationFailureResult(['exitCode' => 1, 'stderr' => 'Account authentication is required. Use --cookies for authentication.', 'timedOut' => false]);
check(str_contains($authenticationFailure['message'], 'not publicly accessible'), 'An authentication-required media response was not explained to the user.');

$firstUrlLock = acquireUrlValidationLock('https://media.public-site.net/lock-one');
$duplicateUrlLock = acquireUrlValidationLock('https://media.public-site.net/lock-one');
$secondUrlLock = acquireUrlValidationLock('https://media.public-site.net/lock-two');
check(is_resource($firstUrlLock) && $duplicateUrlLock === null, 'Duplicate checks for one URL were not coalesced.');
check(is_resource($secondUrlLock), 'An unrelated URL was blocked by another URL check.');
releaseValidationLock($firstUrlLock);
releaseValidationLock($secondUrlLock);

$slotLocks = [];
for ($slot = 0; $slot < URL_VALIDATION_CONCURRENCY; $slot++) $slotLocks[] = acquireUrlValidationSlot();
$overflowSlot = acquireUrlValidationSlot();
check(count(array_filter($slotLocks, 'is_resource')) === URL_VALIDATION_CONCURRENCY && $overflowSlot === null, 'The validation concurrency pool did not enforce its configured capacity.');
foreach ($slotLocks as $slotLock) releaseValidationLock($slotLock);
$unsupported = validateMediaUrl(
  'https://media.public-site.net/page',
  fn($host) => ['8.8.8.8'],
  fn($command, $timeout) => ['exitCode' => 1, 'stdout' => '', 'stderr' => 'unsupported', 'timedOut' => false],
  false
);
check(!$unsupported['valid'] && !str_contains(strtolower($unsupported['message']), 'yt-dlp'), 'An unsupported page exposed an implementation detail or passed validation.');
check(($unsupported['diagnosticStage'] ?? '') === 'media_extractor' && str_contains($unsupported['diagnosticDetail'] ?? '', 'unsupported'), 'Extractor failure diagnostics were not retained for operators.');

$command = buildYtDlpCommand('https://www.youtube.com/playlist?list=test', 'transcribe', '/tmp/work');
check(in_array('--yes-playlist', $command, true), 'Playlist downloads are not explicitly enabled.');
check(in_array('--playlist-end', $command, true) && in_array((string)PLAYLIST_MAX_ITEMS, $command, true), 'Playlist limit is missing.');
check(in_array('--audio-format', $command, true) && in_array('mp3', $command, true), 'Transcription does not download audio.');
check(end($command) === 'https://www.youtube.com/playlist?list=test', 'URL is not passed as one final argv value.');
check(str_contains(OUTPUT_TEMPLATE, '%(playlist_index|)s%(playlist_index& - |)s'), 'Playlist filename numbering is missing.');
$redditCommand = buildYtDlpCommand('https://v.redd.it/media123/HLSPlaylist.m3u8', 'base', '/tmp/work', 'A public / 100% video');
$outputIndex = array_search('-o', $redditCommand, true);
$redditOutputTemplate = $outputIndex === false ? '' : ($redditCommand[$outputIndex + 1] ?? '');
check($redditOutputTemplate === 'A public 100%% video.%(ext)s', 'The resolved Reddit title was not converted into a safe output template.');

$clientJob = formatJobForClient([
  'id' => str_repeat('b', 24),
  'url' => 'https://www.youtube.com/watch?v=test',
  'sourceTitle' => 'A verified video',
  'extractor' => 'Youtube',
  'mode' => 'base',
  'state' => 'complete',
  'message' => 'One file is ready to save.',
  'outputCount' => 1,
  'outputNames' => ['A verified video.mp4']
]);
check(($clientJob['url'] ?? '') === 'https://www.youtube.com/watch?v=test' && ($clientJob['title'] ?? '') === 'A verified video', 'Process-log media metadata is missing from job responses.');
check(($clientJob['source'] ?? '') === 'Youtube' && ($clientJob['outputNames'][0] ?? '') === 'A verified video.mp4', 'Process-log source or completed filename is missing from job responses.');

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
check(is_file(DATABASE_PATH), 'The SQLite runtime database was not initialized.');
$tables = database()->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
check(in_array('jobs', $tables, true) && in_array('files', $tables, true) && in_array('validation_cache', $tables, true) && in_array('url_events', $tables, true), 'The SQLite schema is incomplete.');
$now = time();
$ownerA = hash('sha256', 'owner-a');
$ownerB = hash('sha256', 'owner-b');
check(recordUrlEvent($ownerA, 'field_paste', 'https://media.public-site.net/watch?token=debug', 'idle', 'URL pasted.', ['trigger' => 'paste'], 'url-test-1'), 'A URL interaction event was not stored.');
$recentEvents = listUrlEvents(1);
check(($recentEvents[0]['url'] ?? '') === 'https://media.public-site.net/watch?token=debug' && ($recentEvents[0]['event_type'] ?? '') === 'field_paste', 'URL diagnostics did not retain the attempted URL and interaction.');
check(($recentEvents[0]['details']['trigger'] ?? '') === 'paste', 'URL diagnostic details were not retained.');
check((appBuildInfo()['version'] ?? '') === '2026.10.23', 'Build information is not available to the Help dialog.');
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
$oldTimestamp = $now - CONTENT_RETENTION_SECONDS - 10;
writeJob($oldJobId, [
  'id' => $oldJobId,
  'ownerId' => $ownerA,
  'state' => 'complete',
  'createdAt' => $oldTimestamp,
  'updatedAt' => $oldTimestamp
]);
database()->prepare('UPDATE jobs SET updated_at = :updated_at WHERE id = :id')->execute([
  ':updated_at' => $oldTimestamp,
  ':id' => $oldJobId
]);
$oldCacheUrl = 'https://media.public-site.net/old-cache';
writeUrlValidationCache($oldCacheUrl, ['valid' => true, 'message' => 'Verified media link.']);
database()->prepare('UPDATE validation_cache SET created_at = :created_at WHERE cache_key = :cache_key')->execute([
  ':created_at' => $oldTimestamp,
  ':cache_key' => hash('sha256', $oldCacheUrl)
]);
recordUrlEvent($ownerA, 'validation_completed', 'https://media.public-site.net/expired-log', 'invalid', 'Expired diagnostic.', [], 'url-old', $oldTimestamp);
$cleanup = purgeExpiredContent($now);
check(readFileMetadata($expired['id']) === null && !is_file(FINAL_DIR . '/' . $expired['storageName']), 'Expired tracked output was not permanently removed.');
check(!is_file(FINAL_DIR . '/legacy-old.mp3'), 'Expired legacy output was not permanently removed.');
check(!is_file(FINAL_DIR . '/abandoned.part'), 'Expired partial output was not permanently removed.');
$oldJobCount = database()->query("SELECT count(*) FROM jobs WHERE id = '$oldJobId'")->fetchColumn();
$oldCacheCount = database()->query("SELECT count(*) FROM validation_cache WHERE cache_key = '" . hash('sha256', $oldCacheUrl) . "'")->fetchColumn();
$oldUrlEventCount = database()->query("SELECT count(*) FROM url_events WHERE request_id = 'url-old'")->fetchColumn();
check((int)$oldJobCount === 0 && (int)$oldCacheCount === 0 && (int)$oldUrlEventCount === 0, 'Expired job, validation cache, or URL diagnostics were not removed.');
check(($cleanup['files'] ?? 0) >= 3 && ($cleanup['jobs'] ?? 0) >= 1 && ($cleanup['cache'] ?? 0) >= 1 && ($cleanup['urlEvents'] ?? 0) >= 1, 'Cleanup counters do not reflect expired data.');

check(jobBelongsToOwner(['ownerId' => $ownerA], $ownerA), 'Owner job authorization failed.');
check(!jobBelongsToOwner(['ownerId' => $ownerA], $ownerB), 'Another visitor passed job authorization.');

cleanupWorkDir($testRoot);
if (!empty($failures)) {
  fwrite(STDERR, implode("\n", $failures) . "\n");
  exit(1);
}
echo "Backend tests passed.\n";
