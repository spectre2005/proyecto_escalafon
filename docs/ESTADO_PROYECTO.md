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
6. ~~Una base por cliente (recomendada) o compartida.~~ Decidido el 13/10/2026: una base por cliente.
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

Asistente con IA (idea, sin implementar):
27. ¿La universidad acepta que los PDF se procesen en un servicio de IA externo (nube), o la información no puede salir de sus servidores (modelo local)? Hay datos personales y de salud (Ley 29733). De esto depende todo el diseño. Propuesta: usar un modelo existente, sin entrenar uno propio. La IA solo propone (metadatos del PDF, tipo de documento, avisos de páginas en blanco o titular distinto) y el personal de la ORH confirma. Nunca guarda, recepciona ni verifica. Se activa por entidad en `parametros_sistema` y queda registrado lo que propuso y lo que se confirmó. Piloto sugerido: precarga de metadatos en las secciones 02, 03, 04 y 07.

Folios automáticos (idea, sin implementar):
28. ¿Un folio es una página del PDF o una hoja física? ¿Escanean a doble cara y cuentan las páginas en blanco? La directiva (art. 9.3) solo dice "foliados por sección de forma correlativa". Si folio = página, el sistema puede asignar los folios solo, a partir del número de páginas (hoy ya lo hace con el DNI: `CargaDocumento`, `foliosAutomaticos`). Antes hay que contar las páginas con una herramienta fiable (`qpdf` o `pdfinfo`), porque `ArchivoPdf::paginas()` usa expresiones regulares y puede equivocarse. Si folio = hoja, hace falta un ajuste manual con motivo.

Accesos delegados (sugerencia del docente, 13/10/2026; pendiente de confirmar con el cliente):
29. Que el jefe de la ORH dé cuenta y rol a su personal de apoyo (arts. 6.3 y 6.4) desde una pantalla «Usuarios y
    accesos», con fecha de fin opcional y auditoría. Hoy no hay forma en producción (el CRUD genérico es de solo
    lectura). Propuesta: roles fijos (`asistente_legajo` ya definido en config/permisos.php, sin crear en la base).
    Preguntas: (a) confirmar la tabla «Límites del asistente»; (b) ¿ve datos sensibles? (pend. 4); (c) ¿quien sube
    puede recepcionar? (pend. 8); (d) ¿el jefe puede crear otros administradores o solo ayudantes?; (e) ¿alcance por
    tipo de personal o unidad orgánica?

## Hoja de ruta del sistema institucional (por definir, analizado el 13/10/2026)

Funcionalidades que faltan, con su base en la directiva. Orden propuesto, sin decidir:
1. Verificar, observar o marcar falso documentos en todas las secciones, con bandeja de pendientes (hoy solo
   «aceptar u observar» en 03, 04, 06, 08 y 10, cada una por su lado). Depende de pend. 3 y 10.
2. Fiscalización posterior (art. 7, Anexos 03 y 04): iniciar, oficio, resultado y plazos (personal de confianza: inicia
   a los 2 días hábiles de vinculado y termina en 15). El ítem del menú existe sin pantalla.
3. Formatos imprimibles: carátula (Anexo 09), control de incorporaciones (06), matriz de legajos (08), referencia de
   legajo pasivo (07).
4. Inicio con alertas: requerimientos por vencer (5 días hábiles + 5 de prórroga), actualización (30 días hábiles),
   documentos sin recepción, vínculos y fiscalizaciones por vencer, completitud (pend. 3). Verificar plazos en el PDF.
