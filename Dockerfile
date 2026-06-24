FROM php:8.3-apache

RUN echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf && \
    a2enconf servername

# Install only what yt-dlp needs at runtime
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        ffmpeg \
        curl && \
    curl -L "https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux" \
         -o /usr/local/bin/yt-dlp && \
    chmod a+rx /usr/local/bin/yt-dlp && \
    rm -rf /var/lib/apt/lists/*
