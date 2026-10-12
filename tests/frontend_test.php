<?php

function checkFrontend($condition, $message) {
  if (!$condition) {
    fwrite(STDERR, "Frontend test failed: $message\n");
    exit(1);
  }
}

$html = file_get_contents(__DIR__ . '/../app/index.html');
$css = file_get_contents(__DIR__ . '/../app/system/style.css');
$dockerfile = file_get_contents(__DIR__ . '/../Dockerfile');
$entrypoint = file_get_contents(__DIR__ . '/../docker/start-downloader.sh');

checkFrontend(str_contains($html, '<script src="https://kit.fontawesome.com/666b0b7246.js" crossorigin="anonymous"></script>'), 'The required Font Awesome kit is missing.');
checkFrontend((bool)preg_match('/<h1 class="title">Awesome Media Downloader<\/h1>/', $html), 'The site title is not an h1.');

preg_match('/<p class="icons icons-featured"[^>]*>([\s\S]*?)<\/p>/', $html, $featuredBlock);
preg_match_all('/class="fa-brands fa-([a-z0-9-]+)"/', $featuredBlock[1] ?? '', $featuredMatches);
$featuredBrands = ['youtube', 'vimeo', 'facebook', 'x-twitter', 'instagram', 'tiktok', 'dailymotion', 'twitch', 'reddit-alien', 'soundcloud'];
checkFrontend($featuredMatches[1] === $featuredBrands, 'The 10 featured brand icons are not directly under the title.');

preg_match('/<p class="icons icons-all"[^>]*>([\s\S]*?)<\/p>/', $html, $allBlock);
preg_match_all('/class="fa-brands fa-([a-z0-9-]+)"/', $allBlock[1] ?? '', $allMatches);
$allBrands = [
  'youtube', 'vimeo', 'facebook', 'x-twitter', 'instagram', 'tiktok', 'dailymotion',
  'twitch', 'reddit-alien', 'soundcloud', 'bluesky', 'bandcamp', 'bilibili', 'flickr',
  'pinterest', 'tumblr', 'mixcloud', 'vk', 'snapchat', 'linkedin'
];
checkFrontend($allMatches[1] === $allBrands, 'The Help dialog does not contain all 20 supported brand icons.');
checkFrontend(str_contains($css, '.icons .fa-reddit-alien::after') && str_contains($css, 'left: -1px;'), 'The Reddit white-background alignment is missing.');
checkFrontend(str_contains($css, '.icons .fa-vimeo::after') && str_contains($css, 'top: 4px;'), 'The Vimeo white-background alignment is missing.');
checkFrontend(str_contains($css, '.icons .fa-dailymotion::after') && str_contains($css, 'top: 3px;'), 'The Dailymotion white-background alignment is missing.');
checkFrontend(str_contains($css, 'height: 1.8rem;') && str_contains($css, 'width: 1.8rem;'), 'The Facebook white background size is incorrect.');

checkFrontend(!str_contains(strtolower($html), 'yt-dlp'), 'A public-facing implementation name remains in the page.');
checkFrontend(str_contains($html, 'style.css?v=20261012-unified-queue-row'), 'The redesigned stylesheet is not cache-busted.');
checkFrontend(str_contains($html, 'class="queue-mode-button" aria-haspopup="listbox"'), 'The styled method picker is missing.');
checkFrontend(str_contains($html, 'class="queue-mode-option" role="option"'), 'The styled method options are missing.');
checkFrontend(str_contains($html, "text('Converting ')") && str_contains($html, "text('to ')"), 'The integrated format picker does not explain the conversion choice.');
checkFrontend(str_contains($html, '$fieldShell.append($validation, $url, $modePicker);'), 'The format picker is not merged into the URL field shell.');
checkFrontend(str_contains($css, 'font-family: "SFMedium", sans-serif;'), 'The method picker font is not explicitly styled.');
checkFrontend(str_contains($css, '.queue-mode-verb { display: none }'), 'The phone layout does not preserve useful URL space.');
checkFrontend(str_contains($css, '.queue-row.queue-row-single { grid-template-columns: minmax(0, 1fr) }'), 'The single-row layout still reserves delete-button space.');