5. Accesos y custodia (art. 6, Anexo 02): solicitudes, préstamo y devolución. El ítem del menú existe sin pantalla.
6. Secciones 12 (pend. 1) y 13 (legajo pasivo), las únicas sin pantalla.
7. Usuarios y accesos (pend. 29). 8. Auditoría visible para el jefe. 9. Notificaciones (tabla sin uso).
- Mientras tanto: ocultar o marcar «Próximamente» Fiscalización y Accesos y custodia en el menú.
- Recuadro «Un espacio para tu institución» del menú lateral (`layouts/Sidebar.jsx`): duplica el menú Configuración,
  lo ven todos aunque solo sirve al administrador y no desaparece. Propuesta (por definir): mostrarlo solo a
  administradores mientras la institución no se haya personalizado (`configured`), y separar el nombre legal del
  contrato (panel de plataforma) de la identidad visible (configuración de la institución).

## Hoja de ruta del panel de plataforma (superadmin) (por definir, analizado el 13/10/2026)

Hoy tiene: resumen por estado, respaldos y últimas 8 operaciones; listado, registro y detalle de instituciones (datos,
aprovisionar, activar/suspender, tiempo de uso, administradores, respaldos, auditoría). Por consola: superadmins,
migraciones y verificación. Respaldos (17/10/2026): uno por institución, copia externa cifrada opcional con rclone
(Google Drive u otro; de quién es la cuenta: pendiente del cliente), verificación y restauración por institución
(docker/produccion/respaldar.sh y restaurar.sh, docs/PRODUCCION.md). Falta, en el orden propuesto (sin decidir):
1. ~~Datos del cliente y del contrato: razón social, RUC, contacto, N.º de contrato y fecha de inicio.~~ Hecho el
   18/10/2026 (RUC con dígito verificador de SUNAT).
2. ~~Resumen útil: vencimientos en los próximos 30 días, en gracia o vencidas, ingresos del mes y del año.~~ Hecho
   el 18/10/2026 (ver «Tiempo de uso pagado»).
3. Seguridad del superadmin: ~~gestionarlos desde el panel, cambiar la propia contraseña~~ hecho el 18/10/2026
   (crear, restablecer, desactivar y reactivar con contraseña temporal; SesionPlataformaValida cierra las sesiones de
   una cuenta desactivada o con la contraseña cambiada). Pendiente de decidir: verificación en dos pasos.
4. Dar de baja una institución y exportar sus datos (base y PDF) al terminar el contrato; luego archivarla.
   Diseño propuesto, sin aprobar: docs/DISENO_BAJA_EXPORTACION_INSTITUCIONES.md (9 decisiones pendientes). Hecha solo
   la entrega 1: ResolverInstitucion en lista blanca (solo `activa` opera; un estado futuro o desconocido recibe 403) y
   un estado no reconocido no se activa ni se suspende. Pendiente: enlaces simbólicos de UNIQ (`tar -h`) antes de exportar.
   Herramienta de inventario y normalización del almacenamiento (`plataforma:normalizar-almacenamiento`; sin opciones
   solo inventaría): probada en entornos aislados, **no ejecutada** sobre UNIQ. Modo `unica` resuelto con
   `PLATAFORMA_ALMACENAMIENTO_UNICA` (vacía = carpetas comunes; se pone solo después de normalizar; `--cambiar` indica
   la línea y `produccion:verificar` la revisa; diseño 10.7). Normalizar espera las demás decisiones de la sección 10.8.
5. ~~Uso por institución (personas, legajos, espacio, último ingreso) y estado técnico~~ hecho el 18/10/2026
   (UsoInstitucion, página «Estado técnico» y sección en el detalle): base de datos, migraciones, carpeta, respaldo y
   copia externa. Último ingreso = último inicio de sesión (usuarios.ultimo_acceso). El espacio no sigue enlaces
   simbólicos y la medición se guarda 10 minutos. Pendiente: trabajos en cola fallidos por institución.
6. Comprobante adjunto al pago, auditoría global con filtros y exportación, avisos a las instituciones.
7. Planes y límites: pendiente del cliente.
- No recomendado: «entrar como» una institución para soporte (vería datos personales y de salud).

## Siguientes pasos

