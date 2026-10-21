#!/bin/sh
# Pruebas aisladas de conectar-drive.sh. Se ejecutan sin red ni cuenta de Google:
#
#   sh docker/produccion/pruebas/conectar-drive.sh
#
# «docker» está sustituido por uno falso que simula rclone (configuración cifrada, remotos, subida, lectura y borrado)
# sobre una carpeta temporal. Nada toca Google Drive, rclone.conf reales ni el .env.produccion del repositorio; la
# carpeta temporal se borra al terminar.
set -eu
REPO=$(cd "$(dirname "$0")/../../.." && pwd -P)
[ ! -e "$REPO/.env.produccion" ] || { echo "Hay un .env.produccion: estas pruebas no se ejecutan junto a una configuración real." >&2; exit 2; }

T=$(mktemp -d "${TMPDIR:-/tmp}/pruebas-drive-XXXXXX")
trap 'rm -rf "$T"' EXIT
export FALSO="$T/falso"
mkdir -p "$FALSO/bin" "$FALSO/drive" "$T/home"
: > "$FALSO/llamadas"
: > "$FALSO/remotos"

cat > "$FALSO/bin/docker" <<'FIN_DOCKER'
#!/bin/sh
# docker falso: solo «run ... rclone/rclone:1.68.2 <opciones> <orden>». Simula rclone sin red.
[ "$1" = run ] || { echo "docker falso: solo run" >&2; exit 99; }
echo "$*" >> "$FALSO/llamadas"
conf_dir="" clave=""
while [ "$#" -gt 0 ] && [ "$1" != rclone/rclone:1.68.2 ]; do
	if [ "$1" = -v ]; then
		case "$2" in
		*:/config/rclone) conf_dir=${2%:/config/rclone} ;;
		*:/run/clave:ro) clave=${2%:/run/clave:ro} ;;
		esac
	fi
	shift
done
[ "$#" -gt 0 ] || exit 99
shift
config="" pc=""
while [ "$#" -gt 0 ]; do
	case "$1" in
	--config) config=$conf_dir/${2##*/}; shift 2 ;;
	--password-command) pc=$2; shift 2 ;;
	--cache-dir | --contimeout | --timeout | --retries) shift 2 ;;
	*) break ;;
	esac
done
[ "$pc" = "cat /run/clave" ] && [ -r "$clave" ] || { echo "falso: rclone sin su clave" >&2; exit 98; }
cifrada() { grep -q '^RCLONE_ENCRYPT_V0:' "$config"; }
huella_clave() { sed -n 's/^CLAVE=//p' "$config"; }
abrir() { cifrada && [ "$(huella_clave)" = "$(cat "$clave")" ] || { echo "CRITICAL: Failed to load config file: password incorrect" >&2; exit 1; }; }
objeto() { printf '%s' "$1" | tr '/:' '__'; }
orden=$1
shift
case "$orden" in
config)
	sub=$1
	shift
	case "$sub" in
	encryption)
		[ "$1" = set ] || exit 97
		printf '# Encrypted rclone\nRCLONE_ENCRYPT_V0:\nCLAVE=%s\n' "$(cat "$clave")" > "$config"
		;;
	show)
		abrir
		[ "$#" -gt 0 ] || exit 0
		linea=$(awk -v n="$1" '$1 == n' "$FALSO/remotos")
		[ -n "$linea" ] || exit 1
		echo "[$1]"
		for par in $linea; do
			case "$par" in *=*) echo "${par%%=*} = ${par#*=}" ;; esac
		done
		echo 'token = {"access_token":"SECRETO-FALSO","refresh_token":"SECRETO-FALSO"}'
		;;
	create)
		abrir
		nombre=$1 tipo=$2
		shift 2
		pares="type=$tipo"
		for a; do case "$a" in *=*) pares="$pares $a" ;; esac; done
		echo "$nombre $pares" >> "$FALSO/remotos"
		echo "[$nombre]"
		echo "type = $tipo"
		echo 'token = {"access_token":"SECRETO-FALSO"}'
		echo 'password = SECRETO-FALSO'
		echo 'Your password is: SECRETO-FALSO'
		;;
	*) exit 97 ;;
	esac
	;;
