#!/bin/sh
# Instalación o actualización en el servidor: construye las imágenes, migra todas las bases (la del .env, la de la
# plataforma y la de cada institución) y arranca o reemplaza los contenedores. Se ejecuta desde cualquier carpeta.
set -e
cd "$(dirname "$0")/../.."
[ -f .env.produccion ] || { echo "Falta .env.produccion: copia .env.produccion.example y complétalo (docs/PRODUCCION.md)."; exit 1; }
dc() { docker compose --env-file .env.produccion -f docker-compose.produccion.yml "$@"; }

dc build
dc up -d --wait db clamav
dc run --rm --no-deps app php artisan documentos:clave
# Antes de reemplazar la aplicación: si una migración falla, sigue corriendo la versión anterior.
dc run --rm --no-deps app php artisan migrate --force
dc run --rm --no-deps app php artisan plataforma:migrar-instituciones
dc up -d --remove-orphans
dc ps
echo "Despliegue terminado."
