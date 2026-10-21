#!/bin/sh
# Asistente de Google Drive para el servidor Linux. Las credenciales quedan en rclone.conf, fuera del proyecto.
# Sin argumentos: autoriza los remotos que falten, prueba una copia cifrada y guarda el destino en .env.respaldos.
# --verificar: comprueba los remotos existentes sin abrir el asistente ni cambiar la configuración del proyecto.
set -eu
umask 077
cd "$(dirname "$0")/../.."
. ./docker/produccion/configuracion-respaldo.sh

modo=${1:-conectar}
case "$modo" in
    conectar|--verificar) ;;
    --help|-h) sed -n '2,4p' "$0"; exit 0 ;;
    *) echo "Uso: sh docker/produccion/conectar-drive.sh [--verificar]" >&2; exit 2 ;;
esac
[ $# -le 1 ] || exit 2
command -v docker >/dev/null 2>&1 || { echo "Se necesita Docker para conectar Google Drive." >&2; exit 2; }

entorno=${RESPALDO_ENTORNO:-.env.respaldos}
valor_entorno() { [ ! -f "$entorno" ] || awk -F= -v clave="$1" '$1 == clave { sub(/^[^=]*=/, ""); sub(/\r$/, ""); valor=$0 } END { print valor }' "$entorno"; }
configuracion=${RESPALDO_RCLONE_CONF:-$(valor_entorno RESPALDO_RCLONE_CONF)}
configuracion=${configuracion:-$HOME/.config/rclone/rclone.conf}
destino=${RESPALDO_REMOTO:-$(valor_entorno RESPALDO_REMOTO)}
destino=${destino:-escalafon_cifrado:copias}
directorio=$(dirname "$configuracion")
case "$configuracion" in /*) ;; *) echo "RESPALDO_RCLONE_CONF debe ser una ruta absoluta." >&2; exit 2 ;; esac
case "$configuracion" in *[[:space:]]*) echo "La ruta de rclone no puede contener espacios." >&2; exit 2 ;; esac
case "$configuracion" in "$PWD"/*) echo "Guarda rclone.conf fuera del repositorio." >&2; exit 2 ;; esac
printf '%s' "$destino" | grep -Eq '^[a-zA-Z0-9_-]+:[a-zA-Z0-9_-]+(/[a-zA-Z0-9_-]+)*$' || { echo "Usa un destino remoto:carpeta exclusivo para respaldos." >&2; exit 2; }
mkdir -p "$directorio"
imagen=rclone/rclone:1.68.2
rclone() { docker run --rm --user "$(id -u):$(id -g)" -v "$directorio:/config/rclone" "$imagen" --config "/config/rclone/$(basename "$configuracion")" --contimeout 10s --timeout 30s --retries 1 "$@"; }
interactivo() {
    [ -t 0 ] && [ -t 1 ] || { echo "Ejecuta el asistente desde una terminal para autorizar Google y definir el cifrado." >&2; exit 2; }
    docker run --rm -it --network host --user "$(id -u):$(id -g)" -v "$directorio:/config/rclone" "$imagen" --config "/config/rclone/$(basename "$configuracion")" "$@"
}
tipo_de() { rclone config show "$1" 2>/dev/null | awk '$1 == "type" && $2 == "=" { print $3 }'; }

remoto=${destino%%:*}
if [ "$modo" != --verificar ]; then
    if [ -z "$(tipo_de escalafon_drive)" ]; then
        echo "1. Autoriza Google Drive. Abre en tu navegador la dirección que indique rclone."
        echo "En un servidor remoto puedes usar el túnel SSH descrito en https://rclone.org/remote_setup/."
        interactivo config create escalafon_drive drive scope drive.file config_is_local true
    fi
    [ "$(tipo_de escalafon_drive)" = drive ] || { echo "escalafon_drive ya existe con otro tipo; no se modificó." >&2; exit 2; }
    if [ -z "$(tipo_de "$remoto")" ]; then
        echo "2. Configura el cifrado. Conserva el destino propuesto y el cifrado de nombres."
        echo "Genera contraseña y sal con la opción g del asistente; guarda una copia segura de rclone.conf."
        interactivo config create "$remoto" crypt remote escalafon_drive:escalafon-respaldos filename_encryption standard directory_name_encryption true --all
    fi
fi
[ "$(tipo_de "$remoto")" = crypt ] || { echo "El destino no tiene un remoto crypt configurado. No se subirá nada sin cifrar." >&2; exit 2; }
base=$(rclone config show "$remoto" 2>/dev/null | awk '$1 == "remote" && $2 == "=" { print $3 }')
[ "$(tipo_de "${base%%:*}")" = drive ] || { echo "El remoto cifrado debe apuntar a Google Drive." >&2; exit 2; }
opciones=$(rclone config show "$remoto" 2>/dev/null | awk '$1 == "filename_encryption" || $1 == "directory_name_encryption" { print $1 "=" $3 }')
if printf '%s\n' "$opciones" | grep -Eq '^(filename_encryption=off|directory_name_encryption=false)$'; then
    echo "Activa el cifrado de nombres de archivos y carpetas antes de conectar." >&2
    exit 2
fi
[ -f "$configuracion" ] || { echo "No existe rclone.conf." >&2; exit 2; }
chmod 600 "$configuracion"

prueba=$(mktemp -d "${TMPDIR:-/tmp}/escalafon-drive-XXXXXX")
objeto="$destino/.conexion-$(basename "$prueba").txt"
subido= temporal_entorno=
limpiar() {
    [ -z "$subido" ] || rclone deletefile "$objeto" >/dev/null 2>&1 || true
    [ -z "$temporal_entorno" ] || rm -f "$temporal_entorno"
    rm -rf "$prueba"
}
trap limpiar EXIT
trap 'exit 130' INT TERM HUP
printf 'Prueba de conexión cifrada de Escalafón\n' > "$prueba/original.txt"
subido=si
rclone copyto "$prueba/original.txt" "$objeto"
rclone cat "$objeto" > "$prueba/recuperado.txt"
cmp "$prueba/original.txt" "$prueba/recuperado.txt" || { echo "La prueba recuperada no coincide; la configuración no se activó." >&2; exit 1; }
rclone deletefile "$objeto"
subido=
echo "Conexión cifrada comprobada: se subió, recuperó y eliminó únicamente un archivo de prueba."
[ "$modo" != --verificar ] || exit 0

if [ -f "$entorno" ] || [ "$(basename "$entorno")" = .env.respaldos ]; then
    temporal_entorno=$(mktemp "${entorno}.XXXXXX")
    if [ -f "$entorno" ]; then
        awk '!/^RESPALDO_REMOTO=/ && !/^RESPALDO_RCLONE_CONF=/' "$entorno" > "$temporal_entorno"
    elif [ -f .env.produccion ]; then
        printf 'RESPALDO_MODO=produccion\n' > "$temporal_entorno"
    else
        printf 'RESPALDO_MODO=local\n' > "$temporal_entorno"
    fi
    printf '\nRESPALDO_REMOTO=%s\nRESPALDO_RCLONE_CONF=%s\n' "$destino" "$configuracion" >> "$temporal_entorno"
    chmod 600 "$temporal_entorno"
    mv "$temporal_entorno" "$entorno"
    temporal_entorno=
    echo "Destino guardado en $entorno."
else
    echo "No existe $entorno; no se creó un entorno de producción incompleto. Agrega al prepararlo:"
    printf 'RESPALDO_REMOTO=%s\nRESPALDO_RCLONE_CONF=%s\n' "$destino" "$configuracion"
fi
echo "Conserva rclone.conf en un lugar seguro fuera del servidor; se necesita para recuperar las copias."
echo "Ejecuta respaldar.sh y programa su ejecución diaria; luego prueba la restauración con restaurar.sh verificar."
