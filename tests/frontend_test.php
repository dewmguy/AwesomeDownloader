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
$compose = file_get_contents(__DIR__ . '/../compose.yaml');
$entrypoint = file_get_contents(__DIR__ . '/../docker/start-downloader.sh');
$supervisor = file_get_contents(__DIR__ . '/../docker/process-supervisor.py');
$cleanupLoop = file_get_contents(__DIR__ . '/../docker/cleanup-loop.sh');

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
checkFrontend(str_contains($html, 'style.css?v=20261022-observability'), 'The current stylesheet is not cache-busted.');
checkFrontend((bool)preg_match('/\.icons-featured\s*\{[^}]*background:\s*transparent;/s', $css), 'The featured icon strip still has a background.');
checkFrontend(str_contains($css, '.icons-featured .fa-youtube,') && str_contains($css, '.icons-featured .fa-dailymotion { overflow: hidden }'), 'Featured brand-icon background layers are not clipped inside their glyph boxes.');
checkFrontend((bool)preg_match('/\.icons-featured \.fa-facebook::after\s*\{[^}]*left:\s*5px;[^}]*height:\s*1\.7rem;[^}]*width:\s*1\.7rem;/s', $css), 'The featured Facebook backing is not safely inset.');
checkFrontend(str_contains($html, 'class="queue-mode-button" aria-haspopup="listbox"'), 'The styled method picker is missing.');
checkFrontend(str_contains($html, 'class="queue-mode-option" role="option"'), 'The styled method options are missing.');
checkFrontend(str_contains($html, "text('Converting ')") && str_contains($html, "text('to ')"), 'The integrated format picker does not explain the conversion choice.');
checkFrontend(str_contains($html, '$fieldShell.append($validation, $urlContent, $modePicker);'), 'The format picker and verified media summary are not merged into the URL field shell.');
checkFrontend(str_contains($css, 'font-family: "SFMedium", sans-serif;'), 'The method picker font is not explicitly styled.');
checkFrontend(str_contains($css, '.queue-mode-verb { display: none }'), 'The phone layout does not preserve useful URL space.');
checkFrontend(str_contains($css, '.queue-row.queue-row-single { grid-template-columns: minmax(0, 1fr) }'), 'The single-row layout still reserves delete-button space.');
checkFrontend((bool)preg_match('/#queueRows\s*\{[^}]*overflow-x:\s*hidden;[^}]*overflow-y:\s*auto;[^}]*box-shadow:\s*0 0 50px rgba\(0,0,0,\.5\);/s', $css), 'The queue scroller does not own the unclipped queue shadow.');
checkFrontend(!(bool)preg_match('/\.url-field-shell\s*\{[^}]*box-shadow:/s', $css), 'URL fields still carry a shadow that the queue scroller clips.');

