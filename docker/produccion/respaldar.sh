#!/bin/sh
# Respaldo completo: cada base de datos (pg_dump, formato custom) y los archivos privados (PDF, fotos) de todas las
# instituciones. Queda en respaldos/AAAAMMDD-HHMMSS y se borran los de más de RESPALDO_DIAS días (14 por defecto).
# Programarlo con cron (docs/PRODUCCION.md) y copiar la carpeta respaldos/ fuera del servidor.
set -e
cd "$(dirname "$0")/../.."
dc() { docker compose --env-file .env.produccion -f docker-compose.produccion.yml "$@"; }

destino="respaldos/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$destino"
chmod 700 respaldos "$destino"
bases=$(dc exec -T db sh -c 'psql -U "$POSTGRES_USER" -d postgres -Atc "select datname from pg_database where not datistemplate and datname <> '"'"'postgres'"'"'"')
for base in $bases; do
	dc exec -T db sh -c "pg_dump -U \"\$POSTGRES_USER\" -Fc \"$base\"" > "$destino/$base.dump"
	echo "Base $base respaldada."
done
dc exec -T app tar -czf - -C /var/www/html/storage app > "$destino/archivos.tar.gz"
echo "Archivos respaldados."
find respaldos -mindepth 1 -maxdepth 1 -type d -mtime +"${RESPALDO_DIAS:-14}" -exec rm -rf {} +
echo "Respaldo en $destino"
