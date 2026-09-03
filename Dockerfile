FROM php@sha256:973e11c67c1c81e7811077a0efa0f910cf903af0ba972cab6ba0c0e15913c771

ENV DEBIAN_FRONTEND=noninteractive
ARG YT_DLP_VERSION=2026.07.04

RUN echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf && \
    a2enconf servername

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        ffmpeg \
        curl; \
    if [ "$YT_DLP_VERSION" = "latest" ]; then \
      yt_dlp_url="https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux"; \
    else \
      yt_dlp_url="https://github.com/yt-dlp/yt-dlp/releases/download/$YT_DLP_VERSION/yt-dlp_linux"; \
    fi; \
    curl -L "$yt_dlp_url" -o /usr/local/bin/yt-dlp; \
    chmod a+rx /usr/local/bin/yt-dlp; \
    rm -rf /var/lib/apt/lists/*
