FROM php:8.4-fpm AS runtime

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libpq-dev libsqlite3-dev \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install pdo_pgsql pdo_sqlite pgsql zip gd bcmath \
 && php -r 'exit(function_exists("imagejpeg") && extension_loaded("pdo_sqlite") ? 0 : 1);' \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

RUN mkdir -p /var/www/html/vendor /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions /var/www/html/storage/framework/views \
    /var/www/html/storage/framework/testing /var/www/html/storage/logs \
 && chown -R 1000:1000 /var/www/html/vendor /var/www/html/storage

FROM runtime AS production
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY --chown=1000:1000 backend/ /var/www/html/
RUN composer dump-autoload --no-dev --no-interaction --optimize --no-scripts \
 && php artisan package:discover --no-interaction \
 && chown -R 1000:1000 /var/www/html/bootstrap/cache /var/www/html/storage
USER 1000:1000
