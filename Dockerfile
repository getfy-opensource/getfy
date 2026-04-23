# Estágio 1: compila extensões PHP (toolchain pesada); não vai para a imagem final.
FROM php:8.2-fpm-alpine AS php_extensions_builder

RUN apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS \
    libzip-dev libpng-dev oniguruma-dev icu-dev libxml2-dev \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql zip exif intl opcache pcntl bcmath \
    && mkdir -p /export-inis \
    && cp /usr/local/etc/php/conf.d/docker-php-ext-*.ini /export-inis/ \
    && apk del .build-deps \
    && rm -rf /tmp/pear /var/cache/apk/*

# Estágio 2: runtime — só nginx/supervisor + libs runtime (sem gcc).
# COPY do builder ANTES do apk: força o builder a terminar primeiro (evita dois apk em paralelo no BuildKit).
FROM php:8.2-fpm-alpine AS php_runtime

COPY --from=php_extensions_builder \
    /usr/local/lib/php/extensions/no-debug-non-zts-20220829/ \
    /usr/local/lib/php/extensions/no-debug-non-zts-20220829/

COPY --from=php_extensions_builder /export-inis/ /usr/local/etc/php/conf.d/

RUN apk add --no-cache \
    nginx supervisor curl \
    git unzip mysql-client \
    libzip libpng oniguruma icu-libs icu-data-en libxml2

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

FROM php_runtime AS app

COPY docker/php/conf.d/99-getfy-uploads.ini /usr/local/etc/php/conf.d/99-getfy-uploads.ini
COPY docker/php-fpm.d/zz-getfy.conf /usr/local/etc/php-fpm.d/zz-getfy.conf
COPY docker/nginx/getfy.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY . .
COPY docker/entrypoint.sh /usr/local/bin/getfy-entrypoint

RUN chmod +x /usr/local/bin/getfy-entrypoint \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache .docker .docker/plugins-installed \
    && mkdir -p /run/nginx \
    && chmod -R 777 storage bootstrap/cache .docker

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/getfy-entrypoint"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisord.conf"]