rcat)
	abrir
	[ -z "${FALSO_SUBIDA:-}" ] || { cat > /dev/null; echo 'ERROR : Failed to upload: googleapi: Error 500' >&2; exit 1; }
	cat > "$FALSO/drive/$(objeto "$1")"
	echo "rcat $1" >> "$FALSO/operaciones"
	;;
cat)
	abrir
	[ -z "${FALSO_LECTURA:-}" ] || { echo 'contenido distinto'; exit 0; }
	cat "$FALSO/drive/$(objeto "$1")"
	;;
deletefile)
	abrir
	echo "deletefile $1" >> "$FALSO/operaciones"
	rm -f "$FALSO/drive/$(objeto "$1")"
	;;
*) echo "falso: orden no simulada: $orden" >&2; exit 97 ;;
esac
FIN_DOCKER
chmod +x "$FALSO/bin/docker"
export PATH="$FALSO/bin:$PATH" HOME="$T/home"
unset RESPALDO_REMOTO RESPALDO_RCLONE_CONF RESPALDO_RCLONE_CLAVE RESPALDO_ENTORNO RESPALDO_MODO 2>/dev/null || true

aprobadas=0 fallidas=0
ok() { aprobadas=$((aprobadas + 1)); echo "  ok: $1"; }
falla() { fallidas=$((fallidas + 1)); echo "  FALLA: $1"; }
comprobar() { # $1 descripción, resto: orden
	descripcion=$1
	shift
	if "$@"; then ok "$descripcion"; else falla "$descripcion"; fi
}
rechaza() { # $1 descripción $2 texto esperado; resto: variables del caso. Sin terminal.
	descripcion=$1 texto=$2
	shift 2
	: > "$FALSO/llamadas"
	if env "$@" sh "$REPO/docker/produccion/conectar-drive.sh" < /dev/null > "$T/salida" 2>&1; then
		falla "$descripcion: debía rechazarse"
	else ok "$descripcion: se rechaza"; fi
	comprobar "$descripcion: lo explica" grep -qF -- "$texto" "$T/salida"
}
asistente() { sh "$REPO/docker/produccion/conectar-drive.sh" "$@" < /dev/null > "$T/salida" 2>&1; }
sin_secretos() { ! grep -Eq 'SECRETO-FALSO|CLAVE=' "$T/salida" && { [ ! -f "$clave" ] || ! grep -qF -- "$(cat "$clave")" "$T/salida"; }; }
sin_objetos() { [ -z "$(ls -A "$FALSO/drive")" ]; }
permisos() { [ "$(stat -c %a "$2")" = "$1" ]; }

conf="$T/rclone/rclone.conf"
clave="$T/escalafon/rclone.clave"
entorno="$T/.env.produccion"
printf 'APP_ENV=production\nAPP_KEY=no-se-toca\nRESPALDO_REMOTO=anterior:carpeta\nRESPALDO_DIAS=14\n' > "$entorno"
chmod 600 "$entorno"
export RESPALDO_ENTORNO="$entorno" RESPALDO_RCLONE_CONF="$conf" RESPALDO_RCLONE_CLAVE="$clave" RESPALDO_REMOTO=escalafon_cifrado:copias

echo "conectar-drive.sh: solo en producción y con rutas seguras"
rechaza "sin .env.produccion" "solo se usa en el servidor de producción" RESPALDO_ENTORNO="$T/no-existe"
comprobar "sin .env.produccion: no llama a docker" test ! -s "$FALSO/llamadas"
printf 'APP_ENV=local\nRESPALDO_MODO=local\n' > "$T/env-local"
rechaza "APP_ENV=local (aunque diga RESPALDO_MODO=local)" "no se conecta Drive fuera de producción" RESPALDO_ENTORNO="$T/env-local"
comprobar "APP_ENV=local: no llama a docker" test ! -s "$FALSO/llamadas"
rechaza "rclone.conf dentro del repositorio" "fuera del repositorio" RESPALDO_RCLONE_CONF="$REPO/rclone.conf"
rechaza "ruta relativa" "deben ser absolutas" RESPALDO_RCLONE_CLAVE=clave
rechaza "clave junto a rclone.conf" "en otra carpeta" RESPALDO_RCLONE_CLAVE="$T/rclone/rclone.clave"
rechaza "destino sin carpeta" "remoto:carpeta" RESPALDO_REMOTO=escalafon_cifrado:
rechaza "destino sin cifrar (escalafon_drive)" "el remoto cifrado" RESPALDO_REMOTO=escalafon_drive:copias
comprobar "nada creado tras los rechazos" sh -c "[ ! -e '$conf' ] && [ ! -e '$clave' ]"
comprobar ".env.produccion sin cambios tras los rechazos" grep -qx 'RESPALDO_REMOTO=anterior:carpeta' "$entorno"

