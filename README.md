# Awesome Downloader

Awesome Downloader is a self-hosted web interface for downloading and converting media with [`yt-dlp`](https://github.com/yt-dlp/yt-dlp). Paste a supported media URL, choose an output format, and download the finished file from your browser.

The app runs in Docker and includes PHP, Apache, `yt-dlp`, and `ffmpeg`. Runtime files are stored in mounted directories so the container can be rebuilt or replaced without losing completed downloads.

## Features

- Simple browser-based download form
- Background job processing for long downloads and conversions
- Recent downloads list with play, save, delete, and refresh actions
- Supported output modes:
  - Original media download
  - MP3 audio extraction
  - MP4/H264 re-encode
  - GIF conversion for short clips
  - WebM playback and listing
- Per-job temporary work directories
- Docker image with `ffmpeg` and `yt-dlp` installed at build time
- Configurable runtime paths, duration limits, and output naming

## Quick Start

Clone the repository and start the container:

```bash
git clone git@github.com:dewmguy/AwesomeDownloader.git
cd AwesomeDownloader
docker compose up -d --build
```

Open the app:

```text
http://localhost:8080
```

The included `compose.yaml` maps the app to port `8080` on the host. The container itself listens on port `80`, so it can also sit behind a reverse proxy, Cloudflare Tunnel, Tailscale Funnel, Pangolin, Nginx Proxy Manager, or another HTTP gateway.

## Requirements

- Docker
- Docker Compose v2
- Internet access during image build so the image can install packages and download `yt-dlp`

## Configuration

The default configuration works for local use. These environment variables can be set in `compose.yaml` or your own compose stack:

| Variable | Default | Purpose |
| --- | --- | --- |
| `DOWNLOADER_TEMP_DIR` | `/var/www/html/temp` | Temporary job metadata and work files |
| `DOWNLOADER_FINAL_DIR` | `/var/www/html/download` | Completed downloads |
| `DOWNLOADER_GIF_MAX_SECONDS` | `600` | Maximum source duration for GIF conversion |
| `DOWNLOADER_REENCODE_MAX_SECONDS` | `7200` | Maximum source duration for H264 re-encode |
| `DOWNLOADER_OUTPUT_TEMPLATE` | `%(title).180B [%(id)s].%(ext)s` | `yt-dlp` output filename template |

Example:

```yaml
services:
  apache-downloader:
    environment:
      DOWNLOADER_GIF_MAX_SECONDS: 300
      DOWNLOADER_REENCODE_MAX_SECONDS: 3600
```

## Data Directories

The default compose file uses three project directories:

| Host path | Container path | Purpose |
| --- | --- | --- |
| `./app` | `/var/www/html` | Web application files |
| `./download` | `/var/www/html/download` | Completed downloads |
| `./temp` | `/var/www/html/temp` | Job status and temporary work files |

`download/` and `temp/` are ignored by git except for `.gitkeep` placeholders. Back up `download/` if you want to preserve completed media.

## Updating

Pull the latest code and rebuild the container:

```bash
git pull
docker compose up -d --build
```

If you use a custom compose file, keep the same mounted paths or set `DOWNLOADER_TEMP_DIR` and `DOWNLOADER_FINAL_DIR` to match your layout.

## Backup and Restore

To back up a deployment, keep a copy of:

- `download/` for completed media
- any custom `compose.yaml` changes
- any reverse proxy or tunnel configuration outside this repository

To restore:

1. Clone the repository on the new host.
2. Restore `download/` if you have saved media.
3. Apply any custom compose or proxy settings.
4. Run `docker compose up -d --build`.
5. Open `http://localhost:8080` or your configured public URL.

## Troubleshooting

Check whether the container is running:

```bash
docker ps --filter name=apache-downloader
```

Check application logs:

```bash
docker logs apache-downloader --tail 100
```

Confirm the runtime tools are available:

```bash
docker exec apache-downloader php -v
docker exec apache-downloader yt-dlp --version
docker exec apache-downloader ffmpeg -version
```

Confirm the app responds locally:

```bash
curl -I http://localhost:8080/
```

If the page loads but downloads fail, verify that the mounted `temp/` and `download/` directories are writable by the container. For bind mounts, the Apache process in the container runs as `www-data`.

## Security Notes

This project is intended for personal or trusted-user deployments. It does not include authentication, rate limiting, account management, quota controls, or multi-user isolation. Put it behind an access-controlled proxy or private network if it is reachable from the internet.

Only download media that you have the right to access and store. Site support, format availability, and download behavior depend on `yt-dlp` and the source platform.

## License

See [LICENSE](LICENSE).
