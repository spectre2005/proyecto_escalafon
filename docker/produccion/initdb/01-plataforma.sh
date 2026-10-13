#!/bin/sh
# Solo la primera vez que se crea el volumen de PostgreSQL: la base de la plataforma, junto a la institucional.
set -e
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" -c "CREATE DATABASE \"${PLATAFORMA_DB_DATABASE:-escalafon_plataforma}\""
