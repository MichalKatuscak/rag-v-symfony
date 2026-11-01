FROM php:8.4-cli

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev libicu-dev unzip git \
 && docker-php-ext-install pdo_pgsql intl pcntl \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
