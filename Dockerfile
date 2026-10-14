FROM nginx:stable-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY docker/fastcgi.conf /etc/nginx/app-fastcgi.conf
COPY public/assets/ /app/public/assets/

FROM php:8.3-fpm-bookworm AS php-base
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4 libonig5 libsqlite3-0 libfcgi-bin \
        libcurl4-openssl-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-install -j"$(nproc)" curl mbstring pdo_sqlite opcache \
    && apt-get purge -y --auto-remove libcurl4-openssl-dev libonig-dev libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/* \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
WORKDIR /app

FROM php-base AS dependencies
RUN apt-get update && apt-get install -y --no-install-recommends unzip
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
COPY src/ ./src/
RUN composer install --no-dev --prefer-dist --no-interaction \
    --no-progress --no-scripts --optimize-autoloader \
    && composer check-platform-reqs --no-dev

FROM php-base AS app
COPY --from=dependencies /app/vendor/ ./vendor/
COPY composer.json composer.lock ./
COPY src/ ./src/
COPY public/ ./public/
COPY migrations/ ./migrations/
COPY bin/console.php ./bin/console.php
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY --chmod=755 docker/entrypoint.sh docker/healthcheck.sh /usr/local/bin/
RUN mkdir -p /app/var && chown www-data:www-data /app/var
ENV STORAGE_PATH=/app/var
USER www-data
EXPOSE 9000
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD ["/usr/local/bin/healthcheck.sh"]
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]
