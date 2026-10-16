#!/bin/sh
set -eu

curl --fail --silent --show-error --max-time 3 http://127.0.0.1/ >/dev/null
curl --fail --silent --show-error --max-time 3 http://127.0.0.1:4416/ping >/dev/null
curl --fail --silent --show-error --max-time 3 http://127.0.0.1:8000/health >/dev/null
