<?php

function checkFrontend($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, "Frontend test failed: $message\n");
    exit(1);
  }
}

$html = file_get_contents(__DIR__ . '/../app/index.html');
$css = file_get_contents(__DIR__ . '/../app/system/style.css');

checkFrontend(str_contains($html, '<script src="https://kit.fontawesome.com/666b0b7246.js" crossorigin="anonymous"></script>'), 'The required Font Awesome kit is missing.');

preg_match_all('/class="fa-brands fa-([a-z0-9-]+)"/', $html, $matches);
$expectedBrands = [
  'youtube', 'vimeo', 'facebook', 'x-twitter', 'instagram', 'tiktok', 'dailymotion',
  'twitch', 'reddit-alien', 'soundcloud', 'bluesky', 'bandcamp', 'bilibili', 'flickr',
  'pinterest', 'tumblr', 'mixcloud', 'vk', 'snapchat', 'linkedin'
];
checkFrontend($matches[1] === $expectedBrands, 'The supported brand icon bar changed unexpectedly.');
checkFrontend(str_contains($html, 'fa-solid fa-download'), 'The single-download action is missing its Font Awesome icon.');
checkFrontend(str_contains($html, "toggleClass('fa-list-check', jobCount > 1)"), 'The multi-job queue icon is not dynamic.');
checkFrontend(str_contains($html, 'style.css?v=20260903-help-dialog'), 'The Help dialog stylesheet is not cache-busted.');
checkFrontend(str_contains($css, 'grid-template-columns: repeat(10, minmax(0, 1fr));'), 'The desktop brand grid is missing.');
checkFrontend(str_contains($css, 'grid-template-columns: repeat(5, minmax(0, 1fr));'), 'The mobile brand grid is missing.');
checkFrontend((bool)preg_match('/<div id="help">[\s\S]*?<p class="icons"[\s\S]*?<\/p>[\s\S]*?<\/div>\s*<\/div>\s*<div id="container">/', $html), 'The brand icons are not contained in the Help dialog.');
checkFrontend(str_contains($html, 'class="queue-mode-button" aria-haspopup="listbox"'), 'The styled download method picker is missing.');
checkFrontend(str_contains($html, 'class="queue-mode-option" role="option"'), 'The styled download method options are missing.');
checkFrontend(str_contains($html, '<input type="hidden" class="queue-mode">'), 'The method picker is missing its queue-value field.');
checkFrontend(str_contains($css, 'font-family: "SFMedium", sans-serif;'), 'The method picker font is not explicitly styled.');
checkFrontend(str_contains($css, '.queue-row.queue-row-single { grid-template-columns: minmax(0, 1fr) 8rem }'), 'The single-row desktop layout still reserves delete-button space.');
checkFrontend(str_contains($css, '.queue-row.queue-row-single { grid-template-columns: minmax(0, 1fr) 7rem }'), 'The single-row mobile layout still reserves delete-button space.');
checkFrontend(str_contains($html, "confirm('Remove this URL from the download queue?')"), 'Queue removal is missing its confirmation prompt.');
checkFrontend(str_contains($html, 'fa-solid fa-trash-can'), 'Queue removal is missing its Font Awesome trash icon.');
checkFrontend(str_contains($css, '.remove-url:hover, .remove-url:focus-visible'), 'Queue removal is missing its hover treatment.');
checkFrontend(str_contains($css, 'background: #C33;'), 'Queue removal does not turn red on hover.');
checkFrontend(str_contains($html, "jobCount > 1 ? 'Download Queue' : 'Download'"), 'The download action label is not job-count aware.');
checkFrontend(str_contains($html, 'id="copyTranscript"'), 'Transcript playback is missing its copy action.');
checkFrontend(str_contains($html, 'fa-solid fa-copy'), 'The transcript copy action is missing its Font Awesome icon.');
checkFrontend(str_contains($html, 'navigator.clipboard.writeText(text)'), 'Transcript copying does not use the Clipboard API.');
checkFrontend(str_contains($html, '<i class="fa-solid fa-plus"></i> Add URL</button>'), 'The add-URL button label is incorrect.');
checkFrontend(!str_contains($html, 'Add another URL'), 'The obsolete add-another wording is still present.');
checkFrontend(!str_contains($html, '<span class="queue-hint">'), 'The format and playlist hint is still visible in the main form.');
checkFrontend(str_contains($html, '<h2>Download Methods</h2>'), 'The simplified download-method guidance is missing from Help.');
checkFrontend(str_contains($html, 'Use <i class="fa-solid fa-copy"></i> to copy a complete transcript.'), 'The transcript copy feature is missing from Help.');
checkFrontend(str_contains($html, "D̸O̷N̸'̴T̶"), 'The original fun Help warning changed unexpectedly.');
checkFrontend(str_contains($html, '<button type="button" id="helplink" aria-label="Help" title="Help">'), 'Help is not an accessible action-row button.');
checkFrontend(!str_contains($html, '<div id="options">'), 'Help still occupies a separate form row.');
checkFrontend(str_contains($css, 'grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 2.75rem;'), 'The queue action row does not reserve an inline Help column.');

echo "Frontend tests passed.\n";
