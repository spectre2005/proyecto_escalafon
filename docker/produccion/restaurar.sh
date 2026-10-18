#!/bin/sh
# Respaldos de respaldar.sh (respaldos/AAAAMMDD-HHMMSS/<objetivo>/).
#
#   sh docker/produccion/restaurar.sh verificar CARPETA [objetivo ...]
#       Prueba de restauración que no toca nada real: comprueba SHA256SUMS, restaura cada base en una base temporal
#       (que se borra siempre), mira que tenga sus migraciones y que el paquete de archivos se lea. Registra el
#       resultado para el panel (plataforma:registrar-respaldo --verificacion). Sin objetivos: todos los de la carpeta.
#
#   sh docker/produccion/restaurar.sh restaurar CARPETA objetivo
#       Restaura UNA institución (o «plataforma» o «principal»). Conviene suspenderla antes desde el panel. Pide
#       escribir el objetivo para confirmar (o RESPALDO_CONFIRMAR=objetivo). Antes guarda su estado actual en
#       respaldos/antes-de-restaurar-<fecha>-<objetivo>/ y aparta sus archivos actuales (no los borra). Después aplica
#       las migraciones pendientes, por si el respaldo es anterior al código.
set -eu
cd "$(dirname "$0")/../.."
RESPALDO_DC=${RESPALDO_DC:-docker compose --env-file .env.produccion -f docker-compose.produccion.yml}
dc() { $RESPALDO_DC "$@" < /dev/null; }
dc_entrada() { $RESPALDO_DC "$@"; }
privado=/var/www/html/storage/app/private

uso() { sed -n '2,15p' "$0" >&2; exit 2; }
[ $# -ge 2 ] || uso
modo=$1 carpeta=$2
shift 2
printf '%s' "$carpeta" | grep -Eqx '[0-9]{8}-[0-9]{6}' || { echo "Carpeta no válida: $carpeta" >&2; exit 2; }
[ -d "respaldos/$carpeta" ] || { echo "No existe respaldos/$carpeta" >&2; exit 2; }
nombre_valido() { printf '%s' "$1" | grep -Eqx '[a-z0-9][a-z0-9-]{1,38}[a-z0-9]'; }

# Base y carpeta de archivos actuales de un objetivo, según el plan de hoy.
ubicacion() { dc exec -T app php artisan plataforma:respaldo-plan | awk -F '\t' -v o="$1" '$1 == o { print $2 "\t" $3 }'; }

verificar() { # $1 objetivo
	dir="respaldos/$carpeta/$1"
	[ -f "$dir/SHA256SUMS" ] || { echo "sin SHA256SUMS"; return 1; }
	(cd "$dir" && sha256sum -c --quiet SHA256SUMS) || { echo "las huellas SHA-256 no coinciden"; return 1; }
	if [ -f "$dir/base.dump" ]; then
		temporal="verificacion_$(printf '%s' "$1" | tr '-' '_')_$$"
		dc exec -T db sh -c "createdb -U \"\$POSTGRES_USER\" \"$temporal\""
		resultado=0
		dc_entrada exec -T db sh -c "pg_restore -U \"\$POSTGRES_USER\" --no-owner -d \"$temporal\"" < "$dir/base.dump" > /dev/null 2>&1 || resultado=1
		migraciones=$(dc exec -T db sh -c "psql -U \"\$POSTGRES_USER\" -d \"$temporal\" -Atc 'select count(*) from migrations'" 2>/dev/null || echo 0)
		dc exec -T db sh -c "dropdb -U \"\$POSTGRES_USER\" --if-exists \"$temporal\""
		[ "$resultado" -eq 0 ] || { echo "pg_restore terminó con errores"; return 1; }
		[ "${migraciones:-0}" -gt 0 ] 2>/dev/null || { echo "la base restaurada no tiene migraciones"; return 1; }
	fi
	if [ -f "$dir/archivos.tar.gz" ]; then
		tar -tzf "$dir/archivos.tar.gz" > /dev/null || { echo "el paquete de archivos está dañado"; return 1; }
	fi
	echo "base y archivos restaurables"
}

case "$modo" in
verificar)
	[ $# -gt 0 ] || set -- $(cd "respaldos/$carpeta" && ls -d -- */ 2>/dev/null | tr -d / | grep -vx otras || true)
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
	dir="respaldos/$carpeta/$objetivo"
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

	antes="respaldos/antes-de-restaurar-$(date +%Y%m%d-%H%M%S)-$objetivo"
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
