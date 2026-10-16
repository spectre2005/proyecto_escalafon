# Ejecución del backlog — 16/10/2026

El usuario aprobó el plan y después confirmó conservar las reglas funcionales actuales.
Autorizó configuración y pruebas aisladas de infraestructura y pidió un commit local.
No se publican cambios ni se hace push. No se modifican `.env` reales ni se migran datos
activos. Se conserva la rama feature/Panel-Acceso_e_Incorporacion.

## Estado por tarea y criterios de aceptación

✅ significa verificado o decisión explícitamente aprobada. ❌ identifica un criterio
pendiente, no un resultado simulado como aprobado. No se declara cerrado el backlog completo.

| Tarea | Dependencias | Estado | Criterios y evidencia |
|---|---|---|---|
| B01 Proteger respaldos | — | Cerrada | ✅ .env.save de raíz/backend ignorados; ✅ ejemplos versionables; ✅ sin coincidencias de los cuatro archivos sensibles revisados en el historial local accesible. No se acreditó historia remota ni objetos eliminados; no hay evidencia local para rotar credenciales. |
| B02 Limitar procesamiento PDF | — | Cerrada | ✅ Entrada y expansión acumulada acotadas a 8 MiB, máximo 64 streams; ✅ salida null cuando no puede contarse con seguridad; ✅ PDF comprimido, malformado, sobrelímite y lectura posterior cubiertos por pruebas. |
| B11 Aprobar reglas | — | Cerrada por decisión del usuario | ✅ Conservar corrección/anulación actuales; ✅ sin cascadas ni pase automático a pasivo; ✅ nuevos casos de Reportes, Fiscalización y Mi perfil fuera del incremento. |
| B04 Inmutabilidad histórica | — | Cerrada en API y Eloquent | ✅ POST/PUT/PATCH/DELETE históricos rechazados incluso para administradores; ✅ instancias Eloquent rechazan edición/borrado; ✅ carga, revisión y lectura siguen registrando eventos. SQL directo/query builder necesitan privilegios de BD: no se afirma inmutabilidad ante un DBA. |
| B05 Auditoría transaccional | B04 | Cerrada | ✅ Registrador abre transacción; ✅ rollback ante fallo posterior a insertar; ✅ cadena secuencial y dos procesos concurrentes PostgreSQL producen una sola cadena lineal. |
| B06 Cifrado y recuperación | — | Parcial | ✅ Política aprobada y configuración parametrizada: LUKS, KMS 90 días, claves separadas, retención 30 días, RPO 60 min/RTO 240 min; ❌ integración y migración de cifrado de identidad con búsqueda/unicidad; ❌ LUKS/KMS/Secrets Manager y recuperación de claves acreditados en infraestructura. No se identificaron campos bancarios en el esquema actual. |
| B07 CI | — | Implementada y validada localmente | ✅ Workflow backend/frontend/navegador; ✅ PostgreSQL y storage CI aislados; ✅ pruebas, lint, build y formato ejecutados localmente; ✅ imagen verifica JPEG/SQLite; ✅ regresión determinista de factory corregida. ❌ Ejecución remota GitHub y protección de rama requieren configuración del repositorio remoto. |
| B10 Avisos SES | B07 | Implementada; entrega real pendiente | ✅ SES us-east-1 por 587 con STARTTLS obligatorio; ✅ cuatro intentos con 1/5/15 min; ✅ fallos y timeout conservan trazabilidad; ✅ simulación nunca equivale a entrega; ❌ credenciales inyectadas y entrega real conciliada con SES en ventana autorizada. |
| B08 PostgreSQL | B05, B07 | Cerrada en entorno aislado | ✅ Base efímera sin puertos publicados; ✅ workers solo admiten testdb/escalafon_test; ✅ concurrencia de auditoría, folios y envíos; ✅ APIs, filtros y permisos también probados con PostgreSQL. |
| B17 Alertas/restauración | B06, B10 | Preparación local verificada; operación pendiente | ✅ Heartbeat del scheduler y comando sin secretos; ✅ fallo ante heartbeat obsoleto; ✅ dump/restore aislado con contenido sintético no vacío y verificación de integridad; ✅ runbook DevOps. ❌ Alertas externas, snapshots incrementales horarios y restauración conjunta BD/PDF/claves en staging; ❌ RPO/RTO reales medidos. |
| B03 Antivirus/cuarentena | B02 | Implementada; motor real pendiente | ✅ Cliente INSTREAM acotado con timeout; ✅ protocolo limpio/infectado/error probado con servidor local; ✅ infectados bloqueados en las 13 secciones; ✅ modo estricto no entrega ni acepta evidencia sin análisis limpio; ✅ descargas de legajo, PDF antiguos, incorporación y renovación pasan por la política; ✅ error accesible. ❌ Motor ClamAV real con firmas vigentes validado. |
| B09 Producción | B01–B08, B10, B17 | Preparada; publicación bloqueada por criterios pendientes | ✅ Imágenes con código/dependencias incluidos y frontend compilado; ✅ sin `.env` ni PHPUnit en imagen backend; ✅ Nginx validado; ✅ PostgreSQL sin puerto publicado; ✅ API funcional exige flag y controles; ✅ CRUD interno permanece local; ✅ API_ENABLED=false en ejemplo. ❌ Cierre de B06/B17 y smoke test en staging HTTPS con infraestructura real. No se ha desplegado producción. |
| B19 Accesibilidad | B07 | Verificada en recorridos Chromium | ✅ Login por teclado, toggle y error recuperable; ✅ foco atrapado en registro, Escape y retorno al disparador; ✅ menú con Escape; ✅ descarga/cierre del visor y error de cuarentena; ✅ paginación solicitada al servidor. No equivale a una auditoría WCAG completa ni valida el contenido accesible de PDF externos. |
| B14 Consultas de avisos | B08 | Implementada y verificada | ✅ Roles precargados por lote y reutilizados; ✅ destinatarios vigentes conservados y roles vencidos excluidos; ✅ prueba con 25 usuarios adicionales limita consultas de roles a tres. Para un SLA productivo falta acordar volumen representativo y medir tiempos allí. |
| B15 Directorio paginado | B07 | Cerrada | ✅ API limita página a 100; ✅ UI solicita solo su página; ✅ filtros aplican antes de paginar y q trata comodines como texto; ✅ indicadores agregados sin descargar personal; ✅ importación/plantilla y validación definitiva en backend conservadas; ✅ navegación/filtro probados en navegador. |
| B16 Contrato API | B11 | Parcial | ✅ Documentación de contratos principales y errores; ✅ inventario completo de rutas, acciones, middleware/restricciones y hashes de fuentes; ✅ comando detecta divergencias. ❌ Esquemas detallados de entrada/salida de todos los formularios especializados; no se presenta como OpenAPI completo. |
| B18 Permisos explícitos | B07, B08 | Implementada; observación en staging pendiente | ✅ Catálogo con IDs numéricos y slugs, tabla permisos_rol; ✅ importación con vista previa; ✅ modos legacy/dual/database; ✅ dual decide por BD y registra diferencias; ✅ política ausente o modo desconocido deniega; ✅ vigencias/inactividad conservadas; ✅ precarga para avisos. ❌ Dos sprints de doble lectura y matriz validada en staging espejo antes de retirar lectura antigua. |
| B12 Diferencias funcionales aprobadas | B11 | Sin cambios funcionales adicionales requeridos | ✅ El usuario aprobó conservar comportamiento; ✅ regresiones de secciones incluidas en suite completa. |
| B13 Módulos nuevos | B11 | Excluida del incremento por el usuario | Reportes generales, Fiscalización y Mi perfil no reciben casos de uso nuevos ni se presentan como terminados. Las funciones existentes se conservan. |

