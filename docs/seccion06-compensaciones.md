# Sección 06 — Compensaciones

Implementación aprobada el 05/10/2026, en `feature/seccion_04_experiencia_laboral`. Sin cambio de rama, commit ni push.

## Alcance y referencias

Se reutilizan la arquitectura documental de las Secciones 03/04 y las tablas `tiempo_servicios_reconocidos` y `compensaciones_beneficios`. No se agregan dependencias, tablas, importes, monedas ni cálculos financieros.

La Directiva N.º 001-2023-SERVIR-GDSRH incluida en `docs/4818377-directiva001rrhh.pdf` define los cuatro literales de Compensaciones en la página 17. El formato de la página 25 contiene reconocimiento, pagos y retenciones cuando corresponda, además de otros documentos institucionales. Aunque ese formato no enumera la liquidación, la definición de la página 17 sí la incluye, por lo que se conserva el literal d.

Se leyó mediante `git show` y `git diff` la rama del compañero `feature/seccion-05-movimientos`, commit `d94669e03b0bc6afbbf233d1ac7ebb6291fcef13`, sin modificarla ni integrarla. Se incorporó su atención al aislamiento del titular, el filtrado de información sensible y la protección de registros documentados. Compensaciones conserva la carga conjunta de PDF y datos de 03/04; no copia la carga en dos pasos ni modifica los formularios de movimientos. Al integrar ramas, revisar especialmente `routes/api.php`, `ExpedienteController.php`, `DatabaseSeeder.php`, `legajoSections.js` y `LegajosPage.jsx`.

## Uso y catálogo

Navegación: **Legajos → Compensaciones → Documentos de compensaciones**.

| Literal | Código | Datos vinculados | Sensible por defecto |
|---|---|---|---|
| a | `COMP_RECONOCIMIENTO_TIEMPO` | Número/fecha de resolución y años/meses/días en `TiempoServicioReconocido` | No |
| b | `COMP_PAGO_COMPENSACION` | Tipo económica/no económica, concepto, fecha y número opcional de documento en `CompensacionBeneficio` | Sí |
| c | `COMP_RETENCION_TRIBUTARIA` | Metadatos y PDF; categoría identificada en el nombre | Sí |
| d | `COMP_LIQUIDACION_BENEFICIOS` | Metadatos y PDF | Sí |
| Hook | `COMP_OTROS` | Metadatos y PDF; `es_hook_universidad=true` | No |

Los cinco tipos admiten múltiples documentos y son condicionales, sin obligatoriedad universal, fedateo obligatorio ni acreditación de perfil por defecto. Las banderas institucionales existentes siguen siendo respetadas. El seeder no sobrescribe tipos existentes.

El resumen cuenta documentos vigentes aceptados, pendientes y observados. El avance es aceptados/documentos vigentes; sin documentos es `null`, no 100 %. Los faltantes proceden únicamente de exigencias configuradas en el catálogo y perfil existentes. No existe una regla institucional nueva de correspondencia por persona.

El número y fecha de resolución de a se capturan una vez: `num_resolucion` y `fecha_emision` del documento se reutilizan como `numero_resolucion` y `fecha_resolucion`. Años, meses y días son enteros no negativos, dentro del rango del esquema. Se transcriben tal como aparecen en el acto: no se normalizan a 30/360, no se suman resoluciones ni se incorporan al cálculo de experiencia de Sección 04. Límites administrativos adicionales siguen pendientes de definición institucional.

La categoría `compensaciones-beneficios` permanece disponible como consulta genérica. Enlaces anteriores con `categoria=tiempo-servicios-reconocidos` abren el panel de Sección 06. No se trasladan evidencias ni se enlazan registros históricos automáticamente.

## API y permisos

Prefijo `/api/v1`, con Sanctum y la restricción `ApiHabilitada` (solo local y pruebas, salvo `API_HABILITADA=true`).

| Método | Ruta | Permiso |
|---|---|---|
| GET | `/expedientes/{usuario}/seccion-06` | `legajo.ver` |
| POST | `/expedientes/{usuario}/seccion-06/documentos` | `documento.subir` |
| POST | `/expedientes/{usuario}/seccion-06/documentos/{documento}/estado` | `documento.revisar` |