0. Pendiente para la próxima sesión (16/10/2026, rama `feature/correcciones-vinculo`): revisar y mejorar la experiencia de usuario de las secciones de carga de documentos del legajo. Evaluar el flujo de carga, la distribución de los formularios y documentos y la facilidad para registrar y consultar información. No implementar cambios hasta revisar el problema en la próxima sesión.
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
- configuracion.modulos sigue siendo explícito: el comodín '*' solo no lo da. Desde el 17/10/2026, el rol
  ADMIN lo nombra junto a '*' (config/permisos.php): el administrador de cada institución configura sus
  funcionalidades, con motivo y auditoría. El rol configuracion_modulos queda para otra persona que no sea ADMIN.
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
  se mantienen. Desde el 12/10 (commit feb8c99) el backend rechaza los registros nuevos
  incompatibles con una condición en No: ver «Condiciones del perfil» más abajo.
  Quien no tiene datos_sensibles.ver no recibe la lista de módulos ocultos.
- Si lo oculta una bandera, se redirige a la primera categoría con un aviso; si lo
  desactiva Funcionalidades, sigue «Función no disponible».
- Cada compañero debe correr php artisan migrate en su base local tras traer los commits.
- Reglas para pruebas con Code: solo en copia de la base; nunca artisan serve con
  DB_DATABASE para cambiar de base; borrar solo archivos que él creó, por nombre; no tocar
  sesiones del navegador.
- Pendientes de esta línea: entrada «Colegiaturas» del menú (resuelto el 12/10: ahora sigue
  la bandera, ver «Condiciones del perfil»); test intermitente de la suite (2 fallos en 32 corridas, UniqueConstraint
  Violation en ExpedienteApiTest y Seccion06ApiTest; falta capturar el mensaje completo);
  unificar regimen_laboral (hoy mezcla ley y eje); clasificación del catálogo por régimen
  y tipo de personal (propuesta con 3 decisiones abiertas); documentos "por evento";
  sección 08 y 7.2.

  ## Régimen: ley y tipo de vínculo (12/10/2026)
- La ley va en usuarios.regimen_normativo_id (catálogo: 1057/CAS, 276, 728, 30057,
  30220), filtrada por tipo de personal. usuarios.regimen_laboral queda solo como
  respaldo y ya no se edita.
- El tipo de vínculo (carrera / plazo_determinado / designado) sale solo de
  vinculos_laborales.regimen_laboral, con CHECK en la base. Sin vínculo o vínculo
  antiguo sin tipo = «Sin definir» (NULL): no se oculta nada por régimen y lo que
  depende del tipo queda «por determinar». Todo vínculo nuevo exige tipo.
- Migración aplicada en la base real (12/10, lote 15): 12 personas completadas con su
  ley, 4 vínculos con «276» pasaron a «Sin definir». Valores anteriores en
  respaldo_regimen; el rollback los restaura. Respaldos en
  /home/willian/proyectos/respaldos_escalafon/.
- Sugerencias de tipo (nunca se aplican solas): docente ordinario → carrera;
  contratado → plazo determinado; confianza → designado; CAS → plazo determinado;
  D.L. 276 sin sugerencia, lo confirma el administrador.
- Vínculo cerrado sin tipo: se puede completar solo el tipo (POST .../completar-tipo),
  queda en auditoría y no se vuelve a cambiar.
- Personas con ley sin definir (6) y 3 con código y ley en conflicto (75855996,
  99000007, ADMIN-SISTEMA): las revisa el administrador.
- Incoherencias de tipo de personal entre persona y vínculo (75855996, 46945470):
  requisito previo de la clasificación del catálogo.
- Cada compañero debe correr php artisan migrate.
- Pendiente: clasificación del catálogo por régimen y tipo de personal, documentos
  «por evento», test intermitente (UniqueConstraintViolation), preguntas 11, 18 y 20.

## Condiciones del perfil: Sí, No y Sin responder (12/10/2026, commit feb8c99)
- Seis condiciones revisadas. De la persona (usuarios): tiene_derechohabientes y
  con_discapacidad. Del puesto (vinculos_laborales): obligado_djbr, exige_colegiatura,
  exige_estudios_basicos y tiene_periodo_prueba. Las seis admiten los tres estados
  (null = Sin responder); no hizo falta migración.
