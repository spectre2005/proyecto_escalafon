#!/bin/sh
# Respaldo por institución: para cada objetivo de «php artisan plataforma:respaldo-plan» (la plataforma, cada
# institución aprovisionada y la base principal si no es de ninguna) guarda en RESPALDO_DIR/AAAAMMDD-HHMMSS/<objetivo>/
# su base (pg_dump, formato custom), sus archivos privados (archivos.tar.gz) y SHA256SUMS. Todo con umask 077: carpetas
# 0700 y archivos 0600. Las bases del servidor que no
# figuran en el plan van a otras/, para no perder nada.
#
# Copia externa (opcional): si RESPALDO_REMOTO está definido, sube la carpeta con rclone (imagen Docker, sin
# instalarlo) y la comprueba con «rclone cryptcheck». Solo a un remoto de tipo crypt: Google Drive u otro servicio
# guarda los archivos cifrados y con nombres ilegibles. Si el remoto no es crypt, no se sube nada y queda como fallo.
#
# Cada resultado se registra en la base de la plataforma (plataforma:registrar-respaldo) y el panel lo muestra.
# Programarlo con cron (docs/PRODUCCION.md). Termina con error si algo falló.
#
# Variables (entorno o líneas RESPALDO_* de .env.produccion):
#   RESPALDO_REMOTO        remoto:ruta de rclone, de tipo crypt (vacío: sin copia externa)
#   RESPALDO_RCLONE_CONF   archivo de configuración de rclone (por defecto ~/.config/rclone/rclone.conf)
#   RESPALDO_DIR           carpeta de los respaldos (por defecto «respaldos», en la raíz del repositorio)
#   RESPALDO_DIAS          días que se conservan en el servidor (14); nunca se borra uno protegido
#   RESPALDO_CONSERVAR     motivo para proteger ESTE respaldo (p. ej. antes de normalizar una institución): queda con
#                          una marca .conservar, la limpieza automática no lo borra nunca (borrarlo es una decisión
#                          expresa, a mano) y no se sube a ninguna copia externa. Exige RESPALDO_REMOTO vacío y un
#                          RESPALDO_DIR fuera de todo repositorio git (.gitignore no basta: git add -f lo incluiría).
#   RESPALDO_REMOTO_DIAS   días que se conservan en la copia externa (30)
#   RESPALDO_DC            orden de docker compose (por defecto, la de producción; las pruebas usan otra)
set -eu
umask 077
cd "$(dirname "$0")/../.."

if [ -f .env.produccion ]; then
	# Solo las líneas RESPALDO_* sin espacios, sin ejecutarlas; las del entorno mandan.
	for linea in $(grep -E '^RESPALDO_[A-Z_]+=[^ ]*$' .env.produccion || true); do
		nombre=${linea%%=*}
		eval "actual=\${$nombre:-}"
		[ -n "$actual" ] || export "$linea"
	done
fi
RESPALDO_DC=${RESPALDO_DC:-docker compose --env-file .env.produccion -f docker-compose.produccion.yml}
RESPALDO_REMOTO=${RESPALDO_REMOTO:-}
RESPALDO_DIR=${RESPALDO_DIR:-respaldos}
RESPALDO_CONSERVAR=${RESPALDO_CONSERVAR:-}
RESPALDO_RCLONE_CONF=${RESPALDO_RCLONE_CONF:-$HOME/.config/rclone/rclone.conf}
RCLONE_IMAGEN=rclone/rclone:1.68.2
# Sin entrada estándar: docker compose exec la leería y, dentro de un «while read», se comería las líneas siguientes.
dc() { $RESPALDO_DC "$@" < /dev/null; }
rclone_docker() { docker run --rm -v "$RESPALDO_RCLONE_CONF:/config/rclone/rclone.conf" -v "$RESPALDO_DIR:/respaldos:ro" ${RESPALDO_RCLONE_DOCKER_ARGS:-} "$RCLONE_IMAGEN" "$@"; }