## Cambios y pruebas

El informe [codigo-backlog.md](codigo-backlog.md) incluye el contenido completo de
archivos nuevos/modificados y diffs de archivos previamente versionados. Los `.env`
reales y los archivos temporales de pruebas quedan fuera del informe.

Comandos reproducibles: [produccion-y-permisos.md](produccion-y-permisos.md).
Contratos: [api.md](api.md) y [api-contract.json](api-contract.json).
Operación: [operacion-y-recuperacion.md](operacion-y-recuperacion.md).

La primera suite detectó tres fallos de JPEG en la imagen activa y un fallo por caché
de archivos compartida con el scheduler. La imagen nueva verifica JPEG/SQLite y
TestCase fuerza caché/sesión de pruebas en memoria. CI usa además storage propio.
Una ejecución posterior reveló una colisión intermitente de SeccionLegajoFactory con
el catálogo fijo 1–13: la semilla 584 reproduce el fallo antes del cambio y pasa después.
Las secciones sintéticas usan ahora el intervalo 14–32767.

El visor de prueba usa un PDF válido generado con jsPDF. Un fixture PDF inválido activaba
el error y recarga del visor nativo de Chromium; no se atribuyó ese comportamiento a
un fallo del componente. Sí se corrigió el ciclo de Tab del diálogo de registro.