- Qué controla cada una:
  - tiene_derechohabientes: módulo Familia, documentos g, i, j y familiares marcados como
    derechohabientes.
  - con_discapacidad: módulo Discapacidad, documento k y certificados de discapacidad.
  - obligado_djbr: documento S01_E y la declaración «djbr» (sección 01).
  - exige_colegiatura: documento FORM_COLEGIATURA, colegiaturas y la entrada
    «Colegiaturas» del menú (sección 03).
  - exige_estudios_basicos: documento FORM_ESTUDIOS_BASICOS y la formación de nivel
    educación básica (sección 03).
  - tiene_periodo_prueba: subsección 2.4, documento S02_M y períodos de prueba.
- Sí: todo se muestra y funciona normalmente, con las validaciones y permisos de siempre.
- No:
  - Se ocultan solo el apartado y los tipos que controla la condición, y solo si no hay
    datos registrados. Nunca una sección completa.
  - La 2.4 y «Colegiaturas» ahora se ocultan del menú, con aviso al redirigir, igual que
    Familia y Discapacidad. Antes la 2.4 solo se atenuaba.
  - No se ofrecen registros ni documentos nuevos. El backend rechaza los documentos
    (CargaDocumento, ya existía) y, desde feb8c99, también los registros nuevos
    incompatibles (App\Support\CondicionesRegistro):
    - familiar marcado como derechohabiente;
    - certificado de discapacidad;
    - declaración «djbr»;
    - colegiatura;
    - formación de educación básica;
    - período de prueba (Seccion02Controller, ya existía).
  - Un familiar que no es derechohabiente sí se puede registrar.
- Sin responder:
  - Se muestra «Por definir» o «Falta definir si corresponde»; en la 2.4 hay un aviso nuevo.
  - No se interpreta como Sí ni como No y no cuenta en la completitud.
  - Se permite registrar y cargar.
- Datos históricos: con No se conservan y se pueden consultar.
  - Si hay datos, el apartado sigue visible.
  - Los registros existentes se corrigen y se anulan; una DJBR existente se sigue
    corrigiendo y un familiar ya marcado como derechohabiente lo sigue siendo.
  - Los documentos se pueden subsanar aunque su tipo ya no se ofrezca, también en las
    secciones 03 y 08: «Subsanar» ya no depende de que el tipo aplique.
- Qué se valida al corregir en los endpoints del expediente:
  - Marcar como derechohabiente un familiar que no lo era: se rechaza con No.
  - Cambiar una declaración a tipo «djbr»: se rechaza con No.
  - Las demás correcciones se permiten.
- Limitación del CRUD de administración (/api/v1/familiares, certificados-discapacidad,
  declaraciones-verificaciones, colegiaturas y formacion-academica; permiso
  sistema.administrar):
  - Al crear se aplican las mismas reglas.
  - Al actualizar solo se comprueba marcar un familiar como derechohabiente y cambiar una
    formación a educación básica.
  - En familiares, colegiaturas y formación se puede reactivar un registro anulado
    (anulado = false) sin comprobar la condición.
  - En certificados y declaraciones la actualización solo permite anular.
- Pruebas (informe de feb8c99):
  - Backend: 539 pruebas en verde en dos corridas seguidas. 7 nuevas en
    CondicionesRegistroTest: los tres estados de cada condición, la 2.4, S02_M (carga
    rechazada con No y subsanación admitida), Colegiaturas y el rol sin datos sensibles.
  - Pruebas adaptadas: ModulosBanderasSeccion01Test (antes comprobaba que el backend
    aceptaba los registros) y Seccion01DocumentosTest («Tiene derechohabientes» en Sí
    para registrar una hija derechohabiente).
  - Frontend: lint, 65 pruebas y compilación en verde. Render en servidor del bloqueo de
    la casilla de derechohabiente.
  - No se revisaron las pantallas en un navegador.
