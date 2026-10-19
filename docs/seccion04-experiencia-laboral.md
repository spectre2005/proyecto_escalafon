# Sección 04: experiencia laboral

Implementada en `feature/seccion_04_experiencia_laboral`, sin commit ni push. Sigue el panel de Sección 03 y reutiliza `Campo`, `Formulario`, `FormSplit`, `PdfPreviewField`, `VisorPdf`, `PerfilDocumental`, la carga transaccional y los folios por sección. El catálogo tiene tres tipos: certificados de trabajo exigidos, múltiples y con fedateo; resoluciones de funciones opcionales y múltiples con número obligatorio; otros documentos opcionales y múltiples con `es_hook_universidad`. Los ítems b/c no acreditan perfil por defecto, según el informe aprobado; las banderas siguen siendo configurables. Se conserva la sección adicional de legajo pasivo.

## Comportamiento

- Cada carga crea documento, archivo privado, hash SHA-256, historial, auditoría y, para certificados, un periodo de experiencia en la misma transacción. Un fallo revierte los datos, los folios y el PDF recién almacenado.
- Estado inicial: `pendiente`. ADMIN acepta/observa y puede anular con motivo; puede revisar también su propia carga (criterio actualizado el 19/10/2026). El asistente configurado puede consultar y cargar, pero no revisar. La autorización siempre se valida en servidor.
- Aceptar exige revisión de recepción/sello/folios, fedateo registrado con PDF de sustento cuando corresponde y datos de experiencia completos. La aceptación no genera el fedateo. La fiscalización con la entidad emisora y el análisis antivirus son independientes. El antivirus permanece pendiente hasta integrar un analizador real; los infectados se bloquean.
- Los periodos sustentados no se editan ni eliminan mediante el CRUD genérico. Las correcciones crean una evidencia nueva que subsana a la anterior. Los documentos anulados/subsanados y sus archivos se conservan.
- Resoluciones guardan `funcion_asignada` en el documento; no crean periodos artificiales. El formulario de certificados incluye fecha de emisión para completar el metadato obligatorio aprobado.
- El resumen cuenta cada documento exigido vigente: si todavía no existe ninguno, espera al menos uno. El estado del ítem observa la prioridad observado → pendiente → aceptado, excluyendo anulados y subsanados. No puede detectar experiencias declaradas fuera del sistema que aún no se hayan registrado.
- `Número de folios` proviene de `legajo_secciones.ultimo_folio`, incluyendo folios ya asignados a evidencias que después fueron anuladas.
- La línea de tiempo muestra periodos sustentados de todos los legajos de la persona, aunque el checklist y la carga corresponden al legajo activo. Sin documento, con propietario distinto, anulados, subsanados o infectados no cuentan.

## Cálculo y parámetros

Se cuentan días calendario inclusivos, se unen intervalos superpuestos y consecutivos, y después se suman. Los abiertos usan la fecha de corte (hoy en `America/Lima` por defecto). Se calcula la unión total, por sector y por indicador. Los subtotales público/privado pueden sumar más que el total cuando existen trabajos simultáneos en ambos sectores.

| Clave en `parametros_sistema` | Valor inicial | Uso |
|---|---:|---|
| `exp_dias_por_mes` | 30 | Divisor para el desglose de meses/días. |
| `exp_dias_por_anio` | 360 | Divisor para años y años decimales. |
| `exp_hueco_largo_dias` | 90 | Aviso si el hueco entre intervalos fusionados es **mayor** que este valor. |
| `exp_incluir_pendientes` | 0 | `0`: solo aceptados; `1`: también pendientes. Observados nunca cuentan. |

Estos parámetros se editan con el recurso existente `GET/PATCH /api/v1/parametros-sistema`, reservado a administración. Las migraciones conservan valores ya configurados. Valores inválidos o claves duplicadas producen un mensaje de validación; no se utilizan silenciosamente. La reversión preserva parámetros institucionales que hayan sido modificados.

Años = `floor(días / días_por_año)`; meses = `floor((días % días_por_año) / días_por_mes)`; días = `(días % días_por_año) % días_por_mes`. Se muestran años decimales con dos decimales y se devuelve también `anios_decimal_sin_redondear`: los requisitos deben comparar ese valor o los días exactos, evitando que 359 días redondeados a 1.00 satisfagan un año completo. Cambiar los divisores cambia la convención aritmética; una descomposición por meses de calendario de duración variable requiere definir otro algoritmo, no basta con cambiar dos números.

Los huecos y superposiciones son informativos: no impiden guardar ni invalidan experiencia. No se calcula un hueco desde el último periodo hasta hoy. Las banderas de ejercicio profesional/docencia universitaria las declara quien carga y RR. HH. revisa su contenido; si son incorrectas, procede observación y subsanación. Datos históricos sin clasificación no se presumen profesionales o docentes.

