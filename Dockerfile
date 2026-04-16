FROM php:8.2-cli-alpine AS php_base

RUN apk add --no-cache \
    git unzip libzip-dev libpng-dev oniguruma-dev \
    mysql-client icu-dev libxml2-dev $PHPIZE_DEPS

RUN pecl install redis \
    && docker-php-ext-enable redis

RUN docker-php-ext-install pdo_mysql zip exif intl opcache pcntl bcmath

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

FROM php_base AS app

COPY . .
COPY docker/entrypoint.sh /usr/local/bin/getfy-entrypoint

RUN chmod +x /usr/local/bin/getfy-entrypoint \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache .docker .docker/plugins-installed \
    && chmod -R 777 storage bootstrap/cache .docker

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/getfy-entrypoint"]
CMD ["sh", "-lc", "php artisan serve --host=0.0.0.0 --port=${PORT:-80}"]
