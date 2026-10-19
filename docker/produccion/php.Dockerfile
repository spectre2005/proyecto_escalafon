# Imagen de producción del backend (php-fpm). El código va dentro de la imagen, sin dependencias de desarrollo ni .env:
# la configuración llega por variables de entorno (.env.produccion). Contexto: la raíz del repositorio.

FROM composer:2 AS dependencias
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
# Las extensiones se comprueban en la imagen final; aquí solo se descargan los paquetes.
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --ignore-platform-reqs
COPY backend/ ./
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

FROM php:8.4-fpm AS app
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libpq-dev poppler-utils \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install pdo_pgsql pgsql zip gd bcmath opcache pcntl \
 && apt-get purge -y --auto-remove && rm -rf /var/lib/apt/lists/*
COPY docker/produccion/php.ini /usr/local/etc/php/conf.d/zz-produccion.ini
COPY docker/produccion/www.conf /usr/local/etc/php-fpm.d/zz-produccion.conf
COPY docker/produccion/entrada.sh /usr/local/bin/entrada
WORKDIR /var/www/html
COPY --from=dependencias --chown=www-data:www-data /app /var/www/html
RUN mkdir -p storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && su www-data -s /bin/sh -c "php artisan package:discover --ansi"
USER www-data
ENTRYPOINT ["entrada"]
CMD ["php-fpm"]