## Servicio para requisitos

```php
use App\Support\TiempoExperiencia;

$indicadores = TiempoExperiencia::indicadores($usuarioId, '2026-10-05');
$profesional = $indicadores['anios_ejercicio_profesional'];
$docencia = $indicadores['anios_docente_universitario'];
// Comparar días o anios_decimal_sin_redondear con la configuración del requisito.
```

El módulo actual de Evaluaciones es de desempeño/progresión; no existe aún un consumidor de requisitos con estos indicadores. Se entrega el servicio reutilizable y los indicadores en la consulta de Sección 04, sin crear un módulo nuevo ni programar mínimos legales.

## Rutas nuevas

- `GET /api/v1/expedientes/{usuario}/seccion-04`: checklist, estados, resumen, folios, periodos, alertas y tiempo calculado (`legajo.ver`).
- `POST /api/v1/expedientes/{usuario}/seccion-04/documentos`: PDF y datos, enviados juntos al guardar (`documento.subir`).
- `POST /api/v1/expedientes/{usuario}/seccion-04/documentos/{documento}/estado`: aceptar/observar; anular exige además administración (`documento.revisar`).

La carga común y el CRUD documental redirigen mediante mensajes al formulario específico para estos tipos, evitando registrar certificados sin su periodo. Los contratos de las demás secciones se conservan.

## Docker y verificación

Desde la raíz del repositorio:

```sh
docker compose up -d --build
docker compose exec app composer install
docker compose exec frontend pnpm install --frozen-lockfile
docker compose exec app php artisan migrate:status
docker compose exec app php artisan migrate --path=database/migrations/2026_10_05_163133_add_seccion04_experiencia_metadata.php --no-interaction
docker compose exec app php artisan migrate --path=database/migrations/2026_10_05_163133_seed_seccion04_catalogo.php --no-interaction
docker compose exec app php artisan config:clear
docker compose exec app php artisan test --compact tests/Feature/TiempoExperienciaTest.php tests/Feature/Seccion04ApiTest.php tests/Feature/Seccion03ApiTest.php tests/Feature/DocumentoLegajoApiTest.php tests/Feature/DocumentoRecepcionVisorTest.php tests/Feature/ResourceApiTest.php
docker compose exec frontend pnpm test
docker compose exec frontend pnpm exec eslint src/components/pdf/PdfPreviewField.jsx src/features/legajos/components/seccion04 src/features/legajos/api/seccion04Api.js src/features/legajos/components/seccion03/Revision03Form.jsx src/features/legajos/pages/LegajosPage.jsx src/features/legajos/config/legajoSections.js
docker compose exec frontend pnpm build
```

Las dos migraciones nuevas ya se aplicaron al entorno local. Permanecen las migraciones antiguas pendientes de fotografía y adjuntos de Sección 01. En este entorno `foto_path` ya existe aunque su migración figura pendiente; no se corrigió esa discrepancia y por eso se aplicaron rutas específicas en vez de ejecutar una migración general.

Abre `http://localhost:5173`, ingresa, abre un legajo y selecciona **Experiencia laboral → Documentos y tiempo de experiencia**. Registra certificados con periodos consecutivos, superpuestos, separados y abiertos; comprueba PDF antes de guardar. Usa una cuenta ADMIN para aceptar, observar o anular, incluida la que cargó el documento. Comprueba el fedateo, los folios y que aceptar cambie el total mientras observar/anular lo excluye. Prueba resolución y otros documentos, que no añaden tiempo por sí mismos. Revisa a 400 px y con teclado.

Las migraciones son reversibles; la del catálogo impide retirar tipos que ya tengan documentos. No ejecutes rollback en una base con evidencias para intentar eliminar registros históricos.

## Archivos creados