echo "conectar-drive.sh: sin terminal no se autoriza Google"
rechaza "remotos por crear sin terminal" "Ejecuta el asistente desde una terminal"
comprobar "creó la clave con permisos 600" permisos 600 "$clave"
comprobar "cifró rclone.conf (600)" sh -c "grep -q '^RCLONE_ENCRYPT_V0:' '$conf' && [ \"\$(stat -c %a '$conf')\" = 600 ]"
comprobar "no creó ningún remoto" test ! -s "$FALSO/remotos"
comprobar "no subió nada" sin_objetos
comprobar "no imprimió la clave" sin_secretos
comprobar ".env.produccion sin cambios" grep -qx 'RESPALDO_REMOTO=anterior:carpeta' "$entorno"

echo "conectar-drive.sh: autorización con terminal (salida filtrada)"
# «script» da una terminal al asistente; el rclone falso imprime tokens y contraseñas al crear los remotos.
if script -qec "sh '$REPO/docker/produccion/conectar-drive.sh'" /dev/null < /dev/null > "$T/salida" 2>&1; then
	ok "termina bien"
else falla "termina bien"; sed 's/^/    /' "$T/salida"; fi
comprobar "crea el remoto de Drive con alcance drive.file" grep -q '^escalafon_drive type=drive scope=drive.file config_is_local=true' "$FALSO/remotos"
comprobar "crea el remoto crypt con nombres cifrados" grep -q '^escalafon_cifrado type=crypt remote=escalafon_drive:escalafon-respaldos filename_encryption=standard directory_name_encryption=true' "$FALSO/remotos"
comprobar "oculta tokens y contraseñas que muestra rclone" sh -c "grep -q '<oculto>' '$T/salida' && ! grep -q SECRETO-FALSO '$T/salida'"
comprobar "sube, lee y borra solo su archivo de prueba" sh -c "[ \"\$(cut -d' ' -f1 '$FALSO/operaciones' | tr '\n' ' ')\" = 'rcat deletefile ' ] && grep -q '^deletefile escalafon_cifrado:copias/.prueba-conexion-' '$FALSO/operaciones'"
comprobar "no queda el archivo de prueba en Drive" sin_objetos
comprobar "guarda el destino en .env.produccion" grep -qx 'RESPALDO_REMOTO=escalafon_cifrado:copias' "$entorno"
comprobar "guarda las rutas de rclone.conf y de la clave" sh -c "grep -qx 'RESPALDO_RCLONE_CONF=$conf' '$entorno' && grep -qx 'RESPALDO_RCLONE_CLAVE=$clave' '$entorno'"
comprobar "una sola línea RESPALDO_REMOTO" test "$(grep -c '^RESPALDO_REMOTO=' "$entorno")" = 1
comprobar "conserva las demás líneas" sh -c "grep -qx 'APP_KEY=no-se-toca' '$entorno' && grep -qx 'RESPALDO_DIAS=14' '$entorno' && grep -qx 'APP_ENV=production' '$entorno'"
comprobar "conserva los permisos de .env.produccion (600)" permisos 600 "$entorno"
comprobar "no escribe RESPALDO_MODO" sh -c "! grep -q RESPALDO_MODO '$entorno'"
comprobar "ni la clave ni los tokens en la salida" sin_secretos
comprobar "la clave nunca va en los argumentos de docker" sh -c "! grep -qF -- \"\$(cat '$clave')\" '$FALSO/llamadas'"

echo "conectar-drive.sh --verificar"
cp "$entorno" "$T/antes"
: > "$FALSO/operaciones"
: > "$FALSO/llamadas"
comprobar "termina bien" asistente --verificar
comprobar "no cambia .env.produccion" cmp -s "$T/antes" "$entorno"
comprobar "no crea remotos ni cifra de nuevo" sh -c "! grep -Eq ' config (create|encryption) ' '$FALSO/llamadas'"
comprobar "solo su archivo de prueba, borrado" sh -c "[ \"\$(cut -d' ' -f1 '$FALSO/operaciones' | tr '\n' ' ')\" = 'rcat deletefile ' ]"
comprobar "sin secretos en la salida" sin_secretos

