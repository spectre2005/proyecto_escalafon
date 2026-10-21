#!/bin/sh
# Pruebas aisladas de respaldar.sh y restaurar.sh verificar. Se ejecutan en el host, sin Docker ni PostgreSQL:
#
#   sh docker/produccion/pruebas/respaldos.sh
#
# RESPALDO_DC apunta a un «docker compose» falso que ejecuta las órdenes aquí, con createdb, pg_restore, psql, dropdb,
# pg_dump y php falsos sobre una carpeta temporal (cada base es un archivo). El storage es otra carpeta temporal y
# «docker» está sustituido por uno que falla y deja constancia (ninguna copia externa). Nada toca bases, archivos ni
# respaldos reales; la carpeta temporal se borra al terminar.
set -eu
REPO=$(cd "$(dirname "$0")/../../.." && pwd -P)
[ ! -e "$REPO/.env.produccion" ] || { echo "Hay un .env.produccion: estas pruebas no se ejecutan junto a una configuración real." >&2; exit 2; }
respaldos_en_repo_antes=$([ -e "$REPO/respaldos" ] && echo si || echo no)

T=$(mktemp -d "${TMPDIR:-/tmp}/pruebas-respaldos-XXXXXX")
trap 'rm -rf "$T"' EXIT
export FALSO="$T/falso"
mkdir -p "$FALSO/bin" "$FALSO/pg" "$FALSO/privado/documentos" "$FALSO/privado/instituciones/uniq" "$T/hostbin"
: > "$FALSO/llamadas"
: > "$FALSO/pg.log"
: > "$FALSO/registros"
: > "$FALSO/docker"

# Storage falso: la carpeta de UNIQ enlaza a la común, como en el entorno de desarrollo.
printf 'pdf de prueba\n' > "$FALSO/privado/documentos/a.pdf"
ln -s ../../documentos "$FALSO/privado/instituciones/uniq/documentos"
# Bases «reales» falsas: nunca deben cambiar.
printf 'real-uniq\n' > "$FALSO/pg/escalafon"
printf 'real-plataforma\n' > "$FALSO/pg/escalafon_plataforma"

cat > "$FALSO/dc.sh" <<'EOF'
#!/bin/sh
# docker compose falso: «exec -T servicio orden...» se ejecuta aquí, con la ruta del storage del contenedor cambiada.
[ "$1" = exec ] || { echo "dc falso: solo exec" >&2; exit 99; }
shift
[ "$1" != -T ] || shift
servicio=$1
shift
privado=/var/www/html/storage/app/private
n=$#
while [ "$n" -gt 0 ]; do
	a=$1
	shift
	n=$((n - 1))
	case "$a" in *"$privado"*) a=$(printf '%s' "$a" | sed "s#$privado#$FALSO/privado#g") ;; esac
	set -- "$@" "$a"
done
printf '%s\t%s\n' "$servicio" "$*" >> "$FALSO/llamadas"
PATH="$FALSO/bin:$PATH" POSTGRES_USER=prueba exec "$@"
EOF

cat > "$FALSO/bin/createdb" <<'EOF'
#!/bin/sh
for b; do :; done
echo "createdb $b" >> "$FALSO/pg.log"
# Simula que la base temporal ya existía antes de esta ejecución.
[ -z "${FALSO_TEMPORAL_PREEXISTE:-}" ] || printf 'preexistente\n' > "$FALSO/pg/$b"
[ ! -e "$FALSO/pg/$b" ] || { echo "createdb: la base \"$b\" ya existe" >&2; exit 1; }
[ -z "${FALSO_CREATEDB_FALLA:-}" ] || { echo "createdb: fallo simulado" >&2; exit 1; }
printf 'vacia\n' > "$FALSO/pg/$b"
EOF

