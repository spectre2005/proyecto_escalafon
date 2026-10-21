#!/bin/sh
# Asistente para conectar la copia externa de los respaldos (respaldar.sh) con Google Drive, con rclone en Docker.
# Solo en el servidor de producción (.env.produccion con APP_ENV=production). Detalle en docs/PRODUCCION.md.
#
#   sh docker/produccion/conectar-drive.sh                 autoriza Google (OAuth en el navegador de este equipo)
#   sh docker/produccion/conectar-drive.sh --sin-navegador autoriza en un servidor sin navegador («rclone authorize»
#                                                          en otro equipo y se pega el resultado)
#   sh docker/produccion/conectar-drive.sh --verificar     solo comprueba la conexión; no cambia ninguna configuración
#
# Qué hace (conectar):
#   1. Crea, si falta, la clave de rclone.conf (RESPALDO_RCLONE_CLAVE, 600) y cifra rclone.conf con ella: el token de
#      Google y las claves del cifrado de los respaldos nunca quedan legibles en el disco. La clave no se imprime.
#   2. Autoriza Google Drive (alcance drive.file: solo los archivos que crea rclone) en el remoto escalafon_drive.
#   3. Crea el remoto crypt de RESPALDO_REMOTO sobre escalafon_drive:escalafon-respaldos, con nombres cifrados.
#   4. Prueba la conexión: sube un archivo de prueba, lo lee, lo compara con el original y lo borra (solo ese archivo).
#   5. Si todo salió bien, guarda en .env.produccion solo RESPALDO_REMOTO, RESPALDO_RCLONE_CONF y RESPALDO_RCLONE_CLAVE.
# No sube respaldos, no borra copias, no programa nada y no restaura: eso es de respaldar.sh y de una fase posterior.
#
# Variables (entorno o líneas RESPALDO_* de .env.produccion; una del entorno manda aunque esté vacía, y vacía usa el
# valor por defecto):
#   RESPALDO_REMOTO        remoto crypt y carpeta (por defecto escalafon_cifrado:copias)
#   RESPALDO_RCLONE_CONF   configuración de rclone, fuera del repositorio (por defecto ~/.config/rclone/rclone.conf)
#   RESPALDO_RCLONE_CLAVE  clave de rclone.conf, fuera del repositorio (por defecto ~/.config/escalafon/rclone.clave)
#   RESPALDO_ENTORNO       archivo de entorno (por defecto .env.produccion; lo usan las pruebas)
set -eu
umask 077
cd "$(dirname "$0")/../.."
repositorio=$(pwd -P)

modo=conectar
case "${1:-}" in
	"") ;;
	--sin-navegador) modo=sin_navegador ;;
	--verificar) modo=verificar ;;
	--help | -h) sed -n '2,9p' "$0"; exit 0 ;;
	*) echo "Uso: sh docker/produccion/conectar-drive.sh [--sin-navegador | --verificar]" >&2; exit 2 ;;