Anular exige además `sistema.administrar`. Tipos sensibles o de salud requieren `datos_sensibles.ver`. Se reutilizan recepción y visor existentes. No se programan permisos por nombre de rol.

La consulta especializada muestra evidencias del legajo activo, como 03/04. La carga deriva titular, autor y fecha de incorporación del servidor. `fecha_actual` usa America/Lima y se comparte con el formulario y revisión para evitar inconsistencias cerca del cambio de día UTC.

## Integridad, folios y revisión

- PDF privado, contenido/MIME real, tamaño configurable, SHA-256 y estado antivirus real.
- `CargaDocumento` y su callback crean PDF, documento, datos, historial y auditoría en el mismo flujo transaccional; un fallo retira el archivo huérfano.
- `Folios` conserva su bloqueo del legajo/sección: inicio = último + 1, sin huecos ni solapamientos. Folios históricos, anulados o subsanados siguen ocupados.
- Estado inicial `pendiente`; aceptar, observar o anular reutiliza `RevisionDocumento`.
- ADMIN puede revisar también su propia carga (criterio actualizado el 19/10/2026). Se rechazan cambios de estado obsoletos, documentos anulados/subsanados, legajo cerrado, datos estructurados inconsistentes y archivos ausentes/infectados al aceptar.
- Aceptar requiere recepción confirmada y fedateo registrado con PDF de sustento si el tipo lo exige; la aceptación no lo registra. La fiscalización posterior no se marca automáticamente.
- Una corrección crea evidencia nueva con `subsana_a_documento_id`. La aceptación de la subsanación cierra las observaciones abiertas anteriores.
- Registros estructurados vinculados a documentos de sección 6 no se editan, borran ni reasignan por CRUD genérico. Las cargas genéricas no pueden crear los tipos especializados omitiendo el flujo aprobado.
- Las consultas de expediente y los listados/detalles administrativos de documentos, archivos y datos estructurados filtran información sensible. La protección se aplica también antes de editar/eliminar por rutas administrativas.
- Las consultas genéricas por persona excluyen datos vinculados a documentos anulados/subsanados de sección 6. Los registros originales permanecen para auditoría; la vigencia documental se determina mediante su documento asociado.

## Migraciones y ejecución

Dos migraciones nuevas reversibles agregan índices por usuario/documento y catálogo. No modifican columnas ni migraciones históricas. El rollback del catálogo elimina únicamente IDs propios y se bloquea si hay evidencia asociada; conserva tipos preexistentes.

En este entorno local se aplicaron únicamente estas dos migraciones:

```bash
docker compose exec app php artisan migrate \
  --path=database/migrations/2026_10_05_195436_add_seccion06_document_indexes.php \
  --path=database/migrations/2026_10_05_195437_seed_seccion06_catalogo.php \
  --no-interaction
```

Se conservan pendientes las migraciones antiguas de foto y PDF de certificados/declaraciones de Sección 01. No ejecutar indiscriminadamente todas las migraciones sobre este entorno sin resolver antes esa diferencia histórica de esquema.

## Verificación

- Suite backend con caché aislada: **323 aprobadas, 1 fallida, 3183 aserciones**. Incluye las 28 pruebas nuevas de Compensaciones. El único fallo restante es `FotoPersonalTest`: el contenedor actual carece de `imagewebp`; el Dockerfile ya configura WebP, pero no se reconstruyó el servicio dentro de esta tarea.
- La primera suite, sin aislar caché, produjo además dos fallos de autenticación por respuestas 429. Pasaron con `CACHE_STORE=array`, sin modificar autenticación ni borrar la caché compartida.
- Regresión final tras el ajuste de protección de edición sensible: **96 pruebas aprobadas, 1179 aserciones**, correspondientes a Sección 06, CRUD y expedientes.
- Frontend: **12 pruebas aprobadas**, ESLint completo y compilación aprobados. Vite mantiene la advertencia de paquete principal superior a 500 kB.
- Pint aplicado a los 17 archivos PHP afectados. `--dirty` no funciona porque `.git` no está montado dentro del contenedor; se enumeraron las rutas explícitamente.
- Chromium con API simulada: cinco tipos, formularios a/b/c/d/hook, PDF con DPR 2, ausencia de envío antes de guardar, payload específico por tipo, guardado, observación y ocultación de revisión/anulación por permisos. Sin desbordamiento horizontal a 400, 520, 900 y 1280 px. No se cargaron evidencias de prueba en PostgreSQL.
- HTTP real: CSRF 204; inicio de sesión, listado, consulta Sección 06 con cinco tipos y cierre de sesión 200.