cat > "$FALSO/bin/pg_restore" <<'EOF'
#!/bin/sh
d="" previo=""
for a; do [ "$previo" != -d ] || d=$a; previo=$a; done
echo "pg_restore $d" >> "$FALSO/pg.log"
cat > /dev/null
[ -e "$FALSO/pg/$d" ] || exit 1
# Interrupción (kill, tiempo límite): TERM al subshell de verificar(), el padre del «sh -c» que simula el contenedor.
[ -z "${FALSO_INTERRUMPIR:-}" ] || { kill -TERM "$(ps -o ppid= -p "$PPID" | tr -d ' ')"; sleep 1; exit 1; }
[ -z "${FALSO_PG_RESTORE_FALLA:-}" ] || { printf 'a-medias\n' > "$FALSO/pg/$d"; exit 1; }
printf 'restaurada\n' > "$FALSO/pg/$d"
EOF

cat > "$FALSO/bin/psql" <<'EOF'
#!/bin/sh
d="" sql="" previo=""
for a; do [ "$previo" != -d ] || d=$a; [ "$previo" != -Atc ] || sql=$a; previo=$a; done
case "$sql" in
*pg_database*) ls "$FALSO/pg" ;;
*migrations*) [ "$(cat "$FALSO/pg/$d" 2>/dev/null)" = restaurada ] && [ -z "${FALSO_SIN_MIGRACIONES:-}" ] && echo 5 || exit 1 ;;
*) exit 1 ;;
esac
EOF

cat > "$FALSO/bin/dropdb" <<'EOF'
#!/bin/sh
for b; do :; done
echo "dropdb $b" >> "$FALSO/pg.log"
[ -z "${FALSO_DROPDB_FALLA:-}" ] || exit 1
[ -e "$FALSO/pg/$b" ] || exit 1
rm -f "$FALSO/pg/$b"
EOF

cat > "$FALSO/bin/pg_dump" <<'EOF'
#!/bin/sh
for b; do :; done
[ -e "$FALSO/pg/$b" ] || exit 1
printf 'volcado de %s\n' "$b"
EOF

cat > "$FALSO/bin/php" <<'EOF'
#!/bin/sh
[ "$1" = artisan ] || exit 1
shift
case "$1" in
plataforma:respaldo-plan) printf 'plataforma\tescalafon_plataforma\t-\nuniq\tescalafon\tinstituciones/uniq\n' ;;
plataforma:registrar-respaldo) shift; echo "$*" >> "$FALSO/registros" ;;
env) echo "INFO  The application environment is [${FALSO_APP_ENV:-production}]." ;;
*) exit 1 ;;
esac
EOF

cat > "$T/hostbin/docker" <<'EOF'
#!/bin/sh
echo "$*" >> "$FALSO/docker"
exit 1
EOF
# docker falso con rclone simulado para la copia externa: nunca sale a la red. Exige la clave por --password-command
# y simula, según FALSO_*, un remoto de otro tipo, una autorización rechazada, una subida o una comprobación fallidas.
mkdir -p "$T/rclonebin"
cat > "$T/rclonebin/docker" <<'FIN_RCLONE'
#!/bin/sh
echo "$*" >> "$FALSO/rclone.log"
clave=""
while [ "$#" -gt 0 ] && [ "$1" != rclone/rclone:1.68.2 ]; do
	[ "$1" != -v ] || case "$2" in *:/run/clave:ro) clave=${2%:/run/clave:ro} ;; esac
	shift
done
[ "$#" -gt 0 ] || exit 99
shift
pc=""
while [ "$#" -gt 0 ]; do
	case "$1" in
	--password-command) pc=$2; shift 2 ;;
	--config | --cache-dir | --contimeout | --timeout | --retries) shift 2 ;;
	*) break ;;
	esac
done
[ "$pc" = "cat /run/clave" ] && [ -r "$clave" ] || { echo "falso: rclone sin su clave" >&2; exit 98; }
[ "$(cat "$clave")" = clave-de-prueba ] || { echo "CRITICAL: Failed to load config file: password incorrect" >&2; exit 1; }
case "$1" in
config) [ "$2" = show ] || exit 97; printf '[%s]\ntype = %s\npassword = SECRETO-FALSO\n' "$3" "${FALSO_TIPO:-crypt}" ;;
copy)
	case "${FALSO_COPIA:-}" in
	auth) echo 'ERROR : couldn'"'"'t fetch token: oauth2: "invalid_grant" "Token has been expired or revoked."' >&2; exit 1 ;;
	falla) echo 'ERROR : Failed to copy: googleapi: Error 500: backendError' >&2; exit 1 ;;
	esac ;;
