#!/bin/sh
set -eu

data_dir=${DOWNLOADER_DATA_DIR:-/data}
temp_dir=${DOWNLOADER_TEMP_DIR:-$data_dir/temp}
final_dir=${DOWNLOADER_FINAL_DIR:-$data_dir/downloads}
database_path=${DOWNLOADER_DATABASE_PATH:-$data_dir/app.sqlite}

for runtime_dir in \
  "$data_dir" \
  "$temp_dir" \
  "$temp_dir/work" \
  "$final_dir" \
  "$data_dir/locks" \
  "$data_dir/locks/url-validation"; do
  mkdir -p "$runtime_dir"
  chown www-data:www-data "$runtime_dir"
  chmod 0775 "$runtime_dir"
done

touch "$database_path"
chown www-data:www-data "$database_path"
chmod 0660 "$database_path"

exec python3 /usr/local/bin/process-supervisor
