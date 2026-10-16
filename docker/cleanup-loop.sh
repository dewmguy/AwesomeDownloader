#!/bin/sh
set -eu

while true; do
  php /var/www/html/system/ajax.php cleanup >/dev/null 2>&1 || true
  sleep "${DOWNLOADER_CLEANUP_INTERVAL_SECONDS:-900}"
done