esac
[ $# -le 1 ] || { echo "Demasiados argumentos." >&2; exit 2; }
command -v docker > /dev/null 2>&1 || { echo "Se necesita Docker para ejecutar rclone." >&2; exit 2; }

entorno=${RESPALDO_ENTORNO:-.env.produccion}
# Solo el servidor de producción: un entorno local (aunque esté conectado a datos reales) nunca se conecta a Drive.
[ -f "$entorno" ] || { echo "No existe $entorno: este asistente solo se usa en el servidor de producción." >&2; exit 2; }
grep -qx 'APP_ENV=production' "$entorno" || { echo "$entorno no tiene APP_ENV=production: no se conecta Drive fuera de producción." >&2; exit 2; }

# Valor de una variable RESPALDO_*: la del entorno si está definida (aunque vacía), si no la del archivo, sin ejecutarlo.
valor() { # $1 nombre $2 por defecto
	eval "definida=\${$1+si} actual=\${$1-}"
	if [ "$definida" != si ]; then
		actual=$(awk -F= -v clave="$1" '$1 == clave { sub(/^[^=]*=/, ""); sub(/\r$/, ""); v = $0 } END { print v }' "$entorno")
	fi
	printf '%s' "${actual:-$2}"
}
configuracion=$(valor RESPALDO_RCLONE_CONF "$HOME/.config/rclone/rclone.conf")
clave=$(valor RESPALDO_RCLONE_CLAVE "$HOME/.config/escalafon/rclone.clave")
destino=$(valor RESPALDO_REMOTO escalafon_cifrado:copias)
remoto=${destino%%:*}

for ruta in "$configuracion" "$clave"; do
	case "$ruta" in /*) ;; *) echo "Las rutas de rclone deben ser absolutas: $ruta" >&2; exit 2 ;; esac
	case "$ruta" in *[[:space:]]*) echo "Las rutas de rclone no pueden tener espacios: $ruta" >&2; exit 2 ;; esac
	case "$ruta" in "$repositorio"/*) echo "Guarda rclone.conf y su clave fuera del repositorio: $ruta" >&2; exit 2 ;; esac
done
[ "$(dirname "$configuracion")" != "$(dirname "$clave")" ] || { echo "Guarda la clave en otra carpeta que rclone.conf." >&2; exit 2; }
printf '%s' "$destino" | grep -Eqx '[a-zA-Z0-9_-]+:[a-zA-Z0-9_-]+(/[a-zA-Z0-9_-]+)*' \
	|| { echo "RESPALDO_REMOTO debe ser remoto:carpeta, una carpeta solo para respaldos." >&2; exit 2; }
[ "$remoto" != escalafon_drive ] || { echo "RESPALDO_REMOTO debe ser el remoto cifrado, no escalafon_drive." >&2; exit 2; }

imagen=rclone/rclone:1.68.2
conf_dir=$(dirname "$configuracion")
# rclone sin red de host, con rclone.conf cifrado; la clave llega por --password-command desde un archivo de solo lectura.
rclone() {
	docker run --rm --user "$(id -u):$(id -g)" -v "$conf_dir:/config/rclone" -v "$clave:/run/clave:ro" "$imagen" \
		--config "/config/rclone/$(basename "$configuracion")" --password-command "cat /run/clave" --cache-dir /tmp \
		--contimeout 10s --timeout 30s --retries 1 "$@" < /dev/null
}
# Pasos con el administrador (OAuth, cifrado). La salida pasa por un filtro que oculta tokens y contraseñas: rclone
# muestra la configuración creada al terminar. Red del host solo para que el navegador llegue a 127.0.0.1:53682.
interactivo() {
	[ -t 0 ] && [ -t 1 ] || { echo "Ejecuta el asistente desde una terminal: hay que autorizar Google." >&2; exit 2; }
	red=""
	[ "$modo" = sin_navegador ] || red="--network host"
	estado=$(mktemp)
	{ docker run --rm -i $red --user "$(id -u):$(id -g)" -v "$conf_dir:/config/rclone" -v "$clave:/run/clave:ro" "$imagen" \
		--config "/config/rclone/$(basename "$configuracion")" --password-command "cat /run/clave" --cache-dir /tmp "$@"
		echo $? > "$estado"; } | sed -u -E 's/^([[:space:]]*(token|access_token|refresh_token|client_secret|password|password2)[[:space:]]*[=:][[:space:]]*).*/\1<oculto>/; s/(Your password is:[[:space:]]*).*/\1<oculto>/'
	resultado=$(cat "$estado")
	rm -f "$estado"
	[ "$resultado" = 0 ]
}
# Solo el tipo y las opciones pedidas de un remoto: «config show» también trae el token y las claves, que no se muestran.
opcion() { rclone config show "$1" 2> /dev/null | awk -v clave="$2" '$1 == clave && $2 == "=" { print $3 }'; }
cifrada() { [ -f "$configuracion" ] && grep -q '^RCLONE_ENCRYPT_V0:' "$configuracion"; }
permisos_privados() { case "$(stat -c %a "$1")" in 600 | 400) true ;; *) false ;; esac; }

