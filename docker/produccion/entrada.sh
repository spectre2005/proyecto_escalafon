#!/bin/sh
# Arranque de los contenedores de producción (app y worker). No arranca si la configuración no es segura para
# producción (php artisan produccion:verificar). Las carpetas de storage viven en un volumen persistente.
set -e
cd /var/www/html
mkdir -p storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
php artisan produccion:verificar
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
exec "$@"
