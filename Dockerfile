FROM php:8.2-cli-alpine AS php_base

RUN apk add --no-cache \
    git unzip libzip-dev libpng-dev oniguruma-dev \
    mysql-client postgresql-client postgresql-dev icu-dev libxml2-dev \
    freetype-dev libjpeg-turbo-dev $PHPIZE_DEPS

RUN docker-php-ext-configure gd --with-freetype --with-jpeg

RUN pecl install redis \
    && docker-php-ext-enable redis

RUN docker-php-ext-install pdo_mysql pdo_pgsql zip exif intl opcache pcntl bcmath gd

RUN git config --global --add safe.directory /var/www/html

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ---------- Build frontend assets ----------
FROM node:20-alpine AS frontend

WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci
COPY vite.config.js ./
COPY resources/ ./resources/
COPY public/ ./public/
RUN npm run build

# ---------- Final app image ----------
FROM php_base AS app

COPY . .
COPY --from=frontend /app/public/build public/build
COPY docker/entrypoint.sh /usr/local/bin/getfy-entrypoint

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

RUN chmod +x /usr/local/bin/getfy-entrypoint \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache .docker \
    && chmod -R 777 storage bootstrap/cache .docker \
    && git config --global --add safe.directory /var/www/html

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/getfy-entrypoint"]
CMD ["sh", "-lc", "php artisan serve --host=0.0.0.0 --port=${PORT:-80}"]
