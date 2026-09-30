FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libpng-dev libpq-dev \
 && docker-php-ext-install pdo_pgsql pgsql zip gd bcmath \
 && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

RUN mkdir -p /var/www/html/vendor && chown -R 1000:1000 /var/www/html/vendor
