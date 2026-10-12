# ESTADO_PROYECTO.md

Documento vivo. Se actualiza al cerrar cada tarea y se vuelve a subir al proyecto. Última actualización: 04/10/2026.

## Estado del frontend

Módulos en `src/features/`: `auth`, `dashboard`, `evaluations`, `legajos`, `reportes`, `requirements`, `settings`, `users`.
Rutas con `react-router-dom` 7. Tailwind. Datos con `useLiveQuery` (se refresca solo tras cada cambio).

**Módulo `features/legajos/`** (conectado a la API real, ya sin datos de ejemplo):
- `pages/LegajosPage.jsx`: directorio de expedientes y ficha por persona con menú por sección.
- `components/documentos/`: `SubirDocumentoForm`, `ListaDocumentos` y `VisorPdf`, comunes a todas las secciones.
- `components/seccion01/` y `components/seccion02/`: paneles de cada sección (datos y documentos juntos).
- `config/legajoSections.js`: menú del legajo; `config/seccion01.js` y `config/seccion02.js`: etiquetas y grupos.

## Servicio común de documentos (todas las secciones)

- **Subida:** `POST expedientes/{u}/documentos` (`documento.subir`, `DocumentoLegajoController::store`, `Support/Documentos/CargaDocumento`).
  Valida que el archivo sea realmente un PDF y su tamaño (`parametros_sistema`). Guarda hash y páginas, folios correlativos por sección (`Folios`) y los metadatos obligatorios, y registra el historial de estados.
- **Folio sugerido:** `GET expedientes/{u}/documentos/folio-sugerido` (`ultimo_folio + 1`).
- **Recepción (art. 4.5):** `POST …/documentos/{d}/recepcionar` (`documento.recepcionar`). Guarda quién y cuándo, una sola vez.
- **Visor y descarga:** `GET …/documentos/{d}/archivo?accion=ver|descargar` (`legajo.ver`). Se sirve desde el disco privado y cada acceso se registra en `bitacora_accesos_legajo`.
- **Sin borrar ni reemplazar:** un documento se corrige subiendo otro que lo subsana.
- **Enlace con los datos:** `FormulariosDatos` une cada documento con su registro (vínculos, adendas, inducciones, períodos de prueba, familiares, declaraciones, certificados).
- **Datos sensibles:** las secciones 01, 09 y 10, y los tipos marcados como sensibles o de salud, exigen además `datos_sensibles.ver`.

## Sección 02 (Incorporación): hecho

- **Catálogo:** `Seccion02CatalogoSeeder`, con 4 subsecciones (2.1 a 2.4) y 13 tipos (a a m). Persona demo: `Seccion02DemoSeeder`.
- **Lectura:** `GET expedientes/{u}/seccion-02` (`Seccion02Controller::show`). Devuelve las subsecciones con su exigencia (`PerfilDocumental`), los documentos, vigencia y adendas del vínculo, inducciones faltantes, alertas del período de prueba y el resumen (`ResumenSeccion02`).
- **Subida, recepción y visor desde la ficha:** `Seccion02Panel.jsx` (Documentos) con `SubirDocumentoForm`, `ListaDocumentos` y `VisorPdf`. Tests: `DocumentoLegajoApiTest` y `DocumentoRecepcionVisorTest`.
- **Vínculos y adendas:**
  - Registro, y corrección solo mientras el vínculo no tiene fecha de cese.
  - Anulación con motivo, solo administración: `POST …/seccion-02/{tipo}/{registro}/anular`.
  - Vista aparte de anulados: `GET …/seccion-02/anulados`.
  - Migración `2026_10_02_210000_add_anulacion_to_vinculos_laborales_and_vinculo_adendas`. Test: `Seccion02CierreTest`.
- **Datos del puesto** (perfil docente o administrativo según el tipo de personal del vínculo): `PUT …/vinculos-laborales/{v}/datos-puesto` y `DatosPuestoForm` (`seccion02/Formularios.jsx`). Solo con el vínculo abierto.
- **Inducciones y períodos de prueba:** registro y anulación. El período de prueba admite un solo resultado; para corregirlo, se anula.
- **Abrir legajo desde la ficha:** `POST expedientes/{u}/legajo` (`legajo.abrir`, `AperturaLegajoController`) y `AbrirLegajo.jsx` en `PersonalHeader`, en la 01 y en la 02. Crea el número correlativo y las 13 secciones, con un solo legajo activo por persona. Test: `Seccion02CierreTest`.

## Sección 02: falta