# Un respaldo protegido se comprueba antes de crear nada.
if [ -n "$RESPALDO_CONSERVAR" ]; then
	printf '%s' "$RESPALDO_CONSERVAR" | grep -Eqx '[[:print:]]{3,200}' || { echo "RESPALDO_CONSERVAR debe ser un motivo de una línea (3 a 200 caracteres)." >&2; exit 2; }
	[ -z "$RESPALDO_REMOTO" ] || { echo "Un respaldo protegido no se sube a ninguna copia externa: deja RESPALDO_REMOTO vacío." >&2; exit 2; }
	existente=$RESPALDO_DIR
	while [ ! -d "$existente" ]; do existente=$(dirname "$existente"); done
	if command -v git > /dev/null 2>&1 && git -C "$existente" rev-parse --is-inside-work-tree > /dev/null 2>&1; then
		echo "Un respaldo protegido no se guarda dentro de un repositorio git: usa un RESPALDO_DIR fuera de él." >&2
		exit 2
	fi
fi

carpeta=$(date +%Y%m%d-%H%M%S)
mkdir -p "$RESPALDO_DIR"
chmod 700 "$RESPALDO_DIR"
RESPALDO_DIR=$(cd "$RESPALDO_DIR" && pwd -P)
destino="$RESPALDO_DIR/$carpeta"
mkdir "$destino"
chmod 700 "$destino"
if [ -n "$RESPALDO_CONSERVAR" ]; then
	printf 'motivo=%s\ncreado=%s\nNo se borra automáticamente. Eliminarlo requiere una autorización expresa.\n' \
		"$RESPALDO_CONSERVAR" "$(date -Iseconds)" > "$destino/.conservar"
	echo "Respaldo protegido: $destino/.conservar"
fi
resultados="$destino/.resultados"
: > "$resultados"
fallos=0

plan=$(dc exec -T app php artisan plataforma:respaldo-plan) || { echo "No se pudo obtener el plan de respaldo." >&2; exit 1; }
bases_servidor=$(dc exec -T db sh -c 'psql -U "$POSTGRES_USER" -d postgres -Atc "select datname from pg_database where not datistemplate and datname <> '"'"'postgres'"'"'"')

en_lista() { printf '%s\n' "$2" | grep -qx "$1"; }
tamano() { wc -c < "$1" | tr -d ' '; }
huella() { sha256sum "$1" | cut -d' ' -f1; }

respaldar_base() { # $1 base $2 archivo
	dc exec -T db sh -c "pg_dump -U \"\$POSTGRES_USER\" -Fc \"$1\"" > "$2"
}

respaldar_archivos() { # $1 carpeta relativa a storage/app/private, o «.» $2 archivo
	privado=/var/www/html/storage/app/private
	if [ "$1" = "." ]; then
		dc exec -T app tar -czhf - -C "$privado" --exclude=./instituciones . > "$2"
	else
		dc exec -T app test -d "$privado/$1" || { echo "no existe la carpeta $1"; return 1; }
		dc exec -T app tar -czhf - -C "$privado" "$1" > "$2"
	fi
}

