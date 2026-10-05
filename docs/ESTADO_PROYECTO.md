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

## Siguientes pasos

1. Revisar y hacer commit de la sección 01 (`feature/seccion-01-alinear`) y probarla en el navegador con la persona demo 99000101.
2. Bandeja de requerimientos y observaciones.
3. Verificación y fiscalización de documentos (art. 7), cuando el cliente defina el flujo.
4. Secciones 03, 04 y 10 (resto del núcleo común) con el mismo patrón: catálogo, datos y documentos juntos.

## Historial

- 30/09/2026: se definió el panel principal y la sección Legajos (mockups), y se generó el módulo `features/legajos/` con datos de ejemplo.
- 02/10/2026: sección 02 cerrada en lo principal: subida de PDF con folios, recepción, visor con bitácora, anulación de vínculos y adendas, datos del puesto y apertura del legajo desde la ficha.
- 04/10/2026: sección 01 alineada con la directiva: catálogo, seguridad del CRUD, PDF sin borrado, servicio común de documentos, exigencias por familiar y menú por grupos (sin commit).