- **CRUD genérico cerrado (04/10/2026):** `routes/api.php` quita `update` y `destroy` a `vinculos-laborales`, `vinculo-adendas`, `inducciones`, `periodos-prueba`, `perfil-docente` y `perfil-administrativo` (responden 405); se corrigen y se anulan solo desde `Seccion02Controller`. `documentos-usuario` y `archivos-documento` quedan solo de lectura (`index` y `show`); los documentos se crean solo por la carga del legajo. Test: `CrudGenericoSoloLecturaTest`.
- **`store` también cerrado (04/10/2026):** esos seis recursos quedan solo de lectura en el CRUD genérico (`index` y `show`; `POST /api/v1/<recurso>` responde 405), igual que documentos y archivos. Se crean solo desde `Seccion02Controller` (`POST /api/v1/expedientes/{usuario}/<recurso>`, que valida legajo abierto, autor y vínculo abierto); los perfiles docente y administrativo, con `PUT .../vinculos-laborales/{vinculo}/datos-puesto`. Antes se comprobó que ni el frontend ni las secciones 01 y 02 usaban el `store` genérico. Para datos de prueba, usar factories o seeders, no el CRUD genérico. Tests: `ResourceApiTest` y `CrudGenericoSoloLecturaTest`.
- **Verificación, observación, marcar como falso y fiscalización (art. 7):** los estados existen, pero no hay acciones. Fuera del alcance actual.
- **Sin probar en el navegador:** la recepción y el visor solo tienen tests de API.

## Sección 01 (Información personal y familiar): rama `feature/seccion-01-alinear` (sin commit)

- **Catálogo:** `Seccion01CatalogoSeeder`, sin subsecciones (la directiva no las define). Tipos a a l del Anexo 01, el de "otros documentos de la universidad" y dos del Anexo 05 marcados como pendientes.
- **Documentos:** la 01 usa el servicio común. Cada documento se enlaza con su familiar, declaración o certificado, comprobando que sean compatibles (`Support/Seccion01`). Los PDF antiguos (`pdf_path`) se ven en modo solo lectura y no se borran.
- **Exigencias:** se calculan también por familiar (`Support/ExigenciasSeccion01`):
  - g e i: si hay cónyuge o concubino/a.
  - j: un DNI por cada hijo menor de edad; sin fecha de nacimiento queda "por determinar".
  - e y k: solo aplican con su bandera.
- **Seguridad:** el CRUD genérico de familiares, declaraciones y certificados exige `datos_sensibles.ver` y no permite borrar. La descarga de los PDF antiguos se registra en la bitácora.
- **Menú:** seis grupos y resumen (`Seccion01Panel.jsx`). Persona demo: `Seccion01DemoSeeder` (99000101).

## Sección 05 (Movimientos del personal): hecho, sin commit

- **Estructura (Anexo 01):** tres entradas en la ficha, sin subsecciones (la directiva no las define): «Licencias, permisos y suspensiones» (a, e, suspensión y descanso médico), «Vacaciones» (b, c, d) y «Otros documentos» (gancho de la universidad). Reemplaza el menú anterior (`licencias-permisos`, `periodos-vacacionales`, `goces-vacacionales` y «Documentos de la sección»).
- **Catálogo:** `Seccion05CatalogoSeeder` (8 tipos, todos con varios documentos y ninguno obligatorio). El bloque de cada tipo y qué movimientos acredita están en `Support/Seccion05`.
- **Datos:** se reutilizan `licencias_permisos`, `periodos_vacacionales` y `goces_vacacionales` sin migraciones. Se registran y se anulan desde `Seccion05Controller` (`GET/POST …/seccion-05/…`); no se corrigen. El CRUD genérico de esas tablas queda en solo lectura.
- **Documentos:** servicio común, con folios propios de la sección 5. `FormulariosDatos` enlaza el documento con la licencia o el goce si son compatibles (por ejemplo, b) solo con goces).
- **Descanso médico:** se trata como dato de salud (num. 6.2). Sin `datos_sensibles.ver` no se ve, no se registra ni se sube. Test: `Seccion05ApiTest`.

## Límites del asistente

Límites propuestos del asistente (**pendiente de confirmar con el cliente**):

| Acción | Administrador | Asistente |
|---|---|---|
| Ver personal y legajos | Sí | Sí |
| Subir documentos y llenar datos | Sí | Sí |
| Registrar recepción (queda su nombre) | Sí | Sí |
| Verificar, observar o marcar falso | Sí | No |
| Fedateo | Sí | No |
| Anular registros | Sí | No |
| Aprobar accesos de terceros y custodia | Sí | No |
| Crear usuarios y asignar roles | Sí | No |
| Catálogos, configuración y auditoría | Sí | No |
| Datos sensibles y de salud | Sí | A definir |