- `backend/database/migrations/2026_10_05_163133_add_seccion04_experiencia_metadata.php`: agrega clasificación, tipo de vínculo, función documental e índices; reversión de esas extensiones.
- `backend/database/migrations/2026_10_05_163133_seed_seccion04_catalogo.php`: catálogo y cuatro parámetros sin sobrescribir datos existentes; reversión protegida.
- `backend/database/seeders/Seccion04CatalogoSeeder.php`: tres tipos documentales idempotentes con banderas.
- `backend/app/Support/TiempoExperiencia.php`: unión inclusiva, sectores, indicadores, corte y avisos configurables.
- `backend/app/Support/ResumenSeccion04.php`: catálogo de ítems y conteo de documentos exigidos/aceptados.
- `backend/app/Support/Documentos/RevisionDocumento.php`: flujo compartido de revisión de Secciones 03/04, con recepción, fedateo, historial y auditoría.
- `backend/app/Http/Controllers/Api/V1/Legajos/Seccion04Controller.php`: consulta, carga atómica y revisión de experiencia.
- `backend/tests/Feature/TiempoExperienciaTest.php`: días inclusivos, uniones, bisiestos, sectores, indicadores, parámetros y filtros de evidencia.
- `backend/tests/Feature/Seccion04ApiTest.php`: exigencias, carga, validaciones, folios, permisos, revisión, subsanación, anulación y reversión.
- `frontend/src/features/legajos/api/seccion04Api.js`: envío multipart de PDF/datos y cambios de estado.
- `frontend/src/features/legajos/components/seccion04/Seccion04Panel.jsx`: resumen, documentos, tiempo, revisión y folios.
- `frontend/src/features/legajos/components/seccion04/Documento04Form.jsx`: campos por ítem con layout y PDF compartidos; avisos no bloqueantes.
- `frontend/src/features/legajos/components/seccion04/LineaTiempoExperiencia.jsx`: barras por sector, fechas, solapamientos y huecos, adaptadas a móvil.
- `docs/seccion04-experiencia-laboral.md`: entrega, decisiones, archivos y comandos.

## Archivos modificados

- `backend/app/Models/ExperienciaLaboral.php`: campos y casts de clasificación y vínculo.
- `backend/app/Models/DocumentoUsuario.php`: función asignada del documento.
- `backend/app/Http/Controllers/Api/V1/ExperienciaLaboralController.php`: protege experiencias documentales ante edición, borrado o vinculación por CRUD.
- `backend/app/Http/Controllers/Api/V1/DocumentoUsuarioController.php`: evita crear evidencias de los tipos nuevos sin el formulario de experiencia.
- `backend/app/Http/Controllers/Api/V1/Legajos/DocumentoLegajoController.php`: evita eludir la carga específica y bloquea visor de infectados en Sección 04.
- `backend/app/Http/Controllers/Api/V1/Legajos/Seccion03Controller.php`: delega su revisión al servicio compartido, conservando su contrato.
- `backend/app/Support/Documentos/CargaDocumento.php`: excluye anulados también al validar subsanación en Sección 04.
- `backend/config/legajo.php`: valores de respaldo de la convención 30/360/90.
- `backend/config/permisos.php`: amplía la descripción del permiso de revisión a experiencia laboral.
- `backend/routes/api.php`: registra consulta, carga y revisión con permisos.
- `frontend/src/features/legajos/components/seccion03/Revision03Form.jsx`: permite reutilizar envío/nota y añade anulación para Sección 04, manteniendo sus valores originales por defecto.
- `frontend/src/features/legajos/config/legajoSections.js`: entrada de la Sección 04 específica, conservando las consultas anteriores.
- `frontend/src/features/legajos/pages/LegajosPage.jsx`: integra el panel nuevo en el legajo.
- `frontend/src/components/pdf/PdfPreviewField.jsx`: nombre/peso ocupan una fila completa para que el archivo sea legible en formularios estrechos; sin cambios de envío.

## Pendientes

- Integración real de antivirus y flujo de fiscalización posterior con entidades emisoras: permanecen independientes y no se presentan como completados.
- Consumidor de indicadores y configuración institucional de requisitos: no existen aún en el módulo actual.
- Corregir la discrepancia de migraciones antiguas, fuera de este alcance.
- Revisar registros históricos sin documento, sector válido o clasificación; no se inventaron datos ni se corrigieron por cuenta propia.

## Resultado de verificación

- Backend: 124 pruebas relacionadas aprobadas, con 1293 aserciones; incluyen 29 pruebas nuevas y regresiones de Sección 03, documentos, visor y CRUD. No se ejecutó la suite completa.
- Frontend: 12 pruebas aprobadas, ESLint de los archivos afectados y compilación aprobados. Vite conserva una advertencia de tamaño del paquete principal.
- Pint: archivos PHP modificados formateados explícitamente; `--dirty` no dispone del repositorio Git dentro del contenedor.
- Chromium con API simulada: carga, guardado, revisión, permisos, PDF con DPR 2 y anchos de 1280, 900, 520 y 400 px comprobados, sin desbordamiento horizontal.
- HTTP real con Sanctum: CSRF 204; inicio de sesión, listado de legajos, Sección 04 y cierre de sesión 200. Esta comprobación no cargó evidencias de prueba en PostgreSQL.
- Las dos migraciones nuevas fueron aplicadas; las migraciones antiguas pendientes no se alteraron.
- `git diff --check` aprobado. Archivos temporales del navegador retirados y procesos de comprobación detenidos.