- Sigue abierto:
  - La limitación del CRUD de administración descrita arriba.
  - Pregunta 18: con familiares registrados, g, i y j se exigen por familiar sea cual sea
    la bandera.
  - Pregunta 20: ¿k aplica a los hijos con un certificado por persona?
  - Si una persona puede tener varios vínculos vigentes y qué criterio de vigencia se usa
    (un vínculo vencido sin cese sigue contando para las condiciones del puesto).

## Instituciones por subdominio (13/10/2026, rama feature/panel-superadmin)
- Con `PLATAFORMA_MODO=subdominio`, `{slug}.PLATAFORMA_DOMINIO` atiende con la base de datos,
  la carpeta de PDF (`storage/app/private/{almacenamiento}`) y la carpeta de sesiones de esa
  institución (`ResolverInstitucion`, `ConexionInstitucion`). Solo opera si está activa y
  aprovisionada: la suspendida recibe 403 y la registrada o inexistente, 404. El panel de
  plataforma solo responde en el dominio sin subdominio.
- Archivos: todo disco local dentro de `storage/app/private` se reubica en
  `storage/app/private/{almacenamiento}`, que es un espejo de esa carpeta (`documentos/`,
  `fotos-personal/`, PDF anteriores del disco `local` y los discos que se agreguen).
- Con `PLATAFORMA_MODO=unica` (por defecto) no cambia nada: localhost:5173 y la base `escalafon`.
- El proxy de Vite ahora conserva el host del navegador (`changeOrigin: false`).
- En desarrollo: `uno.localhost:5173` (los navegadores resuelven *.localhost sin tocar hosts).
  Requiere `*.localhost:5173` en `SANCTUM_STATEFUL_DOMAINS` y `SESSION_DOMAIN=null`.
- En cada despliegue (y al traer cambios): `php artisan migrate` (base del .env) y luego
  `php artisan plataforma:migrar-instituciones` (base de la plataforma y la de cada institución
  aprovisionada, también las suspendidas; sigue si una falla, termina con error y lo audita).
  Nunca crea una base que falte. Ya está en `iniciar.ps1` y en el README.
- Caché por institución: prefijo propio y, en archivos, carpeta propia
  (`storage/framework/cache/instituciones/{slug}`); incluye el límite de intentos de login.
- Colas (`ColasInstitucion`): con subdominio, los trabajos, lotes y fallos van a la base de la
  plataforma; cada trabajo lleva su institución y el worker lo ejecuta con sus recursos. Si la
  institución ya no está activa, falla sin ejecutarse. Hoy ningún código encola trabajos.
- Ojo: en modo `unica`, `QUEUE_CONNECTION=database` no tiene tabla `jobs` en la base institucional
  (ninguna migración la crea); no afecta mientras nada se encole.
- El login muestra el motivo cuando la institución está suspendida o no existe (`codigo`
  `institucion_suspendida` / `institucion_no_encontrada`). Los tests corren siempre en modo
  `unica` (`phpunit.xml`), aunque el .env use subdominios.

## Aprovisionamiento automático (13/10/2026, rama feature/panel-superadmin)
- Botón «Aprovisionar» en el panel (institución registrada) o `php artisan plataforma:aprovisionar {slug}`.
  Mismo servicio (`Aprovisionamiento`): crea la base, la migra, carga `CatalogosInstitucionSeeder`
  (regímenes, ubigeo, secciones y tipos de documento; sin datos de ejemplo ni unidades orgánicas),
  crea el primer administrador (rol ADMIN) con contraseña temporal mostrada una sola vez (no se
  guarda ni se audita), guarda el nombre en `institution_settings`, crea la carpeta de PDF, verifica
  todo y la marca aprovisionada. No la activa.
- Nunca toca lo existente: si la base ya existe o la carpeta tiene archivos, se rechaza (409). Si
  falla a mitad, retira solo lo creado en esa ejecución y audita `institucion.aprovisionamiento_fallido`.
