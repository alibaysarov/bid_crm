FROM php:8.4-fpm-alpine

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache bash libpq libxml2 oniguruma unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers postgresql-dev libxml2-dev oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" mbstring pcntl pdo_pgsql sockets xml \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

WORKDIR /var/www/html
