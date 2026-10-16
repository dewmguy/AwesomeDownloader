# Development

Docker is the supported development and test environment. This keeps PHP,
SQLite, Python, Faster-Whisper, FFmpeg, Deno, and `yt-dlp` consistent with the
deployed image.

## First start

```bash
./scripts/dev up
```

This builds one production image, starts one container, waits until Apache,
the token provider, and Whisper are all ready, and then verifies the SQLite
schema. There are no source or data bind mounts to prepare.

The default build downloads the Whisper `small` model. To use another model:

```bash
WHISPER_MODEL=base ./scripts/dev up
```

## Tests

```bash
./scripts/dev test
```

The command builds the `test` stage and runs:

- `tests/frontend_test.php`
- `tests/backend_test.php`
- `tests/test_transcriber.py`

The backend suite uses a temporary SQLite database and covers queue validation,
SSRF protections, validation retry/cache behavior, ownership, publication,
retention, and filesystem cleanup. The Python suite covers transcript output
and work-directory confinement. The test stage omits the Whisper model so CI
does not depend on Hugging Face model storage.

## Day-to-day commands

```text
./scripts/dev build    build the complete image
./scripts/dev up       build, start, wait, and smoke-test
./scripts/dev start    start an already-built image and smoke-test
./scripts/dev test     run all tests in isolated containers
./scripts/dev smoke    check every internal service and SQLite
./scripts/dev status   show container health
./scripts/dev logs     follow combined process logs
./scripts/dev down     stop the stack without deleting data
```

Application source is copied into the image. Rebuild after changing PHP,
HTML, CSS, Python, or container scripts. This mirrors production and prevents
development from accidentally relying on host files that will not exist after
deployment.

## Runtime layout

The container entrypoint prepares `/data`, then the supervisor starts four
required processes:

1. BgUtils token provider on internal port 4416
2. Faster-Whisper on internal port 8000
3. the retention cleanup loop
4. Apache on published port 80

If any required process exits, the supervisor stops the remaining processes so
Docker can restart a coherent application. The health check tests all three
network services; a running Apache process alone is not considered healthy.

SQLite uses WAL mode and a busy timeout so Apache requests, the queue worker,
and cleanup can safely share the database. Media remains on the filesystem
because large binary files do not belong in SQLite.

## Proxies and custom certificate authorities

For a trusted HTTPS-intercepting proxy, pass its PEM certificate without
disabling verification:

```bash
EXTRA_CA_CERT=/path/to/proxy-ca.pem ./scripts/dev build
```

The helper also recognizes `CODEX_PROXY_CERT` and `NODE_EXTRA_CA_CERTS`. The
certificate is supplied to BuildKit as a secret and installed in the image
trust store; the source file is not copied into the build context.

## Debugging

```bash
./scripts/dev status
./scripts/dev logs
docker compose exec downloader php -v
docker compose exec downloader yt-dlp --version
docker compose exec downloader deno --version
docker compose exec downloader python --version
docker compose exec downloader curl -fsS http://127.0.0.1:8000/health
docker compose exec downloader curl -fsS http://127.0.0.1:4416/ping
```

Source platforms may behave differently from residential browsers and can
rate-limit cloud IPs. Reproduce extractor failures from the target deployment
before treating a cloud-environment 403 as an application regression.