if [ "$modo" = verificar ]; then
	[ -f "$clave" ] || { echo "No existe la clave de rclone.conf ($clave)." >&2; exit 2; }
	cifrada || { echo "rclone.conf no existe o no está cifrado: ejecuta el asistente sin --verificar." >&2; exit 2; }
else
	mkdir -p "$conf_dir" "$(dirname "$clave")"
	chmod 700 "$conf_dir" "$(dirname "$clave")"
	if [ ! -f "$clave" ]; then
		# 32 bytes aleatorios en base64; no se imprimen.
		head -c 32 /dev/urandom | base64 | tr -d '\n' > "$clave"
		echo "Se creó la clave de rclone.conf en $clave. Guarda una copia en el gestor de contraseñas del equipo."
	fi
	[ -f "$configuracion" ] || : > "$configuracion"
	chmod 600 "$configuracion"
	if ! cifrada; then
		echo "Cifrando rclone.conf con su clave..."
		rclone config encryption set > /dev/null || { echo "No se pudo cifrar rclone.conf." >&2; exit 1; }
		cifrada || { echo "rclone.conf sigue sin cifrar; no se continúa." >&2; exit 1; }
	fi
fi
permisos_privados "$clave" || { echo "La clave de rclone.conf debe tener permisos 600 o 400." >&2; exit 2; }
rclone config show > /dev/null 2>&1 || { echo "No se pudo abrir rclone.conf con su clave (¿clave equivocada?)." >&2; exit 2; }

if [ "$modo" != verificar ]; then
	if [ -z "$(opcion escalafon_drive type)" ]; then
		echo "1. Autorización de Google Drive (alcance drive.file: rclone solo ve los archivos que él mismo crea)."
		if [ "$modo" = sin_navegador ]; then
			echo "   En un equipo con navegador ejecuta: docker run --rm -it -p 53682:53682 $imagen authorize drive"
			echo "   y pega aquí el resultado cuando rclone lo pida (no lo compartas: es la autorización de la cuenta)."
			interactivo config create escalafon_drive drive scope=drive.file config_is_local=false \
				|| { echo "La autorización no terminó." >&2; exit 1; }
		else
			echo "   Abre en el navegador de este equipo la dirección que indique rclone e inicia sesión con la cuenta"
			echo "   de destino de los respaldos."
			interactivo config create escalafon_drive drive scope=drive.file config_is_local=true \
				|| { echo "La autorización no terminó." >&2; exit 1; }
		fi
	fi
	[ "$(opcion escalafon_drive type)" = drive ] || { echo "escalafon_drive existe con otro tipo; no se modificó." >&2; exit 2; }
	if [ -z "$(opcion "$remoto" type)" ]; then
		echo "2. Cifrado de los respaldos: cuando rclone pida las contraseñas del cifrado, elige «g» (generar)."
		echo "   Quedan dentro de rclone.conf, cifrado; para recuperar las copias hacen falta rclone.conf y su clave."
		interactivo config create "$remoto" crypt remote=escalafon_drive:escalafon-respaldos \
			filename_encryption=standard directory_name_encryption=true --all \
			|| { echo "No se creó el remoto cifrado." >&2; exit 1; }
	fi
fi

# Comprobaciones: nada se sube si el destino no es crypt sobre Google Drive con los nombres cifrados.
[ "$(opcion "$remoto" type)" = crypt ] || { echo "$remoto no es un remoto crypt: no se sube nada sin cifrar." >&2; exit 2; }
base=$(opcion "$remoto" remote)
[ "$(opcion "${base%%:*}" type)" = drive ] || { echo "El remoto cifrado debe apuntar a Google Drive." >&2; exit 2; }
[ "$(opcion "$remoto" filename_encryption)" != off ] && [ "$(opcion "$remoto" directory_name_encryption)" != false ] \
	|| { echo "Activa el cifrado de nombres de archivos y carpetas del remoto $remoto." >&2; exit 2; }