Motivo: quien sube un documento no debería ser quien lo verifica.

## Pendientes de decisión

Generales:
1. Sección 12 no tiene formulario de datos en el SQL: ¿solo PDF o falta tabla?
2. "Evaluaciones" está en el menú del Figma: ¿entra al proyecto?
3. Fórmula de completitud del legajo (¿cuenta cargados, recepcionados o solo verificados?). Hoy los resúmenes muestran cada número por separado.
4. ¿El asistente ve datos sensibles?
5. Portal del servidor: ¿ahora o después?
6. Una base por cliente (recomendada) o compartida.
7. Fedateo: ¿solo un check o se sube evidencia?

Documentos y sección 02 (TODO en el código):
8. Recepción (art. 4.5): el sello y la firma son físicos; ¿quien sube puede recepcionar su propia carga? Hoy se permite (`DocumentoLegajoController::recepcionar`).
9. Cifrado en reposo y antivirus de los PDF (`CargaDocumento`).
10. ¿Un documento "observado" cuenta como presente o cargado? (`EstadoDocumento`, `ResumenSeccion02`).
11. Qué documento de formalización (c a g) corresponde a cada régimen, y si b) aplica a todo el personal de confianza (`Seccion02CatalogoSeeder`).
12. ¿Anular un vínculo debe anular sus adendas, inducciones y períodos de prueba? (`Seccion02Controller::anularRegistro`).
13. Fin efectivo del vínculo: ¿la fecha de fin de la última adenda? (`Seccion02Controller::vinculo`).
14. ¿El perfil docente o administrativo debe conservar historial? Hoy se corrige sobre el mismo registro.
15. Apertura del legajo: formato (físico o digital), ubicación física, y si alguien con legajo pasivo puede abrir uno nuevo (`AperturaLegajoController`).

Sección 01:
16. ¿Manda el Anexo 01 o el Anexo 05? (antecedentes judiciales, DJ de intereses, policiales, Ley 31419, RNSSC/REDJUM, CONADIS del cónyuge).
17. ¿Qué identifica a los cargos de libre designación y remoción, para exigir f)?
18. ¿g, i y j dependen de los familiares registrados (como hoy), de `tiene_derechohabientes` o del estado civil?
19. ¿c, d y e admiten varias presentaciones (renovaciones, DJBR anual)? ¿Qué otros registros cubre el "entre otros" de h)?
20. ¿Los PDF anteriores al sistema de folios cuentan como cargados? ¿Se exige el DNI de todos los cónyuges registrados? ¿k aplica también a los hijos?
21. ¿Se puede descargar un PDF de la 01 si la persona no tiene legajo? Hoy no, porque la bitácora exige un legajo.

Sección 05:
22. Rol de vacaciones: ¿solo documento (como hoy) o también datos estructurados? ¿Se exige cada año?
23. «Suspensión»: hoy va en la 05, según el Anexo 01. ¿Las suspensiones disciplinarias van aquí o en la 08?
24. ¿Además del descanso médico, otras licencias son datos de salud (por ejemplo, asistencia médica a un familiar)?
25. Días de un movimiento: ¿calendario o hábiles? Hoy se registran tal como dice el documento, sin superar el rango de fechas. ¿Los permisos por horas se registran con 0 días?
26. ¿Algún documento de la 05 debe ser obligatorio (hoy ninguno)? ¿Hace falta controlar un saldo vacacional? Hoy solo se informan los días de goce y de adelanto por período.

## Siguientes pasos

1. Revisar y hacer commit de la sección 01 (`feature/seccion-01-alinear`) y probarla en el navegador con la persona demo 99000101.
2. Bandeja de requerimientos y observaciones.
3. Verificación y fiscalización de documentos (art. 7), cuando el cliente defina el flujo.
4. Secciones 03, 04 y 10 (resto del núcleo común) con el mismo patrón: catálogo, datos y documentos juntos.

## Historial

- 30/09/2026: se definió el panel principal y la sección Legajos (mockups), y se generó el módulo `features/legajos/` con datos de ejemplo.
- 02/10/2026: sección 02 cerrada en lo principal: subida de PDF con folios, recepción, visor con bitácora, anulación de vínculos y adendas, datos del puesto y apertura del legajo desde la ficha.
- 04/10/2026: sección 01 alineada con la directiva: catálogo, seguridad del CRUD, PDF sin borrado, servicio común de documentos, exigencias por familiar y menú por grupos (sin commit).

