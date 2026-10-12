#!/bin/sh
set -eu

for runtime_root in /var/www/html/temp /var/www/html/download; do
  mkdir -p "$runtime_root"
  chgrp www-data "$runtime_root"
  chmod g+rwx "$runtime_root"
done

for runtime_dir in \
  /var/www/html/temp/jobs \
  /var/www/html/temp/work \
  /var/www/html/temp/files \
  /var/www/html/temp/url-validation; do
  mkdir -p "$runtime_dir"
  chown www-data:www-data "$runtime_dir"
  chmod 0775 "$runtime_dir"
done

cleanup_loop() {
  while true; do
    php /var/www/html/system/ajax.php cleanup >/dev/null 2>&1 || true
    sleep "${DOWNLOADER_CLEANUP_INTERVAL_SECONDS:-900}"
  done
}

cleanup_loop &
exec apache2-foreground
