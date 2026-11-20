# Shared image for the `php` (php-fpm) and `worker` (PHP CLI) services.
FROM php:8.5-fpm-alpine

RUN apk add --no-cache icu-libs git unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev \
    && docker-php-ext-install -j"$(nproc)" intl pdo_mysql pcntl \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# The containers run as the checkout owner's UID, which has no home directory in the image.
ENV COMPOSER_HOME=/tmp/composer

WORKDIR /var/www
