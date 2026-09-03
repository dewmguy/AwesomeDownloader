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

$command = buildYtDlpCommand('https://www.youtube.com/playlist?list=test', 'transcribe', '/tmp/work');
check(in_array('--yes-playlist', $command, true), 'Playlist downloads are not explicitly enabled.');
check(in_array('--playlist-end', $command, true) && in_array((string)PLAYLIST_MAX_ITEMS, $command, true), 'Playlist limit is missing.');
check(in_array('--audio-format', $command, true) && in_array('mp3', $command, true), 'Transcription does not download audio.');
check(end($command) === 'https://www.youtube.com/playlist?list=test', 'URL is not passed as one final argv value.');
check(str_contains(OUTPUT_TEMPLATE, '%(playlist_index|)s%(playlist_index& - |)s'), 'Playlist filename numbering is missing.');

$processResult = executeCommand([PHP_BINARY, '-r', 'fwrite(STDOUT, "out"); fwrite(STDERR, "err");']);
check($processResult['exitCode'] === 0, 'File-backed process execution failed.');
check(($processResult['stdout'] ?? '') === 'out' && ($processResult['stderr'] ?? '') === 'err', 'Process output streams were not captured separately.');

$durations = parseDurationOutput("12.5\n01:02\n1:02:03\n");
check($durations === [12.5, 62.0, 3723.0], 'Playlist duration parsing is incorrect.');
check(parseDurationOutput("12\nNA\n") === null, 'Unknown playlist duration did not fail closed.');

$transcriptItem = formatFileListItem('Example & notes.txt');
check(str_contains($transcriptItem, 'play transcript'), 'Transcript preview class is missing.');
check(str_contains($transcriptItem, 'Example%20%26%20notes.txt'), 'Download URL is not encoded.');

mkdir($testRoot . '/download', 0775, true);
file_put_contents($testRoot . '/download/example.mp3', 'one');
check(str_ends_with(uniqueTargetPath($testRoot . '/download', 'example.mp3'), 'example (2).mp3'), 'Duplicate outputs are not renamed safely.');

cleanupWorkDir($testRoot);
if (!empty($failures)) {
  fwrite(STDERR, implode("\n", $failures) . "\n");
  exit(1);
}
echo "Backend tests passed.\n";