- `DatabaseSeeder` usa ahora `CatalogosInstitucionSeeder` (mismos seeders, mismo orden).
- Contraseña temporal: el primer administrador queda con `usuarios.debe_cambiar_password`. Hasta
  cambiarla (`PUT /api/v1/auth/password`, con la actual), toda la API responde 403
  `cambio_password_requerido` (`ExigeCambioPassword`) y el frontend muestra solo la pantalla de
  cambio. La marca no es asignable en masa: la pone el aprovisionamiento y la quita el cambio.
- Pendiente: `RegimenNormativoSeeder`
  y `UbigeoSeeder` solo corren en local/testing (en producción la base quedaría sin esos catálogos).

## Primera institución vinculada (13/10/2026, entorno de Willian)
- La institución que ya operaba (base `escalafon`) quedó registrada en la plataforma como `uniq`
  (aprovisionamiento verificado con `plataforma:verificar-aprovisionamiento`; falta activarla).
- Su carpeta `storage/app/private/instituciones/uniq` tiene enlaces simbólicos a `documentos`,
  `fotos-personal` y `certificados-discapacidad`: no se movió ni copió nada, y el modo `unica`
  sigue funcionando igual. En producción, esos archivos se moverían de verdad a su carpeta.
- Solo es local: cada compañero que quiera probarlo hace lo mismo con su propia base.

## Administradores de cada institución desde el panel (13/10/2026)
- En el detalle de una institución aprovisionada: lista de sus administradores (solo cuentas con
  `sistema.administrar` por un rol vigente: nombre, correo, estado y último acceso; nada de
  personas ni legajos), agregar uno, darle una contraseña temporal nueva (olvido) y
  desactivarlo o reactivarlo. Nunca deja a la institución sin un administrador activo.
- Contraseñas temporales: se muestran una sola vez, no se guardan ni se auditan, y obligan a
  cambiarlas al ingresar. Cada operación queda en la auditoría del panel con el correo afectado.
- El primer administrador del aprovisionamiento usa el mismo servicio (`AdministradoresInstitucion`).
- Al restablecer la contraseña o desactivar, la sesión abierta de esa persona termina (401):
  `AuthenticateSession` de Sanctum y la falta de permisos. Comprobado con cookies reales.
- Pendiente: agregar como administrador a una persona ya registrada (hoy se rechaza el correo
  existente).

## Tiempo de uso pagado (13/10/2026)
- Cada pago es un periodo (`periodos_servicio` en la base de la plataforma): desde, hasta (ambos
  incluidos), monto en S/ y comprobante opcionales. No se editan ni se borran; se anulan con motivo.
  No se cruzan entre sí. Todo queda auditado.
- Estado: vigente (periodos consecutivos se encadenan), en gracia (`dias_gracia` por institución,
  0 a 90) o vencido. Vencido = bloqueo total por subdominio (403 `institucion_vencida`) y sus
  trabajos en cola no se ejecutan. Al registrar un pago vuelve a operar sola.
- Sin ningún pago registrado (ni anulado) no se controla: así siguen `uniq`, `uno` y `dos`. Desde
  el primer pago el control queda activo; anular todos los pagos no la libera.
- «Hoy» se calcula en `PLATAFORMA_ZONA_HORARIA` (America/Lima): el último día pagado dura hasta la
  medianoche local, aunque la app corra en UTC.
- Sus administradores (permiso `sistema.administrar`) ven un aviso desde 15 días antes
  (`PLATAFORMA_AVISO_VENCIMIENTO_DIAS`) y durante la gracia; el resto del personal no.
- Pendiente de confirmar con el cliente: moneda (hoy S/), si hay planes con distintas funciones o
  solo tiempo, y quién en la institución recibe el aviso además de sus administradores.
