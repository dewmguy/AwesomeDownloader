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
checkFrontend(str_contains($html, 'fa-solid fa-list-check'), 'The queue action is missing its Font Awesome icon.');
checkFrontend(str_contains($html, 'style.css?v=20260903-fontawesome7'), 'The Font Awesome layout stylesheet is not cache-busted.');
checkFrontend(str_contains($css, 'grid-template-columns: repeat(10, minmax(0, 1fr));'), 'The desktop brand grid is missing.');
checkFrontend(str_contains($css, 'grid-template-columns: repeat(5, minmax(0, 1fr));'), 'The mobile brand grid is missing.');

echo "Frontend tests passed.\n";
