# syntax=docker/dockerfile:1.7
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm install --no-audit --no-fund
COPY resources ./resources
COPY vite.config.js ./
COPY public ./public
RUN npm run build

FROM php:8.3-fpm-bookworm AS runtime
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libpq-dev libzip-dev libonig-dev libxml2-dev unzip ca-certificates curl \
 && docker-php-ext-install -j$(nproc) pdo_pgsql pgsql intl bcmath pcntl zip mbstring dom xml xmlwriter \
 && pecl install redis && docker-php-ext-enable redis \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/html
COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
RUN mkdir -p storage/app/private/dataset storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R ug+rwX storage bootstrap/cache
USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]

FROM nginx:1.27-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY public /var/www/html/public
COPY --from=assets /app/public/build /var/www/html/public/build
EXPOSE 80