cryptcheck) [ -z "${FALSO_CHECK:-}" ] || { echo 'ERROR : 1 differences found' >&2; exit 1; } ;;
delete | rmdirs) ;;
*) exit 97 ;;
esac
FIN_RCLONE
chmod +x "$FALSO/bin/"* "$T/hostbin/docker" "$T/rclonebin/docker"

export RESPALDO_DC="sh $FALSO/dc.sh"
export PATH="$T/hostbin:$PATH"
export RESPALDO_REMOTO=
unset RESPALDO_CONSERVAR RESPALDO_DIAS RESPALDO_DIR 2>/dev/null || true

aprobadas=0 fallidas=0
ok() { aprobadas=$((aprobadas + 1)); echo "  ok: $1"; }
falla() { fallidas=$((fallidas + 1)); echo "  FALLA: $1"; }
comprobar() { # $1 descripción, resto: orden
	descripcion=$1
	shift
	if "$@"; then ok "$descripcion"; else falla "$descripcion"; fi
}
respaldar() { sh "$REPO/docker/produccion/respaldar.sh" > "$T/salida" 2>&1; }
restaurar() { sh "$REPO/docker/produccion/restaurar.sh" "$@" > "$T/salida" 2>&1; }
salida_tiene() { grep -qF -- "$1" "$T/salida"; }
log_desde() { tail -n +"$1" "$FALSO/pg.log"; }
lineas() { wc -l < "$1" | tr -d ' '; }
ultima_carpeta() { ls "$1" | grep -Ex '[0-9]{8}-[0-9]{6}' | tail -n 1; }
sin_temporales() { ! ls "$FALSO/pg" | grep -q '^verificacion_'; }
permisos_privados() { [ -z "$(find "$1" \( -type d ! -perm 700 \) -o \( -type f ! -perm 600 \))" ]; }
bases_reales_intactas() { [ "$(cat "$FALSO/pg/escalafon")" = real-uniq ] && [ "$(cat "$FALSO/pg/escalafon_plataforma")" = real-plataforma ]; }

echo "respaldar.sh: respaldo normal"
export RESPALDO_DIR="$T/normales"
comprobar "termina bien" respaldar
normal=$(ultima_carpeta "$RESPALDO_DIR")
comprobar "crea la carpeta del respaldo" test -n "$normal"
comprobar "carpetas 0700 y archivos 0600" permisos_privados "$RESPALDO_DIR"
comprobar "el paquete de uniq trae el archivo real tras el enlace (tar -h)" \
	sh -c "tar -xzOf '$RESPALDO_DIR/$normal/uniq/archivos.tar.gz' instituciones/uniq/documentos/a.pdf | grep -qx 'pdf de prueba'"
comprobar "registra uniq sin copia externa" grep -q "^uniq $normal --estado=correcto --copia-externa=no_configurada" "$FALSO/registros"
comprobar "sin marca .conservar" test ! -e "$RESPALDO_DIR/$normal/.conservar"
comprobar "no llama a docker (ninguna copia externa)" test ! -s "$FALSO/docker"

echo "restaurar.sh verificar: caso correcto"
inicio=$(($(lineas "$FALSO/pg.log") + 1))
comprobar "verifica uniq" restaurar verificar "$normal" uniq
comprobar "informa «correcta»" salida_tiene "uniq: correcta (base y archivos restaurables)"
temporal=$(log_desde "$inicio" | awk '$1 == "createdb" { print $2; exit }')
comprobar "crea una base temporal verificacion_uniq_*" sh -c "printf '%s' '$temporal' | grep -Eqx 'verificacion_uniq_[0-9]+'"
comprobar "restaura en la temporal y la borra, en ese orden" \
	sh -c "[ \"\$(tail -n +$inicio '$FALSO/pg.log' | tr '\n' ';')\" = 'createdb $temporal;pg_restore $temporal;dropdb $temporal;' ]"
