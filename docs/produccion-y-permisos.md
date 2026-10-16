# Preparación de producción y transición de permisos

## Estado

Las imágenes y configuración están preparadas para validación aislada. API_ENABLED
permanece false. No se ha publicado el sistema ni se acredita preparación completa:
falta cifrado de campos sensibles, infraestructura LUKS/KMS/Secrets Manager, ClamAV
real, correo autorizado y restauración en staging. Véase operacion-y-recuperacion.md.

## Construcción y arranque controlado por DevOps

1. Copiar docker/production.env.example a un archivo fuera del repositorio o a
   backend/.env.production, ignorado por Git. Inyectar secretos desde Secrets Manager;
   completar APP_KEY, credenciales y nombres de volúmenes externos cifrados.
2. Mantener API_ENABLED=false durante preparación. El flag no es una certificación
   del cifrado ni sustituye revisión operativa.
3. Usar `docker compose --env-file backend/.env.production -f docker-compose.production.yml config --quiet`
   y `... build`. No imprimir config completo porque expone variables.
4. DevOps debe proveer volúmenes externos PostgreSQL y storage en el almacenamiento
   cifrado, con propietario 1000:1000 para storage, y preparar su contenido inicial
   (`framework/cache/data`, `framework/sessions`, `framework/views`, `logs`).
5. Publicar Nginx exclusivamente detrás del proxy HTTPS institucional. Solo escucha
   en 127.0.0.1:8080; PostgreSQL no publica puertos. Configurar DNS/TLS y verificar
   cookies Secure, dominios Sanctum y CSRF desde el origen HTTPS definitivo.
6. Ejecutar migraciones normales sobre copia de staging antes de cualquier migración
   productiva; no usar migrate:fresh. Proveer una cuenta administradora por el proceso
   de gestión de cuentas acordado: el seeder local no crea administradores en producción.
7. Proveer ClamAV privado en ANTIVIRUS_ENDPOINT, firmas vigentes, límites de INSTREAM
   compatibles con el máximo de PDF y acceso de red solo desde aplicación.
8. Completar la transición de permisos, probar sesiones, secciones y descargas en
   staging espejo. Activar API_ENABLED solo después de cerrar los criterios pendientes.

La imagen backend incorpora código y vendor sin dependencias de desarrollo. La imagen
Nginx incorpora frontend compilado. El arranque no instala paquetes ni monta el código
fuente del host. Scheduler comparte el volumen privado y su caché con la aplicación.
El healthcheck de FPM verifica escucha del proceso; `sistema:estado` verifica dependencias.

## Transición de permisos durante dos sprints

Catálogo `permisos`: id numérico interno, slug público estable. `permisos_rol` vincula
rol y permiso con permitido explícito. Los UUID de recursos y roles existentes se
conservan; no se cambian URLs ni claves foráneas de los expedientes.

1. Aplicar migración en staging. El catálogo se crea, pero no se conceden permisos.
2. `php artisan permisos:importar-legacy` muestra vista previa sin escribir.
3. Revisar la matriz. `php artisan permisos:importar-legacy --aplicar` inserta las
   asignaciones iniciales sin sobrescribir denegaciones existentes. No ejecutar como
   tarea programada: una asignación borrada podría reintroducirse por una nueva importación.
4. PERMISOS_FUENTE=dual durante dos sprints: se lee configuración antigua y BD,
   pero la decisión la toma BD; permisos.divergencia registra diferencias por usuario.
   Una asignación ausente deniega. No hay fallback que conceda acceso desde legacy.
5. Validar en staging espejo roles, vigencias, secciones, datos sensibles, navegación,
   consulta propia y operaciones rechazadas. Guardar matriz, logs y resultados por sprint.
6. Tras aprobación cambiar a PERMISOS_FUENTE=database. La lectura antigua queda inactiva;
   retirar su código en un incremento posterior a la observación de dos sprints.

El catálogo de permisos soportados por el código sigue cerrado; un slug arbitrario no
crea funcionalidad. Un error de BD no recupera concesiones antiguas. Se exige sesión y
rol vigente y activo. Las nuevas concesiones deben mantenerse en BD por el proceso de
administración autorizado; el CRUD de desarrollo de roles no estará abierto en producción.

## Ensayo local de CI

```sh
docker compose -p escalafon_ci -f docker-compose.ci.yml build backend
docker compose -p escalafon_ci -f docker-compose.ci.yml run --rm backend composer install --no-interaction --prefer-dist
docker compose -p escalafon_ci -f docker-compose.ci.yml run --rm backend php artisan test --compact
docker compose -p escalafon_ci -f docker-compose.ci.yml up -d --wait testdb
docker compose -p escalafon_ci -f docker-compose.ci.yml run --rm backend vendor/bin/phpunit -c phpunit.pgsql.xml
docker compose -p escalafon_ci -f docker-compose.ci.yml run --build --rm e2e
```

No ejecutar dos suites backend simultáneas en el mismo proyecto CI. Los workers de
concurrencia están coordinados por su prueba y solo aceptan testdb/escalafon_test.
No confundir estos comandos con los de producción. Los jobs remotos y la protección
de rama necesitan ejecución/configuración en GitHub para acreditarse.
