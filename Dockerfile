# ---- frontend build -------------------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app
COPY package*.json ./
RUN npm ci --ignore-scripts
COPY . .
RUN npm run build

# ---- php runtime ----------------------------------------------------------
FROM php:8.4-fpm-alpine AS php

RUN apk add --no-cache postgresql-dev icu-dev oniguruma-dev libzip-dev \
    && docker-php-ext-install pdo_pgsql pgsql intl opcache zip bcmath

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/php/zzz-pool.conf /usr/local/etc/php-fpm.d/zzz-pool.conf
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

# ---- nginx ----------------------------------------------------------------
# Built from the php stage so the web server ships the exact same public/
# directory (compiled assets included) that php-fpm executes.
FROM nginx:1.27-alpine AS web

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=php /var/www/html/public /var/www/html/public