cubiertas=""
printf '%s\n' "$plan" | while IFS="$(printf '\t')" read -r objetivo base archivos; do
	[ -n "$objetivo" ] || continue
	dir="$destino/$objetivo"
	mkdir -p "$dir"
	estado=correcto detalle="" bytes="" sha="" abytes="" asha=""
	if en_lista "$base" "$bases_servidor"; then
		if respaldar_base "$base" "$dir/base.dump"; then
			bytes=$(tamano "$dir/base.dump") sha=$(huella "$dir/base.dump")
		else
			estado=fallido detalle="pg_dump de $base falló"
		fi
	else
		estado=fallido detalle="la base $base no existe en el servidor"
	fi
	if [ "$archivos" != "-" ]; then
		if mensaje=$(respaldar_archivos "$archivos" "$dir/archivos.tar.gz" 2>&1); then
			abytes=$(tamano "$dir/archivos.tar.gz") asha=$(huella "$dir/archivos.tar.gz")
		else
			estado=fallido detalle="${detalle:+$detalle; }archivos: ${mensaje:-tar falló}"
			rm -f "$dir/archivos.tar.gz"
		fi
	fi
	(cd "$dir" && sha256sum ./* > SHA256SUMS 2>/dev/null || true)
	printf '%s|%s|%s|%s|%s|%s|%s|%s\n' "$objetivo" "$estado" "$base" "$bytes" "$sha" "$abytes" "$asha" "$detalle" >> "$resultados"
	echo "$objetivo: $estado${detalle:+ ($detalle)}"
done

# Bases del servidor que el plan no cubre (p. ej. una institución sin registrar): se respaldan igual, aparte.
cubiertas=$(cut -d'|' -f3 "$resultados")
for base in $bases_servidor; do
	en_lista "$base" "$cubiertas" && continue
	mkdir -p "$destino/otras"
	if respaldar_base "$base" "$destino/otras/$base.dump"; then
		echo "Aviso: la base $base no es de ninguna institución; respaldada en otras/."
	else
		echo "Aviso: falló el respaldo de la base $base (otras/)." >&2
		fallos=$((fallos + 1))
	fi
done
[ ! -d "$destino/otras" ] || (cd "$destino/otras" && sha256sum ./* > SHA256SUMS)

# Copia externa cifrada.
externa=no_configurada
detalle_externa=""
if [ -n "$RESPALDO_REMOTO" ]; then
	remoto=${RESPALDO_REMOTO%%:*}
	ruta=${RESPALDO_REMOTO#*:}
	if [ "$ruta" = "$RESPALDO_REMOTO" ] || [ -z "$ruta" ] || [ "$ruta" = "/" ]; then
		# Se borran las copias antiguas de esta ruta: nunca la raíz del remoto, donde puede haber otros archivos.
		externa=fallida detalle_externa="RESPALDO_REMOTO debe ser remoto:carpeta (una carpeta solo para respaldos)"
	elif [ ! -f "$RESPALDO_RCLONE_CONF" ]; then
		externa=fallida detalle_externa="no existe $RESPALDO_RCLONE_CONF"
	elif ! rclone_docker config show "$remoto" 2>/dev/null | grep -qx 'type = crypt'; then
		externa=fallida detalle_externa="el remoto $remoto no es de tipo crypt: no se sube sin cifrar"
	elif rclone_docker copy "/respaldos/$carpeta" "$RESPALDO_REMOTO/$carpeta" --exclude .resultados \
		&& rclone_docker cryptcheck "/respaldos/$carpeta" "$RESPALDO_REMOTO/$carpeta" --exclude .resultados; then
		externa=correcta
		rclone_docker delete --min-age "${RESPALDO_REMOTO_DIAS:-30}d" "$RESPALDO_REMOTO" || echo "Aviso: no se pudieron borrar copias externas antiguas." >&2
		rclone_docker rmdirs --leave-root "$RESPALDO_REMOTO" || true
	else
		externa=fallida detalle_externa="rclone copy o cryptcheck falló"
	fi
	echo "Copia externa: $externa${detalle_externa:+ ($detalle_externa)}"
fi

while IFS='|' read -r objetivo estado base bytes sha abytes asha detalle; do
	[ "$estado" = correcto ] || fallos=$((fallos + 1))
	todo_detalle=$(printf '%s' "$detalle${detalle_externa:+${detalle:+; }copia externa: $detalle_externa}" | cut -c1-500)
	set -- plataforma:registrar-respaldo "$objetivo" "$carpeta" --estado="$estado" --copia-externa="$externa"
	[ -z "$base" ] || set -- "$@" --base="$base"
	[ -z "$bytes" ] || set -- "$@" --base-bytes="$bytes" --base-sha256="$sha"
	[ -z "$abytes" ] || set -- "$@" --archivos-bytes="$abytes" --archivos-sha256="$asha"
	[ -z "$todo_detalle" ] || set -- "$@" --detalle="$todo_detalle"
	dc exec -T app php artisan "$@" > /dev/null || { echo "No se pudo registrar el respaldo de $objetivo." >&2; fallos=$((fallos + 1)); }
done < "$resultados"
[ "$externa" != fallida ] || fallos=$((fallos + 1))
rm -f "$resultados"

# Solo carpetas de respaldo (AAAAMMDD-HHMMSS) más antiguas que RESPALDO_DIAS y sin la marca .conservar.
find "$RESPALDO_DIR" -mindepth 1 -maxdepth 1 -type d -name '20[0-9][0-9][0-9][0-9][0-9][0-9]-[0-9][0-9][0-9][0-9][0-9][0-9]' -mtime +"${RESPALDO_DIAS:-14}" \
	| while IFS= read -r antiguo; do
		if [ -e "$antiguo/.conservar" ]; then
			echo "Se conserva $(basename "$antiguo") (protegido con .conservar)."
		else
			rm -rf "$antiguo"
		fi
	done
echo "Respaldo en $destino"
[ "$fallos" -eq 0 ] || { echo "$fallos problema(s) en el respaldo: revisa el panel de plataforma." >&2; exit 1; }