checkFrontend(str_contains($html, 'class="url-field-shell validation-idle"'), 'URL verification is not contained in the field row.');
checkFrontend(str_contains($html, 'class="url-status idle"'), 'The compact URL status action is missing.');
checkFrontend(str_contains($html, 'class="url-status-label"') && str_contains($html, "invalid: 'Error'") && str_contains($html, "checking: 'Checking'") && str_contains($html, "valid: 'Verified'"), 'The visible one-word URL statuses are incomplete.');
checkFrontend(!str_contains($html, 'class="url-validation"'), 'URL validation still adds a second row.');
checkFrontend(str_contains($css, '.url-field-shell .url-status'), 'The compact URL status is not styled.');
checkFrontend(str_contains($css, 'grid-template-columns: var(--url-status-width) minmax(0, 1fr) max-content;'), 'The unified status, URL, and format layout is missing.');
checkFrontend(str_contains($css, 'width: var(--url-status-width);') && str_contains($css, 'min-width: var(--url-status-width);') && str_contains($css, 'max-width: var(--url-status-width);'), 'URL status width still changes with its contents.');
checkFrontend(str_contains($html, 'class="verified-media"') && str_contains($html, 'function sourcePresentation(source, url)'), 'Verified URLs do not provide a source-logo metadata summary.');
checkFrontend(str_contains($html, 'sourceTitle: json.title') && str_contains($html, 'source: json.source'), 'Validation metadata is not retained in the URL field.');
checkFrontend(str_contains($html, "$('#queueRows').on('paste', '.queue-url'"), 'Pasted URLs are not checked immediately.');
checkFrontend(str_contains($html, ".prop('readonly', keepReadonly || state === 'checking' || state === 'valid')"), 'The URL is not locked while checking, after verification, or during a duplicate-output error.');
checkFrontend(str_contains($html, "state === 'valid' ? accessibleMessage + ' Click to edit this URL.'"), 'The verified state does not explain how to edit the locked URL.');
checkFrontend(str_contains($html, "$.post('/system/ajax.php', { validateUrl: url, requestId:"), 'Authoritative URL verification is missing or lacks diagnostic correlation.');
checkFrontend(str_contains($html, "\$input.data('validatedUrl') === url && (priorState === 'valid' || priorState === 'invalid')") && str_contains($html, "setUrlValidation(\$input, priorState, \$input.data('validationMessage')"), 'An unchanged URL status is not restored without revalidation on blur.');
checkFrontend(str_contains($html, "['valid', 'invalid'].indexOf(\$input.data('validationState')) !== -1"), 'Previously returned URL statuses are not reused when the value is unchanged.');
checkFrontend(!str_contains($html, "resetUrlValidation(\$input);\n    \$input.prop('readonly', false).focus().select();"), 'Selecting a verified URL still clears its validation before the user changes it.');
checkFrontend(str_contains($html, "'field_focus'") && str_contains($html, "logUrlInteraction(\$input, 'field_blur'") && str_contains($html, "logUrlInteraction(\$input, 'field_paste'") && str_contains($html, "logUrlInteraction(\$input, 'field_changed'") && str_contains($html, "logUrlInteraction(\$input, 'field_removed'"), 'URL field interactions are not comprehensively recorded.');
checkFrontend(str_contains($html, "logUrlInteraction(\$input, 'status_recorded'") && str_contains($html, 'navigator.sendBeacon'), 'Client URL statuses are not recorded without disturbing the UI.');
checkFrontend(str_contains($html, "url === '' || \$input.data('validationState') !== 'valid'"), 'Empty and unverified rows do not block processing.');
checkFrontend(str_contains($html, "\$input.data('validationState') === 'valid' && !confirm"), 'Only verified queue rows should require removal confirmation.');

checkFrontend(str_contains($html, "jobCount > 1 ? 'Process URLs' : 'Process'"), 'The process action label is not job-count aware.');
checkFrontend(str_contains($html, 'refreshDuplicateOutputChoices()') && str_contains($html, '!allOutputsUnique'), 'Duplicate URL/output pairs do not disable processing.');
checkFrontend(str_contains($html, "'Duplicate ' : 'to '"), 'Duplicate output choices are not identified in the format control.');
checkFrontend(str_contains($html, "setUrlValidation(\$input, 'invalid', 'This URL is already queued as '") && str_contains($html, '{ keepReadonly: true }'), 'A later duplicate URL does not enter a locked error state.');
checkFrontend(str_contains($html, 'usedModesByUrl') && str_contains($html, 'usedModes[mode] = true'), 'Duplicate errors are not assigned in queue order.');
checkFrontend(str_contains($css, '.queue-mode-picker.duplicate-output .queue-mode-button') && str_contains($css, '.queue-mode-option:disabled'), 'Duplicate output choices are not visibly styled or disabled.');
checkFrontend(str_contains($css, '#downloadSubmit:disabled,') && str_contains($css, 'background: #666;'), 'The disabled Process action is not gray.');
checkFrontend(str_contains($html, 'a repeated link must use a different output'), 'Help does not explain unique outputs for repeated links.');
checkFrontend(str_contains($html, 'fa-solid fa-gears'), 'The process action does not have a processing icon.');
checkFrontend(str_contains($html, '<h3>Process Log</h3>') && str_contains($html, "text('Convert')") && str_contains($html, "text('Media')") && str_contains($html, "text('Status')"), 'The process log does not identify its conversion, media, and status fields.');
checkFrontend(str_contains($html, "job.state === 'complete' ? 'Completed'") && str_contains($html, ": 'Active'"), 'The process log does not expose active and completed states.');
checkFrontend(str_contains($html, "base: 'Original'") && str_contains($html, "['base', 'Original']"), 'The original-media choice is not consistently named Original.');
checkFrontend(!str_contains($html, '>Base:</span>'), 'The obsolete Base label remains in the help content.');
checkFrontend(str_contains($html, 'if (outputHideTimer !== null) clearTimeout(outputHideTimer);') && str_contains($html, '}, 10000);'), 'Output messages do not reset and auto-hide after ten seconds.');
checkFrontend(str_contains($html, 'id="buildInfo"') && str_contains($html, "$.get('/system/ajax.php', { version: 1 })"), 'The Help dialog does not show live version, commit, and update metadata.');
checkFrontend(str_contains($css, '.help-heading') && str_contains($css, '#buildInfo'), 'Help build metadata is not styled beside its heading.');
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

