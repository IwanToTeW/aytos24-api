FROM php:8.4-fpm-alpine

ARG UID=1000
ARG GID=1000

RUN apk add --no-cache icu-dev libzip-dev oniguruma-dev git unzip \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip bcmath pcntl opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

# Match the host user's UID/GID so files created in the container stay editable
RUN apk add --no-cache shadow \
    && usermod -u "${UID}" www-data \
    && groupmod -g "${GID}" www-data

WORKDIR /var/www/html
USER www-data