- Resumen del panel (18/10/2026, `App\Support\Plataforma\ResumenCobranza`): por vencer en 30 días, en gracia y
  vencidas salen de `Institucion::servicio()`, así que cada institución está en un solo grupo. Ingresos del mes y
  del año: pagos no anulados, por la **fecha en que se registraron** (`created_at`) en hora de Lima. Los pagos sin
  monto no se suman, se cuentan aparte.
  - Pendiente de confirmar con el cliente: la moneda (se muestra S/).
  - Pendiente de decidir: si hará falta una **fecha de pago** propia, distinta de la fecha de registro (pagos
    registrados tarde o por adelantado). Hoy no existe ese campo; añadirlo requiere una migración.

## Preparación para producción (13/10/2026)
- `docker-compose.produccion.yml` con Caddy (HTTPS automático por subdominio, frontend compilado), app (php-fpm,
  código en la imagen), worker (colas) y PostgreSQL sin puerto público. Guía: `docs/PRODUCCION.md`.
- Caddy solo pide certificados para el dominio de la plataforma y los subdominios de instituciones registradas
  (`/interno/tls`, que no se publica).
- `LocalDevelopmentOnly` pasó a `ApiHabilitada`: fuera de local y pruebas la API solo responde con
  `API_HABILITADA=true`, y el contenedor no arranca si `php artisan produccion:verificar` falla (APP_DEBUG, HTTPS,
  cookies seguras, claves largas, dominio y Sanctum).
- El CRUD genérico por tabla es de solo lectura fuera de local (su escritura salta las reglas de cada sección; el
  frontend solo lo lee). Se abriría con `API_CRUD_GENERICO_ESCRITURA=true`: no sin auditar cada recurso.
- `UbigeoSeeder` y `RegimenNormativoSeeder` corren también en producción (catálogos nacionales, idempotentes).
- Scripts: `docker/produccion/desplegar.sh` (construye, migra todas las bases antes de reemplazar y arranca) y
  `docker/produccion/respaldar.sh` (pg_dump de cada base y archivos privados, 14 días).
- Probado de punta a punta en local con el entorno de producción (dominio de prueba y CA local): despliegue,
  HTTPS por subdominio (un host inventado no obtiene certificado), cabeceras, panel, aprovisionamiento, cambio de
  contraseña, CRUD genérico de solo lectura, actualización conservando datos, respaldo y restauración.
- La política de contraseñas de producción (10 caracteres, mayúsculas, minúsculas y números) muestra sus mensajes en
  español al cambiar la contraseña.

## Mensajes en español (13/10/2026)
- `backend/lang/es` (validación, autenticación, contraseñas, paginación) con las mismas claves que Laravel en inglés;
  un test avisa si una actualización de Laravel agrega una regla sin traducir. Nombres de campo legibles y con tildes
  («fecha de emisión», «correo electrónico»).
- `APP_LOCALE=es` y `APP_FALLBACK_LOCALE=en` (si faltara una traducción, se ve en inglés y no la clave). Cada
  compañero debe cambiar esas dos líneas en su `backend/.env`.
- Alojamiento decidido (13/10/2026): SaaS en un servidor en la nube alquilado por nosotros. Falta el contrato con
  cada universidad (aceptación del proveedor y, si está fuera del Perú, flujo transfronterizo, Ley 29733).
- Pendiente (técnico, propuesto el 13/10/2026): usuarios de PostgreSQL con permisos mínimos. `escalafon_app` (solo
  filas) para el día a día y `escalafon_dueno` (crea bases y migra) para migraciones y aprovisionamiento; el
  superusuario fuera de la aplicación; `produccion:verificar` rechazaría un superusuario. Falta decidir si la
  credencial de dueño vive en la app (A) o solo en el worker, con aprovisionamiento en cola (B); opcional: que la
  base impida modificar o borrar auditoría, bitácora e historial.
- Pendiente antes de datos reales: cifrado de PDF y antivirus (pend. 9),
  usuario de PostgreSQL con permisos mínimos, copia de respaldos fuera del servidor y prueba de restauración,
  avisos por correo.
