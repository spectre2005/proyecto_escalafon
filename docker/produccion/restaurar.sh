#!/bin/sh
# Respaldos de respaldar.sh (RESPALDO_DIR/AAAAMMDD-HHMMSS/<objetivo>/; RESPALDO_DIR es «respaldos» por defecto).
#
#   sh docker/produccion/restaurar.sh verificar CARPETA [objetivo ...]
#       Prueba de restauración que no toca nada real: comprueba SHA256SUMS, restaura cada base en una base temporal
#       que crea esta misma ejecución, mira que tenga sus migraciones y que el paquete de archivos se lea, y borra la
#       temporal. Si createdb falla (también si ya existía una base con ese nombre), se detiene sin restaurar ni borrar
#       nada. Registra el resultado para el panel (plataforma:registrar-respaldo --verificacion). Sin objetivos: todos
#       los de la carpeta.
#
#   sh docker/produccion/restaurar.sh restaurar CARPETA objetivo
#       Restaura UNA institución (o «plataforma» o «principal»). Conviene suspenderla antes desde el panel. Pide
#       escribir el objetivo para confirmar (o RESPALDO_CONFIRMAR=objetivo). Antes guarda su estado actual en
#       RESPALDO_DIR/antes-de-restaurar-<fecha>-<objetivo>/ y aparta sus archivos actuales (no los borra). Después aplica
#       las migraciones pendientes, por si el respaldo es anterior al código.
set -eu
umask 077
cd "$(dirname "$0")/../.."
RESPALDO_DIR=${RESPALDO_DIR:-respaldos}
RESPALDO_DC=${RESPALDO_DC:-docker compose --env-file .env.produccion -f docker-compose.produccion.yml}
dc() { $RESPALDO_DC "$@" < /dev/null; }
dc_entrada() { $RESPALDO_DC "$@"; }
privado=/var/www/html/storage/app/private