checkFrontend(str_contains($html, 'class="url-field-shell validation-idle"'), 'URL verification is not contained in the field row.');
checkFrontend(str_contains($html, 'class="url-status idle"'), 'The compact URL status action is missing.');
checkFrontend(str_contains($html, 'class="url-status-label"') && str_contains($html, "invalid: 'Error'") && str_contains($html, "checking: 'Checking'") && str_contains($html, "valid: 'Verified'"), 'The visible one-word URL statuses are incomplete.');
checkFrontend(!str_contains($html, 'class="url-validation"'), 'URL validation still adds a second row.');
checkFrontend(str_contains($css, '.url-field-shell .url-status'), 'The compact URL status is not styled.');
checkFrontend(str_contains($css, 'grid-template-columns: max-content minmax(0, 1fr) max-content;'), 'The unified status, URL, and format layout is missing.');
checkFrontend(str_contains($html, "$('#queueRows').on('paste', '.queue-url'"), 'Pasted URLs are not checked immediately.');
checkFrontend(str_contains($html, ".prop('readonly', state === 'checking' || state === 'valid')"), 'The URL is not locked while checking and after verification.');
checkFrontend(str_contains($html, "state === 'valid' ? accessibleMessage + ' Click to edit this URL.'"), 'The verified state does not explain how to edit the locked URL.');
checkFrontend(str_contains($html, "$.post('/system/ajax.php', { validateUrl: url })"), 'Authoritative URL verification is missing.');
checkFrontend(str_contains($html, "url === '' || \$input.data('validationState') !== 'valid'"), 'Empty and unverified rows do not block processing.');
checkFrontend(str_contains($html, "\$input.data('validationState') === 'valid' && !confirm"), 'Only verified queue rows should require removal confirmation.');

checkFrontend(str_contains($html, "jobCount > 1 ? 'Process URLs' : 'Process'"), 'The process action label is not job-count aware.');
checkFrontend(str_contains($html, 'fa-solid fa-gears'), 'The process action does not have a processing icon.');
checkFrontend(str_contains($html, '>Save</span> beside a file to download it to your device.'), 'Help does not distinguish processing from saving.');
checkFrontend(str_contains($html, 'id="copyTranscript"'), 'Transcript playback is missing its copy action.');
checkFrontend(str_contains($html, 'navigator.clipboard.writeText(text)'), 'Transcript copying does not use the Clipboard API.');
checkFrontend(str_contains($html, '<i class="fa-solid fa-plus"></i> Add URL</button>'), 'The add-URL button label is incorrect.');
checkFrontend(!str_contains($html, 'Add another URL'), 'The obsolete add-another wording is still present.');

$publicTabPosition = strpos($html, 'id="publicDownloadsTab"');
$privateTabPosition = strpos($html, 'id="privateDownloadsTab"');
checkFrontend($publicTabPosition !== false && $privateTabPosition !== false && $publicTabPosition < $privateTabPosition, 'Public downloads are not presented before private downloads.');
checkFrontend(str_contains($html, 'id="publicDownloadsPanel"') && str_contains($html, 'id="privateDownloadsPanel"'), 'The recent download sections are incomplete.');
checkFrontend(str_contains($html, '{ publishFileId: fileId }'), 'The irreversible public-sharing action is missing.');
checkFrontend(str_contains($html, 'cannot be made private again'), 'Public sharing does not warn that it is irreversible.');
checkFrontend(str_contains($html, 'private to this browser and permanently removed after 72 hours'), 'The 72-hour private retention behavior is not explained.');
checkFrontend(str_contains($html, 'aria-label="Don\'t click the smiley face... when you\'re not looking at him, he\'s looking at you!"'), 'The complete Cthulhu warning is not accessible.');
checkFrontend(str_contains($html, 'class="eldritch-low"') && str_contains($html, 'class="eldritch-mid"') && str_contains($html, 'class="eldritch-high"'), 'The Cthulhu warning does not increase in intensity.');

checkFrontend(str_contains($dockerfile, '<Directory "/var/www/html/download">') && str_contains($dockerfile, '<Directory "/var/www/html/temp">'), 'Runtime storage is not blocked from direct Apache access.');
checkFrontend(str_contains($dockerfile, 'CMD ["start-downloader"]'), 'The cleanup-enabled container command is missing.');
checkFrontend(str_contains($entrypoint, 'php /var/www/html/system/ajax.php cleanup') && str_contains($entrypoint, 'DOWNLOADER_CLEANUP_INTERVAL_SECONDS:-900'), 'The periodic retention cleanup is missing.');
checkFrontend(str_contains($entrypoint, 'chown www-data:www-data "$runtime_dir"') && str_contains($entrypoint, '/var/www/html/temp/files'), 'Runtime metadata directories are not prepared for the Apache worker.');

echo "Frontend tests passed.\n";
