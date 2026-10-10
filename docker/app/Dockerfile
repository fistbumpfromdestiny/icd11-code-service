FROM php:8.5-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libpq-dev unzip git \
    && docker-php-ext-install zip pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The container runs as the host UID, which has no home directory in the image.
ENV HOME=/tmp \
    COMPOSER_HOME=/tmp/composer

WORKDIR /var/www/html
