# syntax=docker/dockerfile:1
# CRM Vivienda en Regla · PHP 8.4 (php-fpm) + Nginx + Supervisor en una sola imagen.
# Multi-stage: compila assets (Vite + Wayfinder) en "build" y deja la imagen final sin Node.

# ---------------------------------------------------------------- base PHP
FROM php:8.4-fpm-alpine AS base

ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN apk add --no-cache nginx supervisor curl tzdata poppler-utils \
    && install-php-extensions pdo_mysql gd zip bcmath intl exif pcntl opcache redis

WORKDIR /var/www/html

# ---------------------------------------------------------------- build (vendor + assets)
FROM base AS build

RUN apk add --no-cache nodejs npm git unzip
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    APP_ENV=production \
    CACHE_STORE=array \
    SESSION_DRIVER=array \
    QUEUE_CONNECTION=sync \
    DB_CONNECTION=sqlite \
    DB_DATABASE=:memory:

# Dependencias PHP (capa cacheable)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# Dependencias JS (capa cacheable)
COPY package.json package-lock.json ./
RUN npm ci

# Código y compilación
COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && cp .env.example .env \
    && php artisan key:generate --force \
    && php artisan package:discover --ansi \
    && php artisan wayfinder:generate --with-form \
    && npm run build \
    && rm -rf node_modules .env public/hot

# ---------------------------------------------------------------- imagen final
FROM base AS final

COPY --from=build /var/www/html /var/www/html

COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
RUN mkdir -p /tmp/nginx/client_body /tmp/nginx/fastcgi /tmp/nginx/proxy && chown -R www-data:www-data /tmp/nginx
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/zz-www.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-custom.ini
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && rm -f /usr/local/etc/php-fpm.d/www.conf /usr/local/etc/php-fpm.d/zz-docker.conf \
    && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# El puerto publicado lo decide Traefik (Dokploy): el contenedor solo expone el 80 internamente.
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --start-period=90s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
