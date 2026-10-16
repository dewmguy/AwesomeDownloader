# Deployment

## Build and start

```bash
git clone https://github.com/dewmguy/AwesomeDownloader.git
cd AwesomeDownloader
./scripts/dev test
./scripts/dev up
```

The production topology is one `awesome-downloader` container with one named
volume. Only `${DOWNLOADER_PORT:-8080}` is published. The token provider and
Whisper use internal, unpublished ports and are not host-accessible.

Place a TLS-terminating reverse proxy in front of the published port. Forward
the original host and protocol headers, restrict direct origin access when
appropriate, and choose request and upstream timeouts that allow large media
processing jobs.

## Clean cutover from the old multi-container deployment

The SQLite release intentionally does not import the old JSON job metadata or
old bind-mounted temporary files. For a clean replacement:

1. Record the old deployment directory, Compose file, environment settings,
   reverse-proxy target, and current Git commit.
2. Save any completed media that must outlive the normal 72-hour retention
   period.
3. Stop the old stack with its existing Compose file. Do not add `-v` during
   this step; retaining old volumes and bind-mounted directories keeps rollback
   possible.
4. Deploy this repository revision and run `./scripts/dev test`.
5. Run `./scripts/dev up` and confirm `./scripts/dev status` and
   `./scripts/dev smoke` pass locally.
6. Point the reverse proxy at the new published port and verify the public
   site.
7. Archive or delete the old deployment files only after the new service has
   been accepted.

The new container creates an empty SQLite database and a new Docker-managed
volume automatically. No `/opt/html/downloader` or other host application path
is mounted into it.

## Data ownership and lifecycle

Docker stores the `downloader-data` named volume in its own host storage area.
The exact host path is an implementation detail; inspect it with:

```bash
docker volume inspect awesomedownloader_downloader-data
```

The Compose project name can change the volume's actual name. `docker compose
config` shows the resolved deployment.

Application-managed files expire after 72 hours. The volume is durable across
container rebuilds, `docker compose down`, and host restarts, but it is not a
long-term media archive.

## Backup and restore

Create a consistent application backup while the stack is stopped:

```bash
docker compose stop downloader
docker compose run --rm -T downloader tar -C /data -czf - . > awesome-downloader-data.tar.gz
docker compose start downloader
```

Restore into an empty named volume:

```bash
docker compose down
docker compose run --rm -T downloader tar -C /data -xzf - < awesome-downloader-data.tar.gz
docker compose up -d --no-build --wait
./scripts/dev smoke
```

Treat backups as sensitive: they can contain downloaded media, job source URLs,
and anonymous ownership identifiers.

To permanently erase the current deployment's application data:

```bash
docker compose down -v
```

That command cannot be undone without a backup.

## Capacity

The default Compose limits the container to four CPUs, 4 GB RAM, and 512
processes. Faster-Whisper uses three CPU threads. Downloads and FFmpeg share
the remaining capacity, while the application queue processes jobs
sequentially. Adjust the limits only after measuring the target host.

Temporary disk use can be several times larger than the final output during
conversion. Monitor the Docker data filesystem, not an obsolete bind-mount
path.

## Build egress

Image builds require access to:

- Docker Hub for the pinned PHP and BgUtils provider images
- Debian package repositories
- GitHub release assets for `yt-dlp`, Deno, and the provider plugin
- PyPI for Faster-Whisper dependencies
- Hugging Face and its redirected model-storage hosts

The selected Whisper model is downloaded during the build. Runtime
transcription is entirely local and requires no Hugging Face token or API.

For a trusted intercepting proxy, use
`EXTRA_CA_CERT=/path/to/ca.pem ./scripts/dev build`. TLS and artifact checksum
verification remain enabled.

## Health and logs

```bash
./scripts/dev status
./scripts/dev smoke
./scripts/dev logs
```

Health requires the web page, token provider, Whisper server, and model to be
ready. Initial health can take a few minutes while the model loads.

## Upgrade and rollback

Before an upgrade, record the deployed commit and take a volume backup. Then:

```bash
git pull --ff-only
./scripts/dev test
./scripts/dev up
```

To roll back application code, check out the previous commit and rebuild. Do
not restore an older database backup unless the release notes identify a
database incompatibility. The initial SQLite schema is created idempotently on
startup, and the named volume remains attached across image replacement.
