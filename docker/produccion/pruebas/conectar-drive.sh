#!/bin/sh
# Pruebas aisladas del asistente: Docker y Google Drive se simulan, sin credenciales ni conexiones reales.
set -eu
REPO=$(cd "$(dirname "$0")/../../.." && pwd -P)
T=$(mktemp -d "${TMPDIR:-/tmp}/pruebas-drive-XXXXXX")
trap 'rm -rf "$T"' EXIT
export PRUEBA_DRIVE="$T"
mkdir -p "$T/bin" "$T/config"
printf 'configuracion de prueba\n' > "$T/config/rclone.conf"
export RESPALDO_RCLONE_CONF="$T/config/rclone.conf" RESPALDO_ENTORNO="$T/.env.produccion" RESPALDO_REMOTO=escalafon_cifrado:copias
printf 'CLAVE_DE_PRUEBA=conservar\nRESPALDO_REMOTO=anterior:carpeta\n' > "$RESPALDO_ENTORNO"
cat > "$T/bin/docker" <<'EOF'
#!/bin/sh
set -eu
while [ "$#" -gt 0 ] && [ "$1" != rclone/rclone:1.68.2 ]; do shift; done
[ "$#" -gt 0 ] || exit 99
shift
while [ "$#" -gt 0 ]; do
    case "$1" in config|copyto|cat|deletefile) break ;; *) shift ;; esac
done
printf '%s\n' "$1" >> "$PRUEBA_DRIVE/operaciones"
case "$1" in
    config)
        [ "$2" = show ] || exit 99
        case "$3" in
            escalafon_drive) echo 'type = drive' ;;
            escalafon_cifrado) echo "type = ${PRUEBA_TIPO_REMOTO:-crypt}"; echo 'remote = escalafon_drive:escalafon-respaldos'; echo 'filename_encryption = standard'; echo 'directory_name_encryption = true' ;;
            *) exit 1 ;;
        esac ;;
    copyto) cp "$2" "$PRUEBA_DRIVE/objeto" ;;
    cat) if [ -n "${PRUEBA_CONTENIDO_INVALIDO:-}" ]; then echo 'contenido incorrecto'; else cat "$PRUEBA_DRIVE/objeto"; fi ;;
    deletefile) rm -f "$PRUEBA_DRIVE/objeto" ;;
    *) exit 99 ;;
esac
EOF
chmod +x "$T/bin/docker"
export PATH="$T/bin:$PATH"
asistente() { sh "$REPO/docker/produccion/conectar-drive.sh" "$@" > "$T/salida" 2>&1; }

asistente
grep -qx 'CLAVE_DE_PRUEBA=conservar' "$RESPALDO_ENTORNO"
grep -qx 'RESPALDO_REMOTO=escalafon_cifrado:copias' "$RESPALDO_ENTORNO"
[ "$(grep -c '^RESPALDO_REMOTO=' "$RESPALDO_ENTORNO")" = 1 ]
[ ! -e "$T/objeto" ]
echo 'ok: comprueba ida y vuelta cifrada, limpia la prueba y conserva las demás variables'

cp "$RESPALDO_ENTORNO" "$T/antes"
asistente --verificar
cmp "$T/antes" "$RESPALDO_ENTORNO"
echo 'ok: verificar no modifica la configuración del proyecto'

if PRUEBA_CONTENIDO_INVALIDO=1 asistente; then echo 'FALLA: aceptó contenido distinto' >&2; exit 1; fi
cmp "$T/antes" "$RESPALDO_ENTORNO"
[ ! -e "$T/objeto" ]
echo 'ok: una prueba incorrecta conserva la configuración y limpia su archivo remoto'

: > "$T/operaciones"
if PRUEBA_TIPO_REMOTO=drive asistente --verificar; then echo 'FALLA: aceptó un destino sin cifrar' >&2; exit 1; fi
! grep -qx copyto "$T/operaciones"
echo 'ok: no sube archivos a un destino sin cifrar'

RESPALDO_ENTORNO="$T/no-existe" asistente
[ ! -e "$T/no-existe" ]
grep -q 'no se creó un entorno de producción incompleto' "$T/salida"
echo 'ok: no crea un entorno de producción incompleto'

RESPALDO_ENTORNO="$T/.env.respaldos" asistente
grep -qx 'RESPALDO_REMOTO=escalafon_cifrado:copias' "$T/.env.respaldos"
grep -Eq '^RESPALDO_MODO=(local|produccion)$' "$T/.env.respaldos"
echo 'ok: prepara la configuración de respaldos sin credenciales dentro del proyecto'

printf 'RESPALDO_REMOTO=desde-archivo:carpeta\nRESPALDO_MODO=local\n' > "$T/config-env"
RESPALDO_ENTORNO="$T/config-env" RESPALDO_REMOTO= sh -c '. "$1/docker/produccion/configuracion-respaldo.sh"; [ -z "$RESPALDO_REMOTO" ]' sh "$REPO"
env -u RESPALDO_REMOTO -u RESPALDO_DC RESPALDO_ENTORNO="$T/config-env" sh -c '. "$1/docker/produccion/configuracion-respaldo.sh"; [ "$RESPALDO_REMOTO" = desde-archivo:carpeta ] && [ "$RESPALDO_DC" = "docker compose" ]' sh "$REPO"
echo 'ok: la variable explícita vacía prevalece y el entorno local usa su propio Compose'