comprobar "no queda ninguna base temporal" sin_temporales
comprobar "registra la verificación correcta" grep -q "^uniq $normal --verificacion=correcta" "$FALSO/registros"

echo "restaurar.sh verificar: la base temporal ya existía"
inicio=$(($(lineas "$FALSO/pg.log") + 1))
if FALSO_TEMPORAL_PREEXISTE=1 restaurar verificar "$normal" uniq; then falla "debía fallar"; else ok "falla"; fi
comprobar "lo explica" salida_tiene "no se pudo crear la base temporal"
preexistente=$(log_desde "$inicio" | awk '$1 == "createdb" { print $2; exit }')
comprobar "ni pg_restore ni dropdb tras el fallo" sh -c "! tail -n +$inicio '$FALSO/pg.log' | grep -Eq '^(pg_restore|dropdb) '"
comprobar "la base preexistente sigue intacta" sh -c "[ \"\$(cat '$FALSO/pg/$preexistente')\" = preexistente ]"
comprobar "registra la verificación fallida" grep -q "^uniq $normal --verificacion=fallida" "$FALSO/registros"
rm -f "$FALSO/pg/$preexistente"

echo "restaurar.sh verificar: createdb falla por otro motivo"
inicio=$(($(lineas "$FALSO/pg.log") + 1))
if FALSO_CREATEDB_FALLA=1 restaurar verificar "$normal" uniq; then falla "debía fallar"; else ok "falla"; fi
comprobar "ni pg_restore ni dropdb" sh -c "! tail -n +$inicio '$FALSO/pg.log' | grep -Eq '^(pg_restore|dropdb) '"
comprobar "no queda ninguna base temporal" sin_temporales

echo "restaurar.sh verificar: pg_restore falla"
inicio=$(($(lineas "$FALSO/pg.log") + 1))
if FALSO_PG_RESTORE_FALLA=1 restaurar verificar "$normal" uniq; then falla "debía fallar"; else ok "falla"; fi
comprobar "lo explica" salida_tiene "pg_restore terminó con errores"
temporal=$(log_desde "$inicio" | awk '$1 == "createdb" { print $2; exit }')
comprobar "borra la temporal que creó" sh -c "tail -n +$inicio '$FALSO/pg.log' | grep -qx 'dropdb $temporal'"
comprobar "no queda ninguna base temporal" sin_temporales

echo "restaurar.sh verificar: la base restaurada no tiene migraciones"
if FALSO_SIN_MIGRACIONES=1 restaurar verificar "$normal" uniq; then falla "debía fallar"; else ok "falla"; fi
comprobar "lo explica" salida_tiene "no tiene migraciones"
comprobar "no queda ninguna base temporal" sin_temporales

echo "restaurar.sh verificar: interrupción (TERM) durante pg_restore"
inicio=$(($(lineas "$FALSO/pg.log") + 1))
FALSO_INTERRUMPIR=1 sh "$REPO/docker/produccion/restaurar.sh" verificar "$normal" uniq > "$T/salida" 2>&1 || true
temporal=$(log_desde "$inicio" | awk '$1 == "createdb" { print $2; exit }')
# La limpieza del trap puede terminar un instante después que el proceso principal.
i=0
while ! sin_temporales && [ "$i" -lt 50 ]; do sleep 0.1; i=$((i + 1)); done
comprobar "se interrumpió durante pg_restore" sh -c "tail -n +$inicio '$FALSO/pg.log' | grep -qx 'pg_restore $temporal'"
comprobar "borra la temporal que creó" sh -c "tail -n +$inicio '$FALSO/pg.log' | grep -qx 'dropdb $temporal'"
comprobar "no queda ninguna base temporal" sin_temporales