uso() { sed -n '2,15p' "$0" >&2; exit 2; }
[ $# -ge 2 ] || uso
modo=$1 carpeta=$2
shift 2
printf '%s' "$carpeta" | grep -Eqx '[0-9]{8}-[0-9]{6}' || { echo "Carpeta no válida: $carpeta" >&2; exit 2; }
[ -d "$RESPALDO_DIR/$carpeta" ] || { echo "No existe $RESPALDO_DIR/$carpeta" >&2; exit 2; }
nombre_valido() { printf '%s' "$1" | grep -Eqx '[a-z0-9][a-z0-9-]{1,38}[a-z0-9]'; }

# Base y carpeta de archivos actuales de un objetivo, según el plan de hoy.
ubicacion() { dc exec -T app php artisan plataforma:respaldo-plan | awk -F '\t' -v o="$1" '$1 == o { print $2 "\t" $3 }'; }

borrar_temporal() { dc exec -T db sh -c "dropdb -U \"\$POSTGRES_USER\" \"$1\""; }

# En un subshell (paréntesis), con control explícito de cada paso: así no depende de set -e (que no actúa dentro de
# «if detalle=$(verificar ...)») y su trap solo borra la base temporal que creó esta misma llamada, nunca otra.
verificar() ( # $1 objetivo
	dir="$RESPALDO_DIR/$carpeta/$1"
	[ -f "$dir/SHA256SUMS" ] || { echo "sin SHA256SUMS"; exit 1; }
	(cd "$dir" && sha256sum -c --quiet SHA256SUMS) || { echo "las huellas SHA-256 no coinciden"; exit 1; }
	if [ -f "$dir/base.dump" ]; then
		temporal="verificacion_$(printf '%s' "$1" | tr '-' '_')_$$"
		creada=""
		trap '[ -z "$creada" ] || borrar_temporal "$creada" > /dev/null 2>&1 || echo "Aviso: no se pudo borrar la base temporal $creada" >&2' EXIT
		trap 'exit 130' INT TERM HUP
		if ! dc exec -T db sh -c "createdb -U \"\$POSTGRES_USER\" \"$temporal\"" > /dev/null 2>&1; then
			echo "no se pudo crear la base temporal $temporal (¿ya existe?): no se restauró ni se borró nada"
			exit 1
		fi
		creada=$temporal
		dc_entrada exec -T db sh -c "pg_restore -U \"\$POSTGRES_USER\" --no-owner -d \"$creada\"" < "$dir/base.dump" > /dev/null 2>&1 \
			|| { echo "pg_restore terminó con errores"; exit 1; }
		migraciones=$(dc exec -T db sh -c "psql -U \"\$POSTGRES_USER\" -d \"$creada\" -Atc 'select count(*) from migrations'" 2>/dev/null || echo 0)
		[ "${migraciones:-0}" -gt 0 ] 2>/dev/null || { echo "la base restaurada no tiene migraciones"; exit 1; }
		# Borrado explícito para saber si salió bien; después el trap ya no tiene nada que borrar.
		borrada=$creada
		creada=""
		borrar_temporal "$borrada" > /dev/null 2>&1 || { echo "no se pudo borrar la base temporal $borrada"; exit 1; }
	fi
	if [ -f "$dir/archivos.tar.gz" ]; then
		tar -tzf "$dir/archivos.tar.gz" > /dev/null || { echo "el paquete de archivos está dañado"; exit 1; }
	fi
	echo "base y archivos restaurables"
)

case "$modo" in
verificar)
	[ $# -gt 0 ] || set -- $(cd "$RESPALDO_DIR/$carpeta" && ls -d -- */ 2>/dev/null | tr -d / | grep -vx otras || true)
	fallos=0
	for objetivo in "$@"; do
		nombre_valido "$objetivo" || { echo "Objetivo no válido: $objetivo" >&2; fallos=$((fallos + 1)); continue; }
		if detalle=$(verificar "$objetivo" 2>&1); then resultado=correcta; else resultado=fallida; fallos=$((fallos + 1)); fi
		detalle=$(printf '%s' "$detalle" | tail -n 1 | cut -c1-500)
		echo "$objetivo: $resultado ($detalle)"
		dc exec -T app php artisan plataforma:registrar-respaldo "$objetivo" "$carpeta" --verificacion="$resultado" --detalle="$detalle" > /dev/null \
			|| echo "Aviso: no se pudo registrar la verificación de $objetivo." >&2
	done
	[ "$fallos" -eq 0 ]
	;;
restaurar)
	[ $# -eq 1 ] || uso
	objetivo=$1
	nombre_valido "$objetivo" || { echo "Objetivo no válido: $objetivo" >&2; exit 2; }
	dir="$RESPALDO_DIR/$carpeta/$objetivo"
	[ -d "$dir" ] || { echo "No hay respaldo de $objetivo en $carpeta" >&2; exit 2; }
	actual=$(ubicacion "$objetivo")
	[ -n "$actual" ] || { echo "$objetivo no figura en el plan de respaldo actual (¿está aprovisionada?)" >&2; exit 2; }
	base=$(printf '%s' "$actual" | cut -f1) archivos=$(printf '%s' "$actual" | cut -f2)
	mensaje=$(verificar "$objetivo" 2>&1) || { echo "El respaldo no pasa la verificación: $mensaje" >&2; exit 1; }

	echo "Se restaurará $objetivo (base $base) al estado de $carpeta. Lo actual se guarda antes y no se borra."
	confirmacion=${RESPALDO_CONFIRMAR:-}
	if [ -z "$confirmacion" ]; then
		printf 'Escribe «%s» para continuar: ' "$objetivo"
		read -r confirmacion
	fi
	[ "$confirmacion" = "$objetivo" ] || { echo "No coincide: no se restauró nada." >&2; exit 1; }

	antes="$RESPALDO_DIR/antes-de-restaurar-$(date +%Y%m%d-%H%M%S)-$objetivo"
	mkdir -p "$antes"
	chmod 700 "$antes"
	dc exec -T db sh -c "pg_dump -U \"\$POSTGRES_USER\" -Fc \"$base\"" > "$antes/base.dump"
	echo "Estado actual guardado en $antes"

	if [ -f "$dir/base.dump" ]; then
		dc_entrada exec -T db sh -c "pg_restore -U \"\$POSTGRES_USER\" --no-owner --clean --if-exists --single-transaction -d \"$base\"" < "$dir/base.dump"
		echo "Base $base restaurada."
	fi
	if [ -f "$dir/archivos.tar.gz" ] && [ "$archivos" != "-" ]; then
		if [ "$archivos" = "." ]; then
			dc exec -T app tar -czhf - -C "$privado" --exclude=./instituciones . > "$antes/archivos.tar.gz"
			dc_entrada exec -T app tar -xzf - -C "$privado" < "$dir/archivos.tar.gz"
		else
			apartada="$archivos.antes-de-restaurar-$(date +%Y%m%d-%H%M%S)"
			dc exec -T app sh -c "if [ -e '$privado/$archivos' ]; then mv '$privado/$archivos' '$privado/$apartada'; fi"
			dc_entrada exec -T app tar -xzf - -C "$privado" < "$dir/archivos.tar.gz"
			echo "Archivos restaurados; los anteriores quedan en storage/app/private/$apartada"
		fi
	fi
	case "$objetivo" in
	plataforma) dc exec -T app php artisan migrate --database=plataforma --path=database/migrations/plataforma --force ;;
	principal) dc exec -T app php artisan migrate --force ;;
	*) dc exec -T app php artisan plataforma:migrar-instituciones --institucion="$objetivo" ;;
	esac
	echo "Restauración de $objetivo terminada."
	;;
*) uso ;;
esac