chmod 600 "$configuracion"

# Prueba de ida y vuelta con un archivo propio, que se borra al terminar (bien o mal). Ningún otro archivo se toca.
prueba=$(mktemp -d)
objeto="$destino/.prueba-conexion-$(date +%Y%m%d%H%M%S)-$$.txt"
subido=""
limpiar() {
	[ -z "$subido" ] || docker run --rm --user "$(id -u):$(id -g)" -v "$conf_dir:/config/rclone" -v "$clave:/run/clave:ro" "$imagen" \
		--config "/config/rclone/$(basename "$configuracion")" --password-command "cat /run/clave" --cache-dir /tmp \
		deletefile "$objeto" > /dev/null 2>&1 < /dev/null || echo "Aviso: no se pudo borrar el archivo de prueba $objeto." >&2
	rm -rf "$prueba"
}
trap limpiar EXIT
trap 'exit 130' INT TERM HUP
printf 'Prueba de conexión cifrada de los respaldos (%s)\n' "$(date -Iseconds)" > "$prueba/original.txt"
subido=si
# rcat lee el archivo de la entrada estándar: no hace falta montar ninguna carpeta del servidor.
docker run --rm -i --user "$(id -u):$(id -g)" -v "$conf_dir:/config/rclone" -v "$clave:/run/clave:ro" "$imagen" \
	--config "/config/rclone/$(basename "$configuracion")" --password-command "cat /run/clave" --cache-dir /tmp \
	--contimeout 10s --timeout 30s --retries 1 rcat "$objeto" < "$prueba/original.txt" 2> "$prueba/error" \
	|| { echo "No se pudo subir el archivo de prueba a $destino:" >&2; tail -n 3 "$prueba/error" >&2; exit 1; }
rclone cat "$objeto" > "$prueba/recuperado.txt" 2> "$prueba/error" \
	|| { echo "No se pudo leer el archivo de prueba:" >&2; tail -n 3 "$prueba/error" >&2; exit 1; }
cmp -s "$prueba/original.txt" "$prueba/recuperado.txt" || { echo "El archivo leído no coincide con el subido: no se guardó nada." >&2; exit 1; }
rclone deletefile "$objeto" 2> "$prueba/error" || { echo "No se pudo borrar el archivo de prueba $objeto: revisa la conexión." >&2; exit 1; }
subido=""
echo "Conexión comprobada: se subió, se leyó (igual al original) y se borró solo el archivo de prueba."
[ "$modo" != verificar ] || exit 0

# Solo las tres líneas del destino en .env.produccion; las demás se conservan tal cual, con sus permisos.
temporal=$(mktemp "$entorno.XXXXXX")
awk '!/^RESPALDO_(REMOTO|RCLONE_CONF|RCLONE_CLAVE)=/' "$entorno" > "$temporal"
printf 'RESPALDO_REMOTO=%s\nRESPALDO_RCLONE_CONF=%s\nRESPALDO_RCLONE_CLAVE=%s\n' "$destino" "$configuracion" "$clave" >> "$temporal"
chmod "$(stat -c %a "$entorno")" "$temporal"
mv "$temporal" "$entorno"
echo "Destino guardado en $entorno (RESPALDO_REMOTO, RESPALDO_RCLONE_CONF y RESPALDO_RCLONE_CLAVE)."
echo "Guarda, por separado y fuera del servidor, una copia de $configuracion y otra de su clave: sin las dos no se"
echo "pueden leer las copias de Drive. El próximo respaldar.sh subirá la copia y la comprobará con cryptcheck."