echo "restaurar.sh verificar: dropdb falla"
if FALSO_DROPDB_FALLA=1 restaurar verificar "$normal" uniq; then falla "debía fallar"; else ok "falla"; fi
comprobar "lo informa como fallida" salida_tiene "no se pudo borrar la base temporal"
rm -f "$FALSO/pg"/verificacion_*

echo "restaurar.sh verificar: nunca borra una base que no creó"
comprobar "dropdb solo sobre temporales creadas en la misma ejecución" \
	sh -c "awk '\$1 == \"dropdb\" && \$2 !~ /^verificacion_uniq_[0-9]+\$/ { exit 1 }' '$FALSO/pg.log'"
comprobar "las bases reales siguen intactas" bases_reales_intactas

echo "respaldar.sh: respaldo protegido"
mkdir -p "$T/repo"
git -C "$T/repo" init -q
llamadas=$(lineas "$FALSO/llamadas")
if RESPALDO_CONSERVAR="antes de normalizar" RESPALDO_DIR="$T/repo/respaldos" respaldar; then falla "dentro de git debía rechazarse"; else ok "rechaza un RESPALDO_DIR dentro de un repositorio git"; fi
comprobar "no crea nada dentro del repositorio" test ! -e "$T/repo/respaldos"
if RESPALDO_CONSERVAR="antes de normalizar" RESPALDO_DIR="$T/protegidos" RESPALDO_REMOTO="remoto:respaldos" respaldar; then falla "con copia externa debía rechazarse"; else ok "rechaza una copia externa"; fi
comprobar "no crea nada con la copia externa pedida" test ! -e "$T/protegidos"
if RESPALDO_CONSERVAR="x" RESPALDO_DIR="$T/protegidos" respaldar; then falla "motivo corto debía rechazarse"; else ok "exige un motivo"; fi
comprobar "no llamó a nada antes de rechazar" test "$(lineas "$FALSO/llamadas")" = "$llamadas"
export RESPALDO_DIR="$T/protegidos"
comprobar "fuera de git y sin copia externa, termina bien" env RESPALDO_CONSERVAR="antes de normalizar uniq" sh "$REPO/docker/produccion/respaldar.sh"
protegido=$(ultima_carpeta "$RESPALDO_DIR")
comprobar "lleva la marca .conservar con el motivo" grep -q '^motivo=antes de normalizar uniq$' "$RESPALDO_DIR/$protegido/.conservar"
comprobar "carpetas 0700 y archivos 0600 (también .conservar)" permisos_privados "$RESPALDO_DIR"
comprobar "registra copia externa no_configurada" grep -q "^uniq $protegido --estado=correcto --copia-externa=no_configurada" "$FALSO/registros"
comprobar "no llama a docker" test ! -s "$FALSO/docker"

echo "restaurar.sh verificar: respaldo protegido"
comprobar "verifica todos sus objetivos" restaurar verificar "$protegido"
comprobar "plataforma correcta" salida_tiene "plataforma: correcta"
comprobar "uniq correcta" salida_tiene "uniq: correcta"
comprobar ".conservar no se toma por un objetivo" sh -c "! grep -q 'conservar' '$T/salida'"
comprobar "no queda ninguna base temporal" sin_temporales

echo "respaldar.sh: la limpieza nunca borra un respaldo protegido"
sleep 1
comprobar "un respaldo normal más" respaldar
viejo=$(ultima_carpeta "$RESPALDO_DIR")
touch -d '60 days ago' "$RESPALDO_DIR/$protegido" "$RESPALDO_DIR/$viejo"
sleep 1
comprobar "otro respaldo con la retención predeterminada (14 días)" respaldar
comprobar "el protegido de hace 60 días sigue" test -f "$RESPALDO_DIR/$protegido/.conservar"
comprobar "lo informa" salida_tiene "Se conserva $protegido (protegido con .conservar)."
comprobar "el normal de hace 60 días se borró" test ! -e "$RESPALDO_DIR/$viejo"
comprobar "el protegido sigue verificable" restaurar verificar "$protegido" uniq

