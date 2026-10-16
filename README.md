# Awesome Downloader

Awesome Downloader is a self-hosted web interface for downloading, converting,
and transcribing media with `yt-dlp`, FFmpeg, and Faster-Whisper.

The default deployment is deliberately self-contained: one Docker image, one
container, one HTTP port, and one Docker-managed data volume. It does not use
host-path bind mounts or require a separate database, token-provider, or
transcription container.

## What is included

- Apache and PHP for the web application
- `yt-dlp`, FFmpeg, and Deno
- the BgUtils Proof-of-Origin provider used by current YouTube extractors
- Faster-Whisper running locally on CPU
- SQLite for jobs, file metadata, and URL-validation cache
- a small supervisor that keeps all required processes together
- original, MP3, H264 MP4, GIF, and timestamped transcript outputs
- multi-URL queues, playlist limits, anonymous private files, and opt-in public
  sharing
- automatic deletion of application-managed content after 72 hours
- server-side URL and DNS checks that reject private network targets
- bounded, success-only URL-validation caching to make transient site failures
  immediately retryable

## Requirements

- A Linux `amd64` host, or an `amd64` Docker environment
- a current Docker Engine release
- Docker Compose v2 (`docker compose`, not the legacy `docker-compose` command)
- approximately 4 GB of available RAM for the default Whisper `small` model
- enough Docker storage for the roughly 2.2 GB model-inclusive image and
  temporary media-processing files
- outbound HTTPS access while building the image

You do not need PHP, Python, FFmpeg, yt-dlp, Deno, SQLite, or a separate
database installed on the host.

## Installation

Clone the repository, run the isolated test suite, and start the app:

```bash
git clone https://github.com/dewmguy/AwesomeDownloader.git
cd AwesomeDownloader
./scripts/dev test
./scripts/dev up
```

Open `http://localhost:8080`. Use `DOWNLOADER_PORT=8090 ./scripts/dev up`
to select a different host port. These values can also be persisted in an
optional `.env` file beside `compose.yaml`:

```dotenv
DOWNLOADER_PORT=8080
WHISPER_MODEL=small
```

The first build downloads the Whisper model and can take several minutes.
The `up` helper builds the image, starts Compose, waits for the complete health
check, and verifies the web app, token provider, Whisper service, and SQLite
schema. Later starts reuse the built image and are much faster:

```bash
./scripts/dev start
```

To use Docker Compose directly instead of the helper:

```bash
docker compose build
docker compose up -d --wait --wait-timeout 360
./scripts/dev smoke
```

## Docker Compose configuration

The repository includes the following complete `compose.yaml`. It is shown
here so the production topology and persistence model are explicit and so it
can be copied into Compose-oriented deployment tools:

```yaml
services:
  downloader:
    build:
      context: .
      args:
        WHISPER_MODEL: ${WHISPER_MODEL:-small}
    image: awesome-downloader:latest
    container_name: awesome-downloader
    ports:
      - "${DOWNLOADER_PORT:-8080}:80"
    environment:
      DOWNLOADER_GIF_MAX_SECONDS: 600
      DOWNLOADER_REENCODE_MAX_SECONDS: 7200
      DOWNLOADER_MAX_BATCH_ITEMS: 20
      DOWNLOADER_PLAYLIST_MAX_ITEMS: 50
      DOWNLOADER_URL_VALIDATION_CONCURRENCY: 3
      DOWNLOADER_CLEANUP_INTERVAL_SECONDS: 900
      WHISPER_MODEL: ${WHISPER_MODEL:-small}
      WHISPER_COMPUTE_TYPE: int8
      WHISPER_THREADS: 3
    volumes:
      - downloader-data:/data
    cpus: 4
    mem_limit: 4g
    pids_limit: 512
    security_opt:
      - no-new-privileges:true
    healthcheck:
      test: ["CMD", "downloader-healthcheck"]
      interval: 15s
      timeout: 8s
      retries: 3
      start_period: 240s
    stop_grace_period: 30s
    restart: unless-stopped

volumes:
  downloader-data:
```

Only port 80 from the application container is published, on host port 8080
by default. Internal ports 4416 and 8000 are used by the bundled token provider
and Whisper service and are not published by Compose.

## Architecture and storage

Compose starts only `awesome-downloader`. Inside it, the process supervisor
runs Apache, the local BgUtils token provider, the local Faster-Whisper server,
and retention cleanup. Only Apache's port 80 is published.

The `downloader-data` named volume is mounted at `/data`:

| Path | Purpose |
| --- | --- |
| `/data/app.sqlite` | jobs, file metadata, and validation cache |
| `/data/downloads` | completed media awaiting expiry or user download |
| `/data/temp/work` | in-progress downloads and conversions |
| `/data/locks` | queue, cleanup, and validation coordination |

A named volume still occupies disk on the Docker host, normally inside
Docker's private data directory. The important distinction is that this app no
longer depends on a server-specific path such as `/opt/html/downloader`; Docker
owns the volume location and reconnects it when the container is replaced.

Whisper inference happens locally in the application container. Hugging Face
is contacted only during image build to download the selected public model,
which is then baked into the image. Runtime transcription is not sent to a
Hugging Face API.

## Common commands

```bash
./scripts/dev up       # build, start, wait for health, and smoke-test
./scripts/dev test     # run all PHP and Python tests in Docker
./scripts/dev smoke    # check Apache, token provider, Whisper, and SQLite
./scripts/dev status   # show container health
./scripts/dev logs     # follow logs from all supervised processes
./scripts/dev down     # stop the stack; keep the named volume
```

