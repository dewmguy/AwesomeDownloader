# syntax=docker/dockerfile:1
ARG WHISPER_MODEL=small
ARG APP_VERSION=2026.10.23
ARG APP_COMMIT=unknown
ARG APP_UPDATED_AT=2026-10-23

FROM brainicism/bgutil-ytdlp-pot-provider:1.3.2-deno@sha256:20bd6ad8561d71c628fb3d4fe35a7fcd2c9ef84eecd2bbf40f77dd0d8a8e3799 AS pot-provider

FROM scratch AS runtime-files
COPY --from=pot-provider --chown=33:33 /app /opt/pot-provider
COPY app/ /var/www/html/
# Docker creates missing parent directories with the COPY mode in a scratch
# stage, so use an executable/readable mode for both the directory and script.
COPY --chmod=0755 transcriber/server.py /opt/transcriber/server.py
COPY --chmod=0755 docker/start-downloader.sh /usr/local/bin/start-downloader
COPY --chmod=0755 docker/process-supervisor.py /usr/local/bin/process-supervisor
COPY --chmod=0755 docker/cleanup-loop.sh /usr/local/bin/cleanup-loop
COPY --chmod=0755 docker/healthcheck.sh /usr/local/bin/downloader-healthcheck

FROM php@sha256:973e11c67c1c81e7811077a0efa0f910cf903af0ba972cab6ba0c0e15913c771 AS dependencies

# Optional trust anchor for corporate and cloud HTTPS proxies. The normal build
# does not need this secret; scripts/dev passes it only when one is configured.
RUN --mount=type=secret,id=extra_ca \
    if [ -f /run/secrets/extra_ca ]; then \
      install -D -m 0644 /run/secrets/extra_ca /usr/local/share/ca-certificates/extra-ca.crt; \
      update-ca-certificates; \
    fi

ENV DEBIAN_FRONTEND=noninteractive \
    SSL_CERT_FILE=/etc/ssl/certs/ca-certificates.crt \
    REQUESTS_CA_BUNDLE=/etc/ssl/certs/ca-certificates.crt \
    CURL_CA_BUNDLE=/etc/ssl/certs/ca-certificates.crt \
    PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    PATH=/opt/transcriber-venv/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

ARG YT_DLP_VERSION=2026.07.04
ARG DENO_VERSION=2.9.6
ARG DENO_SHA256=394f07f4da2bebe6ce6f1e7ce0fa16429b29b08c35e3fac3fe25972676dff4b2
ARG POT_PROVIDER_VERSION=1.3.2
ARG POT_PROVIDER_SHA256=d51cf1c54e487137df749bd8778cceaa62304e6c5054c955b95f028f93ad6d57

COPY transcriber/requirements.txt /tmp/transcriber-requirements.txt

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
      ca-certificates \
      curl \
      ffmpeg \
      libgomp1 \
      python3 \
      python3-venv \
      unzip; \
    php -r 'new PDO("sqlite::memory:");'; \
    python3 -m venv /opt/transcriber-venv; \
    /opt/transcriber-venv/bin/pip install --no-cache-dir --requirement /tmp/transcriber-requirements.txt; \
    if [ "$YT_DLP_VERSION" = "latest" ]; then \
      yt_dlp_url="https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux"; \
    else \
      yt_dlp_url="https://github.com/yt-dlp/yt-dlp/releases/download/$YT_DLP_VERSION/yt-dlp_linux"; \
    fi; \
    curl -fSL "$yt_dlp_url" -o /usr/local/bin/yt-dlp; \
    chmod 0755 /usr/local/bin/yt-dlp; \
    deno_archive=/tmp/deno.zip; \
    curl -fSL "https://github.com/denoland/deno/releases/download/v$DENO_VERSION/deno-x86_64-unknown-linux-gnu.zip" -o "$deno_archive"; \
    echo "$DENO_SHA256  $deno_archive" | sha256sum -c -; \
    unzip -q "$deno_archive" -d /usr/local/bin; \
    chmod 0755 /usr/local/bin/deno; \
    plugin_dir=/etc/yt-dlp/plugins; \
    plugin_archive="$plugin_dir/bgutil-ytdlp-pot-provider.zip"; \
    mkdir -p "$plugin_dir"; \
    curl -fSL "https://github.com/Brainicism/bgutil-ytdlp-pot-provider/releases/download/$POT_PROVIDER_VERSION/bgutil-ytdlp-pot-provider.zip" -o "$plugin_archive"; \
    echo "$POT_PROVIDER_SHA256  $plugin_archive" | sha256sum -c -; \
    printf '%s\n' \
      '--compat-options no-certifi' \
      '--extractor-args youtube:player_client=mweb' \
      '--extractor-args youtubepot-bgutilhttp:base_url=http://127.0.0.1:4416' \
      > /etc/yt-dlp.conf; \
    rm -f "$deno_archive" /tmp/transcriber-requirements.txt; \
    apt-get purge -y --auto-remove unzip; \
    rm -rf /var/lib/apt/lists/*

RUN echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf && \
    printf '%s\n' \
      '<Directory "/data">' \
      '  Require all denied' \
      '</Directory>' \
      > /etc/apache2/conf-available/downloader-private-storage.conf && \
    a2enconf servername downloader-private-storage

FROM dependencies AS test

FROM dependencies AS whisper-model
ARG WHISPER_MODEL
ENV WHISPER_MODEL=${WHISPER_MODEL} \
    WHISPER_COMPUTE_TYPE=int8 \
    WHISPER_THREADS=3 \
    WHISPER_MODEL_CACHE=/models \
    TRANSCRIBER_WORK_ROOT=/data/temp/work \
    TRANSCRIBER_PORT=8000

RUN python -c "import os; from faster_whisper import WhisperModel; WhisperModel(os.environ['WHISPER_MODEL'], device='cpu', compute_type=os.environ['WHISPER_COMPUTE_TYPE'], download_root=os.environ['WHISPER_MODEL_CACHE'])" && \
    chown -R www-data:www-data /models

FROM whisper-model AS runtime

ARG APP_VERSION
ARG APP_COMMIT
ARG APP_UPDATED_AT

COPY --from=runtime-files / /

# Normalize web permissions so image behavior does not depend on the checkout's
# umask or executable bits. Apache only needs directories traversable and files
# readable; runtime data remains isolated under /data.
RUN find /var/www/html -type d -exec chmod 0755 {} + && \
    find /var/www/html -type f -exec chmod 0644 {} +

ENV APP_VERSION=${APP_VERSION} \
    APP_COMMIT=${APP_COMMIT} \
    APP_UPDATED_AT=${APP_UPDATED_AT} \
    DOWNLOADER_DATA_DIR=/data \
    DOWNLOADER_DATABASE_PATH=/data/app.sqlite \
    DOWNLOADER_TEMP_DIR=/data/temp \
    DOWNLOADER_FINAL_DIR=/data/downloads \
    DOWNLOADER_TRANSCRIBER_URL=http://127.0.0.1:8000 \
    DOWNLOADER_TRANSCRIBER_SHARED_TEMP_DIR=/data/temp

VOLUME ["/data"]
EXPOSE 80

HEALTHCHECK --interval=15s --timeout=8s --start-period=240s --retries=3 \
  CMD ["downloader-healthcheck"]

CMD ["start-downloader"]
