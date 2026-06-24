# Awesome Downloader

Awesome Downloader is a tiny self-hosted web front end for `yt-dlp`: paste a media URL, choose an output format, and let the container do the downloading and conversion work. It is intentionally simple, easy to recover, and useful from any browser on your network.

## Features

- Browser UI for `yt-dlp` downloads
- Base media download with MP4 merge support
- MP3 extraction for audio-first saves
- H264 re-encode option for device-friendly MP4 playback
- GIF conversion for short clips
- Recent downloads list with play, save, delete, and refresh controls
- Dockerized PHP/Apache runtime with `ffmpeg` and the latest `yt-dlp` release downloaded at build time

## Install

Clone the repo and start the container:

```bash
git clone git@github.com:dewmguy/AwesomeDownloader.git
cd AwesomeDownloader
docker compose up -d --build
```

Open the app locally at:

```text
http://localhost:8080
```

The current production deployment is published at:

```text
https://downloader.wabsite.tech
```

For a reverse proxy or tunnel setup, publish the container however you normally expose internal HTTP services. The app itself listens on port `80` inside the container.

## Configuration

The defaults work out of the box, but these environment variables can tune a deployment:

- `DOWNLOADER_TEMP_DIR`: temporary job and work directory, default `/var/www/html/temp`
- `DOWNLOADER_FINAL_DIR`: completed media directory, default `/var/www/html/download`
- `DOWNLOADER_GIF_MAX_SECONDS`: maximum GIF source duration, default `600`
- `DOWNLOADER_REENCODE_MAX_SECONDS`: maximum H264 re-encode source duration, default `7200`
- `DOWNLOADER_OUTPUT_TEMPLATE`: `yt-dlp` output template, default `%(title).180B [%(id)s].%(ext)s`

## Data Layout

The compose file keeps runtime data outside the image:

- `app/` is mounted at `/var/www/html`
- `download/` is mounted at `/var/www/html/download`
- `temp/` is mounted at `/var/www/html/temp`

Downloaded files live in `download/`. Temporary work files live in `temp/`. Both directories are ignored by git except for `.gitkeep` placeholders.

## Recovery

A clean recovery is intentionally boring:

1. Clone the repository on the target host.
2. Restore any saved media into `download/` if needed.
3. Run `docker compose up -d --build`.
4. Confirm the app responds with `curl -I http://localhost:8080/`.
5. Confirm runtime tools with:

```bash
docker exec apache-downloader php -v
docker exec apache-downloader yt-dlp --version
docker exec apache-downloader ffmpeg -version
```

If the container starts but downloads fail, check logs with:

```bash
docker logs apache-downloader --tail 100
```

## Current Optimization Plan

The next improvements are focused on reliability and user feedback:

- Move long downloads and encodes out of the HTTP request path into background jobs
- Give each job its own temp directory
- Add status/progress polling to the UI
- Tighten output naming to avoid filename collisions
- Skip metadata duration checks unless the selected mode needs a duration limit
- Hide or clean up partial and unsupported files
- Keep Docker and runtime configuration reproducible without leaking host-specific secrets

## Notes

The included `compose.yaml` is safe to commit and intended as the recovery source of truth. If you run this from a larger personal compose stack, keep private service tokens and unrelated services in that private stack, not in this public repo.