checkFrontend(str_contains($dockerfile, '<Directory "/data">') && str_contains($dockerfile, 'VOLUME ["/data"]'), 'The private data volume is not declared and protected.');
checkFrontend(str_contains($dockerfile, 'COPY --from=pot-provider') && str_contains($dockerfile, 'transcriber/server.py /opt/transcriber/server.py'), 'The token provider and transcriber are not included in the application image.');
checkFrontend(str_contains($dockerfile, 'new PDO("sqlite::memory:")'), 'The image does not verify SQLite support while building.');
checkFrontend(str_contains($dockerfile, 'CMD ["start-downloader"]'), 'The supervised container command is missing.');
checkFrontend(str_contains($dockerfile, 'find /var/www/html -type d -exec chmod 0755') && str_contains($dockerfile, 'find /var/www/html -type f -exec chmod 0644'), 'The image does not normalize Apache web-root permissions.');
checkFrontend(str_contains($dockerfile, 'ARG APP_COMMIT') && str_contains($dockerfile, 'APP_UPDATED_AT=${APP_UPDATED_AT}'), 'Build version metadata is not baked into the runtime image.');
checkFrontend(str_contains($entrypoint, 'DOWNLOADER_DATA_DIR:-/data') && str_contains($entrypoint, 'app.sqlite'), 'The named-volume data layout is not initialized.');
checkFrontend(str_contains($supervisor, '"token provider"') && str_contains($supervisor, '"transcriber"') && str_contains($supervisor, '"Apache"'), 'The required processes are not supervised together.');
checkFrontend(str_contains($cleanupLoop, 'php /var/www/html/system/ajax.php cleanup') && str_contains($cleanupLoop, 'DOWNLOADER_CLEANUP_INTERVAL_SECONDS:-900'), 'The periodic retention cleanup is missing.');
checkFrontend(str_contains($compose, 'downloader-data:/data') && str_contains($compose, "\nvolumes:\n  downloader-data:"), 'Compose does not use one Docker-managed data volume.');
checkFrontend(str_contains($compose, 'APP_COMMIT: ${APP_COMMIT:-unknown}') && str_contains($compose, 'APP_UPDATED_AT: ${APP_UPDATED_AT:-2026-10-22}'), 'Compose does not expose build metadata arguments.');
checkFrontend(!str_contains($compose, './app:') && !str_contains($compose, './temp:') && !str_contains($compose, './download:'), 'Compose still depends on host-path bind mounts.');
checkFrontend(substr_count($compose, "\n  downloader:") === 1 && !str_contains($compose, 'downloader-transcriber:') && !str_contains($compose, 'downloader-pot-provider:'), 'Compose still defines multiple application services.');

echo "Frontend tests passed.\n";
