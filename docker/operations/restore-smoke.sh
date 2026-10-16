#!/bin/sh
# Ejecutar solamente dentro del servicio testdb de docker-compose.ci.yml.
set -eu
[ "${POSTGRES_DB:-}" = escalafon_test ] || { echo 'Se requiere escalafon_test.' >&2; exit 1; }
[ "${POSTGRES_USER:-}" = escalafon_test ] || { echo 'Se requiere el usuario aislado.' >&2; exit 1; }
restore_database="escalafon_restore_test_$$"
dump_path="/tmp/escalafon_restore_$$.dump"
cleanup() {
  dropdb -U escalafon_test --if-exists "$restore_database"
  rm -f "$dump_path"
}
trap cleanup EXIT HUP INT TERM
[ "$(psql -U escalafon_test -d escalafon_test -At -c 'SELECT COUNT(*) FROM usuarios')" -gt 0 ] || { echo 'Preparar datos sintéticos antes del ensayo.' >&2; exit 1; }
pg_dump -U escalafon_test -d escalafon_test -Fc -f "$dump_path"
createdb -U escalafon_test "$restore_database"
pg_restore -U escalafon_test --exit-on-error -d "$restore_database" "$dump_path"
for table in usuarios documentos_usuario archivos_documento; do
  query="SELECT COALESCE(md5(string_agg(row_to_json(t)::text, '' ORDER BY t.id)), 'empty') FROM $table t"
  original=$(psql -U escalafon_test -d escalafon_test -At -c "$query")
  restored=$(psql -U escalafon_test -d "$restore_database" -At -c "$query")
  [ "$original" = "$restored" ] || { echo "Restauración inconsistente: $table" >&2; exit 1; }
done
echo 'Restauración aislada: contenido de usuarios, documentos y metadatos íntegro.'
