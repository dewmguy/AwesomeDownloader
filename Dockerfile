FROM php@sha256:973e11c67c1c81e7811077a0efa0f910cf903af0ba972cab6ba0c0e15913c771

ENV DEBIAN_FRONTEND=noninteractive
ARG YT_DLP_VERSION=2026.07.04
ARG DENO_VERSION=2.9.6
ARG DENO_SHA256=394f07f4da2bebe6ce6f1e7ce0fa16429b29b08c35e3fac3fe25972676dff4b2
ARG POT_PROVIDER_VERSION=1.3.2
ARG POT_PROVIDER_SHA256=d51cf1c54e487137df749bd8778cceaa62304e6c5054c955b95f028f93ad6d57

RUN echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf && \
    a2enconf servername

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        ffmpeg \
        curl \
        unzip; \
    if [ "$YT_DLP_VERSION" = "latest" ]; then \
      yt_dlp_url="https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux"; \
    else \
      yt_dlp_url="https://github.com/yt-dlp/yt-dlp/releases/download/$YT_DLP_VERSION/yt-dlp_linux"; \
    fi; \
    curl -fSL "$yt_dlp_url" -o /usr/local/bin/yt-dlp; \
    chmod a+rx /usr/local/bin/yt-dlp; \
    deno_archive=/tmp/deno.zip; \
    curl -fSL "https://github.com/denoland/deno/releases/download/v$DENO_VERSION/deno-x86_64-unknown-linux-gnu.zip" -o "$deno_archive"; \
    echo "$DENO_SHA256  $deno_archive" | sha256sum -c -; \
    unzip -q "$deno_archive" -d /usr/local/bin; \
    chmod a+rx /usr/local/bin/deno; \
    rm -f "$deno_archive"; \
    plugin_dir=/etc/yt-dlp/plugins; \
    plugin_archive="$plugin_dir/bgutil-ytdlp-pot-provider.zip"; \
    mkdir -p "$plugin_dir"; \
    curl -fSL "https://github.com/Brainicism/bgutil-ytdlp-pot-provider/releases/download/$POT_PROVIDER_VERSION/bgutil-ytdlp-pot-provider.zip" -o "$plugin_archive"; \
    echo "$POT_PROVIDER_SHA256  $plugin_archive" | sha256sum -c -; \
    printf '%s\n' \
      '--extractor-args youtube:player_client=mweb' \
      '--extractor-args youtubepot-bgutilhttp:base_url=http://downloader-pot-provider:4416' \
      > /etc/yt-dlp.conf; \
    apt-get purge -y --auto-remove unzip; \
    rm -rf /var/lib/apt/lists/*