Comandos de comprobación:

```bash
docker compose exec -e CACHE_STORE=array app php artisan test --compact tests/Feature/Seccion06ApiTest.php
docker compose exec -e CACHE_STORE=array app php artisan test --compact
docker compose exec frontend pnpm lint
docker compose exec frontend pnpm test
docker compose exec frontend pnpm build
```

`git diff --check` aprobado. Los archivos temporales del navegador se retiraron y sus procesos se detuvieron.

## Archivos creados

| Archivo | Motivo |
|---|---|
| `backend/app/Http/Controllers/Api/V1/Legajos/Seccion06Controller.php` | Consulta, carga conjunta, serialización y revisión |
| `backend/app/Support/ResumenSeccion06.php` | Catálogo de literales y resumen de documentos vigentes |
| `backend/database/migrations/2026_10_05_195436_add_seccion06_document_indexes.php` | Índices de consulta en las dos tablas existentes |
| `backend/database/migrations/2026_10_05_195437_seed_seccion06_catalogo.php` | Catálogo y rollback protegido |
| `backend/database/seeders/Seccion06CatalogoSeeder.php` | Cinco tipos documentales idempotentes |
| `backend/tests/Feature/Seccion06ApiTest.php` | Pruebas de flujo, integridad, permisos y privacidad |
| `frontend/src/features/legajos/api/seccion06Api.js` | Multipart y revisión con Axios compartido |
| `frontend/src/features/legajos/components/seccion06/Documento06Form.jsx` | Campos por literal, metadatos y PDF |
| `frontend/src/features/legajos/components/seccion06/Seccion06Panel.jsx` | Panel, resumen, historial, visor y acciones |
| `docs/seccion06-compensaciones.md` | Decisiones, validación y listado exacto de cambios |

## Archivos modificados

| Archivo | Motivo |
|---|---|
| `backend/app/Http/Controllers/Api/V1/ArchivoDocumentoController.php` | Filtrado de metadatos de archivos sensibles |
| `backend/app/Http/Controllers/Api/V1/CompensacionBeneficioController.php` | Protección de datos documentados y consultas sensibles |
| `backend/app/Http/Controllers/Api/V1/DocumentoUsuarioController.php` | Bloqueo de bypass de carga y filtrado sensible |
| `backend/app/Http/Controllers/Api/V1/Legajos/DocumentoLegajoController.php` | Bloqueo de carga genérica e infectados en visor para 6 |
| `backend/app/Http/Controllers/Api/V1/Legajos/ExpedienteController.php` | Filtrado sensible y vigencia documental de datos de 6 |
| `backend/app/Http/Controllers/Api/V1/TiempoServicioReconocidoController.php` | Protección de datos documentados y consultas sensibles |
| `backend/app/Support/Documentos/CargaDocumento.php` | Exclusión de anulados como candidatos de subsanación de 6 |
| `backend/app/Support/Documentos/RevisionDocumento.php` | Revisión de 6, consistencia estructurada y archivo físico |
| `backend/config/permisos.php` | Descripción de revisión incluye Compensaciones |
| `backend/database/seeders/DatabaseSeeder.php` | Incluye catálogo de sección 6 |
| `backend/routes/api.php` | Tres rutas especializadas |
| `frontend/src/features/legajos/components/seccion03/Revision03Form.jsx` | Fecha mínima inyectable; conserva valor previo para 03/04 |
| `frontend/src/features/legajos/config/legajoSections.js` | Navegación especializada de 6 y ubicación del tiempo reconocido |
| `frontend/src/features/legajos/pages/LegajosPage.jsx` | Renderizado y compatibilidad de enlaces anteriores |

## Pendientes fuera del alcance aprobado

- Integración de antivirus y cifrado en reposo: no se presentan como verificados.
- Correspondencia individual de requisitos, límites de meses/días y reglas institucionales adicionales de fedateo/sensibilidad.
- Revisión y eventual enlace manual de datos históricos sin evidencia.
- Habilitación de API en producción, resolución de migraciones antiguas y reconstrucción del contenedor para WebP.