`docker compose down -v` also deletes the named volume and all application
data. Use that only when a permanent reset is intentional.

## Production deployment and upgrades

For a public installation, place a TLS-terminating reverse proxy or tunnel in
front of port 8080. If the proxy runs directly on the Docker host, the port can
be restricted to loopback with this Compose override:

```yaml
services:
  downloader:
    ports:
      - "127.0.0.1:${DOWNLOADER_PORT:-8080}:80"
```

Forward the original host and protocol headers, restrict direct origin access,
and allow sufficiently long upstream timeouts for media processing. The app
does not provide user accounts or quotas, so an authentication-aware gateway
is recommended for an untrusted public audience.

Before an upgrade, record the deployed commit and take a volume backup. Then:

```bash
git pull --ff-only
./scripts/dev test
./scripts/dev up
```

The image contains the application source, so code changes require a rebuild.
The named volume is automatically reattached to the replacement container.

The previous multi-container, bind-mounted release is not migrated
automatically. A clean cutover creates an empty SQLite database and named
volume; preserve any old media separately before stopping the legacy stack.
The complete cutover and rollback procedure is in
[docs/deployment.md](docs/deployment.md).

## Data lifecycle, backup, and reset

Application-managed media is intentionally temporary and is deleted after 72
hours. A user can publish a private file, which restarts its 72-hour retention
period as public media. The named volume survives container rebuilds,
`docker compose down`, and host restarts, but it is not a permanent media
archive.

Create a consistent backup while the service is stopped:

```bash
docker compose stop downloader
docker compose run --rm -T downloader tar -C /data -czf - . > awesome-downloader-data.tar.gz
docker compose start downloader
```

Backups can contain source URLs, anonymous ownership identifiers, and media,
so store them as sensitive data. Restore and rollback commands are documented
in [docs/deployment.md](docs/deployment.md).

To stop the application while keeping all data:

```bash
./scripts/dev down
```

To permanently remove the application and its named volume:

```bash
docker compose down -v
```

The second command is irreversible without a backup.

## Configuration

Set these in `compose.yaml` or in a Compose override:

| Variable | Default | Purpose |
| --- | --- | --- |
| `DOWNLOADER_PORT` | `8080` | host port mapped to the container |
| `WHISPER_MODEL` | `small` | model baked into the image and used at runtime |
| `WHISPER_COMPUTE_TYPE` | `int8` | CPU inference quantization |
| `WHISPER_THREADS` | `3` | transcription CPU threads |
| `DOWNLOADER_GIF_MAX_SECONDS` | `600` | longest accepted GIF source |
| `DOWNLOADER_REENCODE_MAX_SECONDS` | `7200` | longest H264 re-encode source |
| `DOWNLOADER_MAX_BATCH_ITEMS` | `20` | URLs allowed in one batch |
| `DOWNLOADER_PLAYLIST_MAX_ITEMS` | `50` | items allowed from one playlist |
| `DOWNLOADER_URL_VALIDATION_TIMEOUT` | `25` | seconds allowed for metadata validation |
| `DOWNLOADER_URL_VALIDATION_CONCURRENCY` | `3` | simultaneous validation requests |
| `DOWNLOADER_URL_VALIDATION_CACHE_SECONDS` | `600` | successful-validation cache lifetime |
| `DOWNLOADER_CLEANUP_INTERVAL_SECONDS` | `900` | retention cleanup interval |
| `DOWNLOADER_TRANSCRIPTION_TIMEOUT` | `14400` | maximum seconds for one transcription |
| `DOWNLOADER_OUTPUT_TEMPLATE` | yt-dlp title/id template | completed-file naming template |

Choose a smaller model during the build if the host is constrained:

```bash
WHISPER_MODEL=base ./scripts/dev up
```

The model name is both a build argument and a runtime setting. Rebuild the
image after changing it.

## Development and deployment

The test target installs every runtime dependency but skips the large model
download. Tests run with networking disabled after the image is built.

See [DEVELOPMENT.md](DEVELOPMENT.md) for the development workflow and
[docs/deployment.md](docs/deployment.md) for production cutover, backup,
restore, reverse-proxy, and rollback instructions.

## Troubleshooting

Inspect container state and combined logs first:

```bash
./scripts/dev status
./scripts/dev logs
```

Run the complete internal health check without rebuilding:

```bash
./scripts/dev smoke
```

If initial startup remains in `starting`, allow several minutes for Whisper to
load and inspect the logs. If builds fail, confirm that Docker has sufficient
disk space and can reach Docker Hub, Debian repositories, GitHub, PyPI, and
Hugging Face. A custom trusted proxy CA can be supplied with
`EXTRA_CA_CERT=/path/to/ca.pem ./scripts/dev build`.

Some source sites reject datacenter IPs or require signed-in browser cookies.
An authentication-required response from Reddit or YouTube can therefore be a
source policy rather than a container failure. Failed and transient URL
validations are not cached, so they can be retried immediately.

## Security and expected limits

Private files are isolated with a random HttpOnly browser cookie; this is not
login-based authentication or a quota system. Put an authentication-aware
gateway or network restriction in front of the app when deploying it to an
untrusted public audience.

Source sites can rate-limit or reject datacenter traffic. The app reports
authentication requirements and transient network failures separately and
does not cache failed validations, but it cannot override a source site's
access policy. Only download media you have the right to access and store.

## License

See [LICENSE](LICENSE).