## Pendientes para cerrar el backlog

- B06: implementar migración de cifrado de identidad y aportar acceso/evidencias de LUKS,
  KMS, Secrets Manager y recuperación de claves. Ningún flag acredita por sí solo cifrado.
- B03: proveer daemon ClamAV privado y comprobar firmas, disponibilidad y muestras de prueba.
- B10: inyectar credenciales SES y verificar entrega real solo a
  pruebas-notificaciones@midominio.com, martes 10:00–12:00 America/Lima. Remitente:
  notificaciones@midominio.com. Primera ventana posterior al inicio: 20/10/2026.
- B17/B09: implementar monitoreo y snapshots, restaurar BD/PDF/claves en staging y medir RPO/RTO;
  probar DNS/HTTPS/Sanctum antes de habilitar API productiva.
- B18: registrar fechas y resultados de los dos sprints; después activar database y decidir
  retirada del código legacy en otro incremento. No se cambian los UUID de recursos existentes.
- B07: ejecutar los jobs en GitHub y exigirlos en protección de rama.
- B16: terminar esquemas especializados; el inventario automático no sustituye esa documentación.

## Seguridad de la ejecución

Las migraciones destructivas y seeders se ejecutan únicamente en SQLite en memoria o
PostgreSQL efímero testdb/escalafon_test. El ensayo de restore crea y elimina solo su
BD temporal. No se borran volúmenes de desarrollo. Los contenedores locales pueden
reconstruirse para usar el runtime corregido sin ejecutar migraciones sobre datos activos.
Los reportes no contienen credenciales de los `.env` reales. Las claves y contraseñas del
Compose CI son públicas, exclusivas de pruebas y no sirven para producción.

## Resultados finales de verificación

- Suite backend completa en CI aislado: **509 pruebas, 5.244 aserciones, todas aprobadas**.
- Integración PostgreSQL: **49 pruebas, 414 aserciones, todas aprobadas**, incluidos workers concurrentes.
- Frontend: **28 pruebas unitarias aprobadas**, lint y build correctos.
- Navegador Chromium con API simulada: **6 recorridos aprobados** (login, diálogo, menú,
  paginación del servidor, visor/descarga y cuarentena).
- Pint aplicado a PHP modificado y nuevo; git diff --check sin errores.
- Contrato generado comprobado sin divergencias; inventario de rutas y validaciones de fuente.
- Restore PostgreSQL aislado con usuarios sintéticos no vacíos e integridad coincidente.
- Imágenes productivas construidas; configuración Nginx válida y ausencia de .env/.env.save
  y de PHPUnit en la imagen backend comprobada.
- Contenedores locales actualizados sin migraciones; /up = 200 y API protegida sin sesión = 401.
- Build frontend conserva una advertencia de tamaño de bundle >500 kB; no impide compilar.

No se enviaron correos reales, no se activó API productiva, no se aplicó la transición de
permisos sobre datos activos ni se provisionaron recursos AWS/LUKS. Las pruebas no
sustituyen los criterios pendientes expresamente marcados en la tabla.
