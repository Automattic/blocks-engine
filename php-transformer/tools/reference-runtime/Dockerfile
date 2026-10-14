# The PHP runtime WordPress.com serves Blocks Engine from: Debian 13 with
# PHP 8.4 and the distribution's libxml2 (2.9.14). DOMDocument serialization
# follows the linked libxml2, so recorded outputs are checked against this one.
FROM debian:trixie-slim
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        php8.4-cli php8.4-mbstring php8.4-xml php8.4-intl php8.4-curl php8.4-gd php8.4-zip \
        composer git unzip zip nodejs ca-certificates \
    && rm -rf /var/lib/apt/lists/*
