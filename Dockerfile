# syntax=docker/dockerfile:1
# CERMAT — image aplikasi (php-fpm) & web (nginx). Produksi non-Docker tidak terpengaruh.

# ---------- (a) aset Vite ----------
FROM node:22-bookworm-slim AS assets
WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---------- dependensi composer (tanpa dev) ----------
FROM composer:2 AS vendor
WORKDIR /src
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --ignore-platform-reqs

# ---------- (b) aplikasi php-fpm ----------
FROM php:8.4-fpm-bookworm AS app
ARG UID=1000
ARG GID=1000
ENV TZ=Asia/Jakarta

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN apt-get update \
 && apt-get install -y --no-install-recommends poppler-utils tzdata unzip \
 && install-php-extensions pdo_mysql zip intl bcmath pcntl opcache gd exif \
 && rm -rf /var/lib/apt/lists/* \
 # ftp_ssl_connect (FtpClient) wajib tersedia — gagalkan build bila tidak.
 && php -r 'exit(function_exists("ftp_ssl_connect") ? 0 : 1);' \
 && pdftotext -v && pdftoppm -v

# Jalankan PHP dengan UID/GID sama dengan pengguna host "oem" agar storage bind mount bisa ditulis.
RUN groupmod -o -g "${GID}" www-data && usermod -o -u "${UID}" -g "${GID}" www-data

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-cermat.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /src/vendor ./vendor
COPY --chown=www-data:www-data --from=assets /src/public/build ./public/build
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && php artisan package:discover --ansi \
 && chown -R www-data:www-data storage bootstrap/cache \
 && rm /usr/bin/composer

COPY docker/entrypoint.sh /usr/local/bin/cermat-entrypoint
RUN chmod +x /usr/local/bin/cermat-entrypoint
USER www-data
ENTRYPOINT ["cermat-entrypoint"]
CMD ["php-fpm"]

# ---------- web (nginx) — hanya berkas public ----------
FROM nginx:1.27-alpine AS web
ENV TZ=Asia/Jakarta
RUN apk add --no-cache tzdata
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/html/public
COPY --from=assets /src/public/build /var/www/html/public/build