echo "respaldar.sh: copia externa (rclone simulado, sin red)"
mkdir -p "$T/rclone" "$T/claves"
printf '# Encrypted rclone\nRCLONE_ENCRYPT_V0:\nCONTENIDO-CIFRADO-FALSO\n' > "$T/rclone/rclone.conf"
printf 'clave-de-prueba' > "$T/claves/rclone.clave"
chmod 600 "$T/rclone/rclone.conf" "$T/claves/rclone.clave"
printf 'RESPALDO_REMOTO=cifrado:copias\nRESPALDO_RCLONE_CONF=%s\nRESPALDO_RCLONE_CLAVE=%s\n' \
	"$T/rclone/rclone.conf" "$T/claves/rclone.clave" > "$T/entorno"
export RESPALDO_DIR="$T/externas"
: > "$FALSO/rclone.log"
# Respaldo con el rclone simulado y el entorno de prueba, sin RESPALDO_REMOTO en el entorno (lo da el archivo).
remota() { env -u RESPALDO_REMOTO RESPALDO_ENTORNO="$T/entorno" PATH="$T/rclonebin:$PATH" "$@" sh "$REPO/docker/produccion/respaldar.sh" > "$T/salida" 2>&1; }
ultimo_uniq() { grep '^uniq ' "$FALSO/registros" | tail -n 1; }
sin_secretos() { ! grep -Eq 'clave-de-prueba|SECRETO-FALSO' "$T/salida"; }

# RESPALDO_REMOTO sigue exportado y vacío (arriba): manda sobre el remoto del archivo.
if env RESPALDO_ENTORNO="$T/entorno" PATH="$T/rclonebin:$PATH" sh "$REPO/docker/produccion/respaldar.sh" > "$T/salida" 2>&1; then
	ok "variable presente y vacía: termina bien"
else falla "variable presente y vacía: debía terminar bien"; fi
comprobar "la variable vacía manda sobre el archivo: sin copia externa" sh -c "grep '^uniq ' '$FALSO/registros' | tail -n 1 | grep -q -- '--copia-externa=no_configurada'"
comprobar "y no llama a rclone" test ! -s "$FALSO/rclone.log"

sleep 1
comprobar "destino bien configurado: termina bien" remota
comprobar "registra la copia externa correcta" sh -c "grep '^uniq ' '$FALSO/registros' | tail -n 1 | grep -q -- '--estado=correcto --copia-externa=correcta'"
comprobar "sube y después comprueba con cryptcheck" sh -c "grep -n ' copy /respaldos/' '$FALSO/rclone.log' | head -n 1 | cut -d: -f1 > '$T/n1' && grep -n ' cryptcheck /respaldos/' '$FALSO/rclone.log' | head -n 1 | cut -d: -f1 > '$T/n2' && [ \"\$(cat '$T/n1')\" -lt \"\$(cat '$T/n2')\" ]"
comprobar "la clave llega por --password-command desde un montaje de solo lectura" sh -c "grep -q -- '--password-command cat /run/clave' '$FALSO/rclone.log' && grep -q ':/run/clave:ro' '$FALSO/rclone.log'"
comprobar "la clave nunca va en los argumentos" sh -c "! grep -q clave-de-prueba '$FALSO/rclone.log'"
comprobar "sin secretos en la salida" sin_secretos
comprobar "respaldar.sh no conoce RESPALDO_MODO" sh -c "! grep -q RESPALDO_MODO '$REPO/docker/produccion/respaldar.sh'"

