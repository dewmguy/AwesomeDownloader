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
check(!$unsupported['valid'] && str_contains($unsupported['message'], 'yt-dlp'), 'An unsupported page passed media validation.');

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

$transcriptItem = formatFileListItem('Example & notes.txt');
check(str_contains($transcriptItem, 'play transcript'), 'Transcript preview class is missing.');
check(str_contains($transcriptItem, 'Example%20%26%20notes.txt'), 'Download URL is not encoded.');

if (!is_dir($testRoot . '/download')) mkdir($testRoot . '/download', 0775, true);
file_put_contents($testRoot . '/download/example.mp3', 'one');
check(str_ends_with(uniqueTargetPath($testRoot . '/download', 'example.mp3'), 'example (2).mp3'), 'Duplicate outputs are not renamed safely.');

cleanupWorkDir($testRoot);
if (!empty($failures)) {
  fwrite(STDERR, implode("\n", $failures) . "\n");
  exit(1);
}
echo "Backend tests passed.\n";
