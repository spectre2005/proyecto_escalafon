# Operación y recuperación — DevOps

## Alcance y evidencias

Este runbook parametriza lo aprobado: LUKS, KMS con rotación de 90 días, claves
separadas para backups, retención de 30 días, copia cada 60 minutos, RPO <=1 hora
y RTO <=4 horas. No acredita que la infraestructura esté provisionada ni que esos
objetivos se hayan medido. No ejecutar operaciones de disco contra un servidor activo.

El esquema actual contiene numero_doc_identidad en usuarios y familiares. No se
identificaron campos bancarios. El cifrado de identidad a nivel de aplicación sigue
pendiente de una migración con índices ciegos, backfill y compatibilidad de búsquedas,
importaciones y unicidad. No guardar una clave de datos en código ni reutilizar la
clave de sesión como sustituto de la integración KMS acordada.

## Provisionamiento previo

1. DevOps prepara volúmenes LUKS para BD, PDF y fotografías, y verifica el montaje
   cifrado antes de arrancar servicios. Guardar evidencia de cryptsetup status y findmnt.
2. Crear claves KMS de aplicación y backup separadas, con políticas mínimas e IAM
   independiente para escritura de copias y restauración. Habilitar rotación de 90 días.
   La rotación KMS no recifra automáticamente filas ni archivos existentes.
3. Configurar AWS Secrets Manager con credenciales SES y claves de datos protegidas por KMS.
   Inyectar valores al proceso en el despliegue; el repositorio no incluye credenciales
   ni un lector AWS automático. No volcar secretos en logs ni en docker compose config.
4. Configurar copia incremental coordinada de PostgreSQL y almacenamiento cada 60 min.
   Un pg_dump aislado no es una estrategia de snapshots incrementales ni garantiza RPO.
5. Cifrar backups con la clave separada y aplicar retención de 30 días. Proteger también
   manifiestos, fotografías, configuración necesaria y catálogo de claves recuperables.

## Señales y alertas

El scheduler escribe scheduler.heartbeat cada minuto. `php artisan sistema:estado`
comprueba BD, escritura/lectura/borrado de un objeto de health y heartbeat <=180 segundos.
Devuelve JSON sin credenciales y salida distinta de cero ante un fallo.
`--produccion` añade comprobaciones de configuración; no inspecciona LUKS ni AWS.

DevOps debe ejecutar la comprobación cada minuto desde monitoreo, alertar tras tres
fallos y registrar duración del último backup y antigüedad del punto recuperable.
Alertar si el último backup válido supera 60 min, si hay envíos inciertos o fallidos,
si el antivirus no tiene firmas vigentes o si aparecen permisos.divergencia en modo dual.
No publicar este diagnóstico como endpoint anónimo ni incluir sus detalles en /up.

## Restauración en staging

1. Registrar inicio, incidente, punto de recuperación y responsables. Aislar staging:
   sin correo real, scheduler de negocio ni acceso de usuarios productivos.
2. Obtener el backup y su manifiesto con la identidad de restauración; recuperar las
   claves separadas por el procedimiento de DevOps. Verificar integridad antes de restaurar.
3. Restaurar PostgreSQL, PDF y fotografías del mismo corte en volúmenes cifrados nuevos.
   No usar migrate:fresh ni restaurar sobre los volúmenes de producción.
4. Inyectar secretos de staging y ejecutar migraciones compatibles con el backup.
   Validar sesiones/permisos, recuentos de documentos, hashes SHA-256 de PDF y claves históricas.
5. Medir pérdida de datos <=60 min y tiempo hasta servicio funcional <=240 min. Adjuntar
   timestamps, comprobaciones y responsables. Un restore de esquema vacío no acredita RTO.
6. Destruir las copias de prueba según política y dejar constancia del simulacro.

## Ensayo local seguro de PostgreSQL

Tras ejecutar la suite PostgreSQL, puede comprobarse dump/restore del servicio efímero:

```sh
docker compose -p escalafon_ci -f docker-compose.ci.yml exec -T testdb sh -s < docker/operations/restore-smoke.sh
```

El script rechaza una BD/usuario distintos de escalafon_test; crea una BD temporal,
compara contenido y elimina únicamente su BD y dump temporales. No prueba KMS, LUKS,
recuperación de PDF ni el staging espejo solicitado.

## Correo SES y ventana autorizada

MAIL_MAILER=ses_smtp, SES_SMTP_HOST=email-smtp.us-east-1.amazonaws.com, puerto 587,
STARTTLS obligatorio. SES_SMTP_USERNAME/SES_SMTP_PASSWORD se inyectan desde Secrets Manager.
MAIL_FROM_ADDRESS=notificaciones@midominio.com. Solo se autorizó una prueba a
pruebas-notificaciones@midominio.com los martes 10:00–12:00 America/Lima; no enviar
fuera de esa ventana. La primera ventana posterior al inicio de este trabajo es
el martes 20/10/2026. No se ha enviado ningún mensaje real durante la implementación.

Cuatro intentos totales; esperas de 1, 5 y 15 minutos. Un timeout o una interrupción
posterior a reservar el envío se marca incierto y exige conciliación con SES antes
de reintentar. Estado enviado significa aceptado por el transporte, no recepción
en la bandeja: comprobar eventos SES de entrega, rebote y queja antes de certificarla.