fallida_externa() { # $1 descripción $2 texto esperado en el detalle; resto: variables del caso
	descripcion=$1 texto=$2
	shift 2
	sleep 1
	: > "$FALSO/rclone.log"
	if remota "$@"; then falla "$descripcion: debía terminar con error"; else ok "$descripcion: termina con error"; fi
	registro=$(ultimo_uniq)
	if printf '%s' "$registro" | grep -q -- '--estado=correcto --copia-externa=fallida'; then
		ok "$descripcion: respaldo local correcto y copia externa fallida"
	else falla "$descripcion: respaldo local correcto y copia externa fallida ($registro)"; fi
	if printf '%s' "$registro" | grep -qF -- "$texto"; then ok "$descripcion: el detalle lo explica"; else falla "$descripcion: el detalle lo explica ($registro)"; fi
	comprobar "$descripcion: el respaldo local existe" test -f "$RESPALDO_DIR/$(ultima_carpeta "$RESPALDO_DIR")/uniq/base.dump"
	comprobar "$descripcion: sin secretos en la salida" sin_secretos
}
fallida_externa "fuera de producción" "solo se hace con APP_ENV=production" FALSO_APP_ENV=local
comprobar "fuera de producción: no llama a rclone" test ! -s "$FALSO/rclone.log"
fallida_externa "RESPALDO_MODO=local no habilita nada" "solo se hace con APP_ENV=production" FALSO_APP_ENV=local RESPALDO_MODO=local
comprobar "RESPALDO_MODO=local: no llama a rclone" test ! -s "$FALSO/rclone.log"
fallida_externa "sin rclone.conf" "no existe $T/no-existe.conf" RESPALDO_RCLONE_CONF="$T/no-existe.conf"
comprobar "sin rclone.conf: no llama a rclone" test ! -s "$FALSO/rclone.log"
printf '[cifrado]\ntype = crypt\n' > "$T/rclone/plano.conf"
fallida_externa "rclone.conf sin cifrar" "rclone.conf no está cifrado" RESPALDO_RCLONE_CONF="$T/rclone/plano.conf"
comprobar "rclone.conf sin cifrar: no llama a rclone" test ! -s "$FALSO/rclone.log"
fallida_externa "sin la clave" "no existe la clave" RESPALDO_RCLONE_CLAVE="$T/claves/no-existe"
cp "$T/claves/rclone.clave" "$T/claves/abierta"
chmod 644 "$T/claves/abierta"
fallida_externa "clave con permisos abiertos" "permisos 600 o 400" RESPALDO_RCLONE_CLAVE="$T/claves/abierta"
comprobar "clave con permisos abiertos: no llama a rclone" test ! -s "$FALSO/rclone.log"
printf 'otra-clave' > "$T/claves/equivocada"
chmod 600 "$T/claves/equivocada"
fallida_externa "clave equivocada (configuración incorrecta)" "no se pudo abrir con su clave" RESPALDO_RCLONE_CLAVE="$T/claves/equivocada"
fallida_externa "remoto que no es crypt" "no es de tipo crypt" FALSO_TIPO=drive
comprobar "remoto que no es crypt: no sube nada" sh -c "! grep -q ' copy ' '$FALSO/rclone.log'"
fallida_externa "remoto sin carpeta" "debe ser remoto:carpeta" RESPALDO_REMOTO=cifrado:
fallida_externa "autorización rechazada" "Google Drive rechazó la autorización" FALSO_COPIA=auth
fallida_externa "subida fallida" "falló la subida (rclone copy)" FALSO_COPIA=falla
comprobar "subida fallida: no la da por comprobada" sh -c "! grep -q ' cryptcheck ' '$FALSO/rclone.log'"
fallida_externa "comprobación fallida" "la comprobación de la copia subida no coincide" FALSO_CHECK=1
comprobar "comprobación fallida: no toca las copias remotas anteriores" sh -c "! grep -Eq ' (delete|rmdirs) ' '$FALSO/rclone.log'"

echo "Al terminar"
comprobar "las bases reales falsas siguen intactas" bases_reales_intactas
comprobar "docker nunca se llamó" test ! -s "$FALSO/docker"
comprobar "no se creó respaldos/ en el repositorio" test "$([ -e "$REPO/respaldos" ] && echo si || echo no)" = "$respaldos_en_repo_antes"

echo "$aprobadas comprobaciones correctas, $fallidas fallidas."
[ "$fallidas" -eq 0 ]