echo "conectar-drive.sh: fallos de la prueba de conexión"
: > "$FALSO/operaciones"
if FALSO_SUBIDA=1 asistente; then falla "subida fallida: debía fallar"; else ok "subida fallida: falla"; fi
comprobar "subida fallida: lo explica" grep -q 'No se pudo subir el archivo de prueba' "$T/salida"
comprobar "subida fallida: no cambia .env.produccion" cmp -s "$T/antes" "$entorno"
comprobar "subida fallida: no queda nada en Drive" sin_objetos
: > "$FALSO/operaciones"
if FALSO_LECTURA=1 asistente; then falla "lectura distinta: debía fallar"; else ok "lectura distinta: falla"; fi
comprobar "lectura distinta: lo explica" grep -q 'no coincide con el subido' "$T/salida"
comprobar "lectura distinta: no cambia .env.produccion" cmp -s "$T/antes" "$entorno"
comprobar "lectura distinta: borra su archivo de prueba" sh -c "grep -q '^deletefile escalafon_cifrado:copias/.prueba-conexion-' '$FALSO/operaciones'"
comprobar "lectura distinta: no queda nada en Drive" sin_objetos
printf 'otra-clave' > "$T/escalafon/equivocada"
chmod 600 "$T/escalafon/equivocada"
if RESPALDO_RCLONE_CLAVE="$T/escalafon/equivocada" asistente --verificar; then falla "clave equivocada: debía fallar"; else ok "clave equivocada: falla"; fi
comprobar "clave equivocada: lo explica" grep -q 'No se pudo abrir rclone.conf con su clave' "$T/salida"
chmod 644 "$clave"
if asistente --verificar; then falla "clave con permisos abiertos: debía fallar"; else ok "clave con permisos abiertos: falla"; fi
comprobar "clave con permisos abiertos: lo explica" grep -q 'permisos 600 o 400' "$T/salida"
chmod 600 "$clave"

echo "conectar-drive.sh: destinos que no se aceptan"
cp "$FALSO/remotos" "$T/remotos.bien"
sed -i 's/^escalafon_cifrado type=crypt/escalafon_cifrado type=drive/' "$FALSO/remotos"
: > "$FALSO/operaciones"
if asistente --verificar; then falla "remoto sin cifrar: debía fallar"; else ok "remoto sin cifrar: falla"; fi
comprobar "remoto sin cifrar: no sube nada" test ! -s "$FALSO/operaciones"
cp "$T/remotos.bien" "$FALSO/remotos"
sed -i 's/filename_encryption=standard/filename_encryption=off/' "$FALSO/remotos"
if asistente --verificar; then falla "nombres sin cifrar: debía fallar"; else ok "nombres sin cifrar: falla"; fi
comprobar "nombres sin cifrar: lo explica" grep -q 'cifrado de nombres' "$T/salida"
comprobar "nombres sin cifrar: no sube nada" test ! -s "$FALSO/operaciones"
cp "$T/remotos.bien" "$FALSO/remotos"
printf '[cifrado]\ntype = crypt\n' > "$T/rclone/plano.conf"
if RESPALDO_RCLONE_CONF="$T/rclone/plano.conf" asistente --verificar; then falla "rclone.conf sin cifrar: debía fallar"; else ok "rclone.conf sin cifrar: --verificar falla"; fi
comprobar "rclone.conf sin cifrar: no lo modifica" sh -c "! grep -q RCLONE_ENCRYPT '$T/rclone/plano.conf'"

echo "Al terminar"
comprobar "nunca borra en Drive otra cosa que su archivo de prueba" sh -c "! grep -v ' .prueba-conexion-' '$FALSO/operaciones' | grep -q '^deletefile' && ! grep -Eq ' (delete|purge|rmdirs|sync|move) ' '$FALSO/llamadas'"
comprobar "no creó .env.produccion en el repositorio" test ! -e "$REPO/.env.produccion"

echo "$aprobadas comprobaciones correctas, $fallidas fallidas."
[ "$fallidas" -eq 0 ]
