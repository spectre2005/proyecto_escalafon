# Configuración compartida por respaldo, restauración y conexión a Drive. Valores sin comillas ni espacios.
# Las variables del proceso, incluso vacías, prevalecen sobre los archivos. Nunca se ejecuta el contenido del .env.
for respaldo_archivo in "${RESPALDO_ENTORNO:-.env.respaldos}" .env.produccion; do
    [ -f "$respaldo_archivo" ] || continue
    while IFS= read -r respaldo_linea || [ -n "$respaldo_linea" ]; do
        printf '%s' "$respaldo_linea" | grep -Eq '^RESPALDO_[A-Z_]+=[^[:space:]]*$' || continue
        respaldo_nombre=${respaldo_linea%%=*}
        eval "respaldo_definida=\${$respaldo_nombre+x}"
        [ "$respaldo_definida" = x ] || export "$respaldo_linea"
    done < "$respaldo_archivo"
done
if [ "${RESPALDO_MODO:-produccion}" = local ]; then
    RESPALDO_DC=${RESPALDO_DC:-docker compose}
else
    RESPALDO_DC=${RESPALDO_DC:-docker compose --env-file .env.produccion -f docker-compose.produccion.yml}
fi
