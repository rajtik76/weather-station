# Composer dependencies. This stage runs first because resources/css/app.css
# imports Flux's stylesheet out of vendor/, so the asset build needs it.
FROM php:8.4-cli-alpine AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction

COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# Frontend assets.
FROM node:24-alpine AS assets

WORKDIR /app

# package.json's prepare script runs "vp config", which reads the vite config
# and shells out to git, so both have to be in place before npm ci.
RUN apk add --no-cache git

COPY package.json package-lock.json vite.config.js .node-version ./
COPY .vite-hooks ./.vite-hooks
RUN npm ci

COPY --from=vendor /app/vendor ./vendor
COPY . .
RUN npm run build

# Runtime. Only the built application lands here - no composer, no npm, no
# compilers. That is the whole point of the split above.
FROM php:8.4-fpm-alpine AS run

RUN apk add --no-cache nginx supervisor libpq \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libpq-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql opcache \
    && apk del .build-deps

COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /app

COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# php-fpm runs as www-data and needs to write logs, caches and sessions. The
# bootstrap caches are dropped so the entrypoint rebuilds them for this image
# rather than inheriting whatever the build host had lying around.
RUN rm -f bootstrap/cache/*.php \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD wget -qO- http://127.0.0.1:8080/up >/dev/null 2>&1 || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
