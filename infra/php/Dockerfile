# syntax=docker/dockerfile:1
ARG NGINX_IMAGE=nginx:stable-alpine
FROM php:8.5-fpm-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip libicu-dev libzip-dev libonig-dev libsqlite3-dev libfcgi-bin gosu \
    && docker-php-ext-install -j"$(nproc)" bcmath intl mbstring pcntl pdo_mysql pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY infra/php/production.ini /usr/local/etc/php/conf.d/zz-techpoint.ini
COPY infra/php/pool.conf /usr/local/etc/php-fpm.d/zz-techpoint.conf

FROM base AS dev
COPY infra/php/dev-entrypoint.sh /usr/local/bin/techpoint-dev-entrypoint
RUN chmod 755 /usr/local/bin/techpoint-dev-entrypoint
ENTRYPOINT ["techpoint-dev-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

FROM node:24-bookworm-slim AS node_runtime

FROM base AS assets
COPY --from=node_runtime /usr/local/bin/node /usr/local/bin/node
COPY --from=node_runtime /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
COPY composer.json composer.lock ./
RUN composer install --no-scripts --prefer-dist --no-interaction
COPY package*.json ./
RUN npm ci
COPY . .
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && APP_ENV=testing CACHE_STORE=array SESSION_DRIVER=array php artisan package:discover --no-ansi \
    && APP_ENV=testing CACHE_STORE=array SESSION_DRIVER=array php artisan techpoint:routes \
    && APP_ENV=testing CACHE_STORE=array SESSION_DRIVER=array npm run build

FROM base AS app
ARG APP_BUILD_REVISION=unverified
LABEL org.opencontainers.image.title="TechPoint Business OS" org.opencontainers.image.revision="${APP_BUILD_REVISION}"
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction
COPY . .
COPY --from=assets /var/www/html/public/build ./public/build
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
COPY infra/php/entrypoint.sh /usr/local/bin/techpoint-entrypoint
RUN chmod 755 /usr/local/bin/techpoint-entrypoint
ENTRYPOINT ["techpoint-entrypoint"]
CMD ["php-fpm", "-F"]

FROM ${NGINX_IMAGE} AS web
ARG APP_BUILD_REVISION=unverified
LABEL org.opencontainers.image.title="TechPoint Business OS web" org.opencontainers.image.revision="${APP_BUILD_REVISION}"
COPY --from=app /var/www/html/public /var/www/html/public
COPY infra/nginx/logging.conf /etc/nginx/conf.d/00-logging.conf