## Configuración (30/09 → 11/10/2026)
- /configuracion tiene pestañas Institución y Funcionalidades (?pestana=...).
  Archivos: features/settings/SettingsPage.jsx (contenedor), InstitucionConfig.jsx (nuevo),
  FuncionalidadesConfig.jsx (sin cambios), layouts/AccountMenu.jsx.
- Permisos: sistema.administrar → Institución; configuracion.modulos → Funcionalidades.
  Cada ruta del backend valida su propio permiso (api.php:95-100). Tests en
  ConfiguracionBloqueoTest e InstitutionSettingsTest.
- ATENCIÓN: el comodín '*' del rol ADMIN NO incluye configuracion.modulos. Un
  administrador nuevo necesita los dos roles (ADMIN y configuracion_modulos).
- Un usuario sin ningún permiso no puede iniciar sesión (AuthController.php:31).
- La base real tiene 68 tablas; escalafon_drawdb_55.sql está desactualizado.
- asistente_legajo aún no existe en la base (pendiente de confirmar con el cliente).
- Pendiente: Funcionalidades con 25 opciones es larga; plegar por sección (después).

## Banderas con tres estados (11/10/2026)
- Las banderas admiten Sí / No / Sin responder (null). Migración aplicada en la base
  real el 11/10 (lote 14): 155 valores false pasaron a Sin responder; los true se
  conservaron. Respaldos en /home/willian/proyectos/respaldos_escalafon/.
- Regla: Sí = se exige; No = se oculta y la carga se rechaza con 422 (salvo que ya haya
  documentos cargados; subsanar siempre se permite); Sin responder = se ofrece con aviso
  "Falta definir si corresponde" y no cuenta en la completitud.
- Varios vínculos: Sí si alguno vigente es Sí; No solo si todos son No; si no, null.
  Se ofrece el tipo si cualquier vínculo no anulado (también cesado) dice Sí.
- Las 4 banderas del puesto (DJBR, colegiatura, estudios básicos, período de prueba) se
  editan solo en el vínculo. En la persona quedan tiene_derechohabientes y con_discapacidad.
  Quien no tiene vínculo vigente no puede responder las del puesto.
- con_discapacidad ("el servidor o alguno de sus hijos", punto 1k) exige el permiso
  discapacidad.editar (ADMIN lo recibe; el asistente no). Cada cambio queda en
  auditoria_eventos. Quien no tiene datos_sensibles.ver no ve los tipos de las secciones
  01, 09 y 10 en no-aplicables.
- Dashboard: tarjeta "Condiciones del personal sin responder" (sistema.administrar).
  Endpoint GET personal/banderas-sin-responder. Hoy: 19 personas.
- Regla 5 (g, i, j): con familiares registrados se exigen por familiar sea cual sea la
  bandera; sin familiares: Sí = por determinar, No = no aplica, Sin responder = falta definir.
  PENDIENTE de confirmar con el cliente (pregunta 18).
- Sigue abierta la pregunta 20 (¿k aplica también a los hijos con un certificado por
  persona?).
- Las banderas también ocultan módulos: Discapacidad y Familia (sección 01) si están en
  No y sin datos registrados (familiares, certificados o documentos g, i, j, k); y la
  subsección 2.4 si período de prueba está en No y sin períodos ni documentos m. Con datos,
  se mantienen. El backend sigue permisivo: acepta registros aunque el módulo esté oculto.
  Quien no tiene datos_sensibles.ver no recibe la lista de módulos ocultos.
- Si lo oculta una bandera, se redirige a la primera categoría con un aviso; si lo
  desactiva Funcionalidades, sigue «Función no disponible».
- Cada compañero debe correr php artisan migrate en su base local tras traer los commits.
- Reglas para pruebas con Code: solo en copia de la base; nunca artisan serve con
  DB_DATABASE para cambiar de base; borrar solo archivos que él creó, por nombre; no tocar
  sesiones del navegador.
- Pendientes de esta línea: entrada «Colegiaturas» del menú (solo lectura, no sigue la
  bandera); test intermitente de la suite (2 fallos en 32 corridas, UniqueConstraint
  Violation en ExpedienteApiTest y Seccion06ApiTest; falta capturar el mensaje completo);
  unificar regimen_laboral (hoy mezcla ley y eje); clasificación del catálogo por régimen
  y tipo de personal (propuesta con 3 decisiones abiertas); documentos "por evento";
  sección 08 y 7.2.