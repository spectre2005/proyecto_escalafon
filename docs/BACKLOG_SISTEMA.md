# Backlog actualizado: tareas faltantes o sin funcionalidad

**Revisión:** 17/10/2026, America/Lima. **Base local revisada:** `main`, `95e3a75`. Sustituye el backlog anterior basado en `d4c3906`.

## Prueba integral pendiente (19/10/2026)

- **Pendiente de ejecución y aceptación:** recorrer en navegador personal → vínculo → legajo → carga PDF → revisión → reportes; requerimientos completos (incluidas observación, subsanación, prórroga y cancelación); custodia hasta devolución; vencimientos y avisos con fechas controladas; exportaciones y restauración de respaldos. Registrar resultado, evidencia e incidencias. Las pruebas automáticas no sustituyen esta aceptación.
- **Roles:** verificar cuentas distintas de administrador y asistente, recepción permitida de la propia carga y revisión propia prohibida. La comprobación automatizada se mantiene separada de la demostración manual con cuentas reales.

**Comprobación automatizada de roles (19/10/2026):** cuentas ficticias distintas de administrador y asistente en pruebas aisladas; asistente sin acceso a la bandeja ni aceptación/observación, recepción propia permitida, administrador acepta carga ajena y no puede aceptar ni observar la propia. Bandeja y formación: 23 pruebas, 188 aserciones correctas. No se han cambiado cuentas reales; el recorrido manual de inicio de sesión sigue pendiente.

## Avance de implementación (18/10/2026)

Los apartados 1–9 conservan el inventario y diagnóstico de `95e3a75`. Esta tabla describe los cambios actuales, todavía sin commit ni publicación; el inventario histórico no implica que las 55 tareas estén terminadas.

| IDs | Estado del incremento | Resultado y límite |
| --- | --- | --- |
| SEG-02 | Implementada | Accesos del personal con rol fijo de asistente, fechas, revocación, restablecimiento, contraseña temporal e historial. |
| SEG-04, UI-01–03 | Implementadas | Perfil propio, entorno público y cambio de contraseña institucional/plataforma; conserva la sesión actual e invalida anteriores. |
| REQ-01–05, REQ-07–10 | Implementadas | Alta, edición antes de responder, evidencia del mismo legajo/tipo, observación y subsanación, cumplimiento, cancelación con motivo, prórroga explícita, control de versión e historial. |
| REQ-06 | Implementada | Aviso interno de atraso; correo opcional desactivado por defecto, sin documentos ni datos personales en el mensaje. |
| DOC-01, DOC-05 | Implementadas para revisión común | Bandeja paginada, filtros, permisos, aceptación/observación y subsanación mediante nuevo PDF. Completos solo recepcionados y aceptados/verificados; las validaciones propias de formación, experiencia y compensaciones se conservan. |
| DOC-02 | Implementada | ClamAV real, bloqueo ante infección o indisponibilidad, registro de rechazo y bloqueo de descarga. La carga infectada se rechaza antes de almacenarse. |
| DOC-03 | Implementada; conversión de archivos históricos pendiente de ejecución | Cifrado autenticado, clave independiente fuera de Git, rotación y comando de conversión verificada con limpieza pendiente recuperable. No equivale a protección de todos los respaldos históricos. |
| DOC-04 | Implementada | Poppler en ambas imágenes PHP y CI; un folio por página real, correlativo por sección, rechazo sin consumir folios. |
| PER-02 | Implementada | Consulta y cálculo compartidos con inicio, cese efectivo y última adenda válida; fechas incluidas y día de Lima. |
| PER-01, SEC-09 | Implementadas | Pase a pasivo al terminar el último vínculo efectivo y apertura de nuevo legajo conservando persona/documentos. Vista de referencias a pasivos con procedencia y folios originales. Pendiente aceptación integral con ORH. |
| SEC-02 | Parcial | Auditoría de corrección del vínculo con antes/después, actor y fecha. Falta completar la consulta del historial y cubrir todos los cambios de puesto. |
| VEN-04 | Implementada | Todos los motivos paginados, permisos y filtros consistentes; contratos usan el fin efectivo. |
| FIS-02, FIS-03, FIS-05, FIS-06 | Implementadas | Casos, comunicaciones/evidencias, resultados, cierre/informe, reapertura, prórroga, cancelación y control de concurrencia. Registrar comunicación no envía correo. |
| FIS-04 | Implementada para fedateo independiente | PDF de sustento obligatorio del mismo legajo, fedatario, fecha, motivo, control de cambios e historial; conserva el PDF original y los sustentos anteriores. |
| CUS-02, CUS-03 | Implementadas | Solicitud con alcance, aprobación/rechazo/revocación, vigencia, entrega autorizada, préstamo/devolución, historial y aviso de atraso. Datos sensibles restringidos al titular identificado. |
| CUS-04, AUD-01 | Implementadas | Pantallas de auditoría institucional/plataforma y bitácora de accesos; filtros, detalle antes/después, paginación y CSV del conjunto filtrado; historiales protegidos frente al CRUD genérico. |
| NOT-02, NOT-04 | Implementadas | Bandeja administrativa propia, filtros, paginación y lectura idempotente; no permite marcar avisos ajenos. |
| NOT-03 | Implementada | Cola por institución con reintentos; entrega opcional desactivada, reclamación persistente y estados previos/enviados/inciertos. Reintento administrativo solo antes del envío; una entrega incierta no se reenvía automáticamente. |
| SEC-06 | Regla de acceso implementada | Calificaciones/puntajes con permiso explícito de ADMIN; el asistente no los recibe. Otras reglas docentes siguen pendientes. |
| SAAS-04 | Implementada en código | Suspensión previa, archivo con motivo/fecha, bloqueo en modo único y subdominio, sin borrado ni reactivación directa. Exportación JSON/ZIP exclusiva de superadministración, aislada por institución y auditada; archivos conservan cifrado y requieren clave custodiada aparte. No es un volcado SQL ni un restaurador automático. |
| SEG-07 | Implementada | Cuentas de plataforma separadas: alta, activación, desactivación y restablecimiento con motivo; protección de la última cuenta activa y de la propia sesión. |
| SEC-02 | Implementada para operaciones aprobadas | Auditoría de alta/corrección/anulación de vínculos, adendas y datos de puesto, con historial consultable; no se inventan cascadas de anulación. |
| SEC-10, SEC-11 | Implementadas según directiva | Sección 12 opcional con folios reales y privacidad; sección 13 con referencias al original y transferencia manual después de más de tres años de pasivo, con inventario PDF y constancia de microforma para digital/mixto. No genera microformas ni efectúa entregas físicas. |
| REP-01–06 | Implementadas | Ruta y catálogo de carátula (09), incorporación de documentos (06), matriz (08) y referencia pasiva (07); filtros, paginación y PDF/Excel de todo el conjunto autorizado. Firma manual del responsable. |
| CFG-04 | Implementada | Parámetros efectivos de avisos, tamaño PDF y numeración; validación, versión, motivo e historial; conserva números existentes. |
| SAAS-01–03, SAAS-05 | Implementadas para alcance confirmado | Contrato/contacto separados de identidad visual, versiones e historial; cobertura efectiva y totales PEN por registro sin anulados; asignación de ADMIN a persona existente; consumo agregado y disponibilidad a demanda. El respaldo se informa sin verificación automática. |
| OPS-02 | Configurada | CI backend/frontend y PostgreSQL aislado; primera ejecución remota y protección de rama pendientes. |
| OPS-05 | Pendiente | El usuario autorizó Poppler y rechazó añadir Playwright. Se documentan recorridos manuales; no se instaló Playwright. |
| OPS-08 | Actualizada | README, estado y producción con instalación, claves, conversión histórica y casos manuales. |

**Decisiones confirmadas:** fedateo independiente con PDF de sustento obligatorio; calificaciones solo para administradores institucionales; baja con archivo sin borrado y exportación JSON/ZIP sin nuevas dependencias; estados y fechas explícitas de requerimientos; matriz fija del asistente y recepción propia; fiscalización y custodia con fechas explícitas; ClamAV y cifrado; vigencia efectiva incluida; pase a pasivo tras el último vínculo; avisos internos administrativos sin duplicación diaria y correo desactivado; completitud por recepción y aceptación/verificación; un folio por página del PDF; importes de periodos en PEN, por fecha de registro y excluyendo anulados.

**Pendientes de aceptación u operación:** recorridos manuales con ORH; automatización de navegador no autorizada (OPS-05); primera CI remota y protección de rama; protección de PDF históricos después del respaldo; comprobación externa de copias/restauración y supervisión. Las decisiones adicionales de vacaciones, ratificación/progresión, calendario de feriados, retención y otros criterios institucionales sin regla documentada permanecen abiertas. No se declaran resueltas por estos cambios.

**Validación local (18/10/2026):** backend completo: **710 pruebas correctas, 1 omitida, 8297 aserciones**. La omitida es la integración opcional con ClamAV, ejecutada por separado: **1 correcta**. Frontend: **109 pruebas correctas**, `pnpm lint` y `pnpm build` correctos. La compilación advierte paquetes mayores de 500 kB; queda medición de rendimiento con el volumen acordado. PostgreSQL aislado: **72 pruebas correctas, 716 aserciones**, con `phpunit.pgsql.xml`. Los servicios backend/frontend responden por HTTP; scheduler y worker están iniciados y `avisos.correo_habilitado` es `false`. Estas pruebas no sustituyen la aceptación en navegador con ORH ni una restauración real de respaldos.

**Instalación:** se autorizaron Poppler y ClamAV; se modificaron únicamente ejemplos de variables, sin leer ni cambiar el `.env` real. Las tres migraciones iniciales de operaciones, avisos y limpieza se aplicaron en la base principal y las instituciones uno/dos/uniq. Las migraciones de sustento, archivo, parámetros, sección 12/referencias, contratos y entrega de correo se aplicaron posteriormente; la última ejecución dejó la plataforma y las tres instituciones al día. `documentos:clave` genera una clave privada ignorada por Git y no imprime ni sobrescribe su valor. No ejecutar `migrate:fresh` en la instalación.

## 1. Qué cambió en esta revisión

Se revisaron cambios de Git desde la base anterior, navegación/rutas, pantallas, controladores y pendientes específicos. Esta actualización organiza **55 tareas concretas** y **18 decisiones funcionales separadas**. Cada tarea conserva un ID anterior cuando corresponde y añade evidencia actual, trabajo pendiente, aceptación y dependencias.

No se consideran faltantes un módulo que ya funciona, una prueba que no se ejecutó durante esta revisión ni una mejora hipotética. Las tareas de funcionalidades documentadas como futuras se identifican como **planificadas sin aprobar**: su ausencia no constituye por sí sola un defecto del alcance actual. La lista es un inventario estático de lo revisado, no una certificación de todos los flujos ni de producción. No se ejecutaron pruebas ni se modificó código para elaborar este archivo.

### Resuelto en la última actualización: no volver a implementar

| Cambio | Evidencia | Tratamiento |
| --- | --- | --- |
| Restablecer contraseña invalida sesiones anteriores | `070df6a`, AutenticarSesion, sesión institucional/plataforma y SesionRestablecimientoTest | Resuelto según código y pruebas incorporadas; no se ejecutaron aquí. La recuperación voluntaria o el cambio de contraseña desde una nueva pantalla son alcances diferentes. |
| PHPUnit no usa el almacenamiento real | `f3e77a4`, backend/tests/bootstrap.php y phpunit.xml | Storage temporal por proceso y limpieza de su propia carpeta. No se mantiene una tarea para volver a aislarlo. |
| Avisos de suspensión reflejan el comportamiento real | `a6a130d`, estado.js y detalle de institución | Mensajes corregidos; no tratar como defecto pendiente sin un caso nuevo. |
| Retirada de respaldos .env y resultados Playwright | `95e3a75`, .gitignore | Resuelto en los archivos actuales. Esto no acredita rotación de secretos históricos ni ejecución de E2E. |
| Vencimientos integrado y ubicado debajo de Requerimientos | navigation.js, App.jsx, ConsultaVencimientos y VencimientosPanelTest | Ya disponible; únicamente quedan ampliaciones concretas indicadas abajo. |
| ADMIN configura funcionalidades; primer administrador y aprovisionamiento | config/permisos.php, rutas y servicios de plataforma | Ya implementados. No crear tareas duplicadas de login, primer administrador o plataforma multiinstitución. |
| Ítems sin pantalla marcados como Próximo | layouts/NavItem.jsx | Indicador existente. La tarea pendiente es construir cada función aprobada, no volver a añadir el indicador. |

## 2. Inventario de funcionalidad actual

| Área | Estado de la versión revisada | Pendiente real o decisión |
| --- | --- | --- |
| Inicio, Personal y Legajos | Rutas y pantallas presentes; alta, importación, vínculos y expediente | Unificación de vigencia y reingreso; otras mejoras solo si se acuerdan. |
| Secciones 01–11 | Paneles de datos/documentos presentes | Reglas aún abiertas, revisión integral, historial y archivo. No reconstruir los paneles. |
| Secciones 12–13 | Sin panel propio en legajoSections.js | Definir alcance y construir las vistas aprobadas. |
| Requerimientos | Consulta y filtros, seguimiento y enlace a ficha | Falta gestión operativa y validaciones/transiciones específicas. |
| Vencimientos | Listado agrupado por persona con filtros y criterios confirmados | Acceso a más de cinco motivos; automatización de avisos depende de NOT. |
| Reportes | Ítem sin to/ruta en navegación | Módulo y formatos priorizados. Hay exportaciones existentes que deben reutilizarse. |
| Fiscalización | Ítem sin to/ruta; base de datos/API genérica | Expediente de caso y proceso completo. |
| Accesos y custodia | Ítem sin to/ruta; solicitudes/bitácora en el modelo | Solicitud, autorización y préstamo/devolución operativos. |
| Mi perfil | Ítem deshabilitado en AccountMenu | Vista propia y acceso normal a cambio de contraseña. |
| Evaluaciones | Panel y sección 07 presentes | Decisiones sobre alcance, calificaciones, ciclos y avisos; no se etiqueta el módulo entero como inexistente. |
| Configuración | Identidad y funcionalidades presentes | Parámetros funcionales y decisiones de gobierno. |
| Plataforma SaaS | Instituciones, aprovisionamiento, administradores, periodos y auditoría parcial | Tareas específicas de cuentas, personas existentes y evolución documentada. |
| Operación y calidad | Scripts de producción/respaldos y pruebas existentes | CI y E2E versionados; condiciones del despliegue real requieren verificación separada. |

## 3. Cómo leer y priorizar

- **P0:** capacidad de protección de archivos pendiente antes del uso real, según riesgo y política aprobados; no significa que exista una incidencia de seguridad demostrada.
- **P1:** completar operación actual y corregir defectos observables.
- **P2:** módulos/funciones pendientes y mejoras de uso con alcance definido.
- **S/M/L:** complejidad relativa; no son días ni un compromiso de entrega. Dividir L al planificar.
- **Abierta:** falta observada y trabajo concreto. **Bloqueada por decisión:** aprobar regla antes de implementar. **Planificada sin aprobar:** función ausente de la hoja de ruta, todavía fuera de un compromiso cerrado.

Los responsables propuestos son **ORH** para reglas/aceptación, **Producto** para alcance/prioridad, **Desarrollo** para implementación, **QA** para aceptación y **Operaciones** para infraestructura. Debe asignarse una persona al seleccionar cada tarea; no se inventan responsables ni fechas.

| Prioridad | Tareas de implementación/corrección |
| --- | ---: |
| P0 | 2 |
| P1 | 25 |
| P2 | 28 |
| **Total** | **55** |

Las 18 decisiones de la sección 5 no se cuentan como funcionalidades implementadas ni como tareas de programación listas para comenzar. Las dependencias OPS-01 y OPS-03 remiten a verificaciones de operación de la sección 6, no a módulos inexistentes.

## 4. Tareas por módulo

### SEG. Seguridad, identidad y permisos

#### SEG-02 · Gestionar accesos del personal de apoyo desde la institución

- **Prioridad / tamaño / estado:** P1 / L / Abierta.
- **Estado actual y falta:** El panel administra instituciones y sus administradores, pero las rutas de plataforma no ofrecen cambio propio de contraseña ni gestión de cuentas de superadministración; no hay pantalla institucional de roles de apoyo.
- **Trabajo y aceptación:** Una pantalla dedicada concede/revoca roles autorizados con vigencia, motivo y auditoría; un asistente no puede escalar privilegios ni modificar otras instituciones.
- **Dependencias:** GOB-01.
- **Evidencia:** [backend/routes/plataforma.php](../backend/routes/plataforma.php).

#### SEG-04 · Cambiar la contraseña propia del superadministrador

- **Prioridad / tamaño / estado:** P1 / L / Abierta.
- **Estado actual y falta:** El panel administra instituciones y sus administradores, pero las rutas de plataforma no ofrecen cambio propio de contraseña ni gestión de cuentas de superadministración; no hay pantalla institucional de roles de apoyo.
- **Trabajo y aceptación:** Existe una operación de plataforma y su pantalla con contraseña actual, validación, límites de intentos e invalidación de sesiones según la política vigente.
- **Dependencias:** GOB-01.
- **Evidencia:** [backend/routes/plataforma.php](../backend/routes/plataforma.php).

#### SEG-07 · Gestionar cuentas de superadministración desde el panel

- **Prioridad / tamaño / estado:** P2 / L / Abierta.
- **Estado actual y falta:** El panel administra instituciones y sus administradores, pero las rutas de plataforma no ofrecen cambio propio de contraseña ni gestión de cuentas de superadministración; no hay pantalla institucional de roles de apoyo.
- **Trabajo y aceptación:** La interfaz permite las operaciones aprobadas sobre cuentas de plataforma sin conceder acceso a expedientes ni dejar la plataforma sin una cuenta operativa.
- **Dependencias:** GOB-01, SEG-04.
- **Evidencia:** [backend/routes/plataforma.php](../backend/routes/plataforma.php).

### PER. Personal, registro e importación

#### PER-01 · Resolver reingreso y nuevos vínculos para personas existentes

- **Prioridad / tamaño / estado:** P1 / L / Bloqueada por decisión.
- **Estado actual y falta:** La definición de vínculo vigente usa ausencia de cese; el código conserva un TODO para unificarla con las fechas y adendas. El alta con vínculo también señala el reingreso como pendiente.
- **Trabajo y aceptación:** Una persona conserva identidad y documentos; el alta de otro vínculo no duplica usuario ni abre dos legajos activos; el flujo exige una decisión explícita ante coincidencias.
- **Dependencias:** GOB-04.
- **Evidencia:** [backend/app/Support/SituacionLaboral.php](../backend/app/Support/SituacionLaboral.php).

#### PER-02 · Unificar vigencia laboral entre listados, sección 02 y alertas

- **Prioridad / tamaño / estado:** P1 / M / Bloqueada por decisión.
- **Estado actual y falta:** La definición de vínculo vigente usa ausencia de cese; el código conserva un TODO para unificarla con las fechas y adendas. El alta con vínculo también señala el reingreso como pendiente.
- **Trabajo y aceptación:** Se acuerda cómo tratar fin previsto, adenda y ausencia de cese; los mismos casos producen resultados coherentes en Personal, Inicio, Legajos y Vencimientos.
- **Dependencias:** GOB-03.
- **Evidencia:** [backend/app/Support/SituacionLaboral.php](../backend/app/Support/SituacionLaboral.php).

### DOC. Documentos, recepción, revisión y folios

#### DOC-01 · Unificar el flujo de revisión documental entre secciones

- **Prioridad / tamaño / estado:** P1 / L / Bloqueada por decisión.
- **Estado actual y falta:** La carga sigue guardando cifrado=false y estado_antivirus=pendiente; hay recepción y revisión parcial por sección, sin un proceso completo de fiscalización común.
- **Trabajo y aceptación:** Se definen y prueban transiciones, responsables, observaciones y subsanaciones; se conservan versiones y no se permite una transición inválida por URL/API.
- **Dependencias:** GOB-01, GOB-02.
- **Evidencia:** [backend/app/Support/Documentos/CargaDocumento.php](../backend/app/Support/Documentos/CargaDocumento.php).

#### DOC-02 · Integrar análisis antivirus y cuarentena de archivos

- **Prioridad / tamaño / estado:** P0 / L / Abierta.
- **Estado actual y falta:** CargaDocumento registra estado_antivirus=pendiente y declara que no tiene antivirus integrado.
- **Trabajo y aceptación:** La política acordada bloquea descarga/uso de archivos no admitidos, distingue infección de indisponibilidad del servicio y conserva trazabilidad; se prueban archivos limpios y rechazos.
- **Dependencias:** —.
- **Evidencia:** [backend/app/Support/Documentos/CargaDocumento.php](../backend/app/Support/Documentos/CargaDocumento.php).

#### DOC-03 · Proteger los PDF almacenados según política aprobada

- **Prioridad / tamaño / estado:** P0 / L / Bloqueada por decisión.
- **Estado actual y falta:** CargaDocumento registra cifrado=false y señala explícitamente el cifrado en reposo como pendiente.
- **Trabajo y aceptación:** Se implementa la protección aprobada y el indicador refleja su alcance real; se distingue cifrado de aplicación de cifrado de infraestructura y se conserva lectura/recuperación controlada de archivos existentes.
- **Dependencias:** AUD-03.
- **Evidencia:** [backend/app/Support/Documentos/CargaDocumento.php](../backend/app/Support/Documentos/CargaDocumento.php).

#### DOC-04 · Hacer fiable el conteo de páginas y la asignación de folios

- **Prioridad / tamaño / estado:** P1 / L / Bloqueada por decisión.
- **Estado actual y falta:** ArchivoPdf::paginas cuenta páginas con expresiones regulares y descompresión; no hay un analizador PDF fiable integrado para todos los documentos.
- **Trabajo y aceptación:** Se acuerda página versus hoja física; PDF de prueba con compresión, rotación y límites reciben conteo correcto o revisión explícita; no hay folios duplicados bajo concurrencia.
- **Dependencias:** GOB-02.
- **Evidencia:** [backend/app/Support/Documentos/ArchivoPdf.php](../backend/app/Support/Documentos/ArchivoPdf.php).

#### DOC-05 · Crear bandeja de documentos pendientes de recepción/revisión

- **Prioridad / tamaño / estado:** P2 / L / Abierta.
- **Estado actual y falta:** La carga sigue guardando cifrado=false y estado_antivirus=pendiente; hay recepción y revisión parcial por sección, sin un proceso completo de fiscalización común.
- **Trabajo y aceptación:** Permite filtrar por persona, sección, unidad y responsable; cada acción conserva permisos y control de concurrencia del servicio común.
- **Dependencias:** DOC-01.
- **Evidencia:** [backend/app/Support/Documentos/CargaDocumento.php](../backend/app/Support/Documentos/CargaDocumento.php).

### SEC. Secciones del legajo 01–11 y archivo

#### SEC-02 · Implementar la trazabilidad acordada de cambios de puesto y vínculo

- **Prioridad / tamaño / estado:** P1 / L / Bloqueada por decisión.
- **Estado actual y falta:** El menú del legajo contiene 01–11. Las secciones 12–13 no tienen una vista propia equivalente; reglas de historial, cese y archivo siguen señaladas como pendientes en sus controladores.
- **Trabajo y aceptación:** Después de aprobar la regla, cambios/anulaciones conservan el historial exigido y no dejan adendas, inducciones o períodos de prueba en estados incompatibles.
- **Dependencias:** GOB-04, PER-02.
- **Evidencia:** [backend/app/Http/Controllers/Api/V1/Legajos/Seccion02Controller.php](../backend/app/Http/Controllers/Api/V1/Legajos/Seccion02Controller.php).

#### SEC-09 · Completar la relación entre cese, archivo y reapertura

- **Prioridad / tamaño / estado:** P1 / L / Bloqueada por decisión.
- **Estado actual y falta:** El menú del legajo contiene 01–11. Las secciones 12–13 no tienen una vista propia equivalente; reglas de historial, cese y archivo siguen señaladas como pendientes en sus controladores.
- **Trabajo y aceptación:** El flujo aprobado registra el efecto sobre el legajo, contempla vínculos concurrentes y conserva documentos; no se automatiza el paso a pasivo antes de acordar la regla.
- **Dependencias:** PER-01, PER-02.
- **Evidencia:** [backend/app/Http/Controllers/Api/V1/Legajos/Seccion11Controller.php](../backend/app/Http/Controllers/Api/V1/Legajos/Seccion11Controller.php).

#### SEC-10 · Implementar la sección 12 según alcance aprobado

- **Prioridad / tamaño / estado:** P2 / L / Bloqueada por decisión.
- **Estado actual y falta:** El menú del legajo contiene 01–11. Las secciones 12–13 no tienen una vista propia equivalente; reglas de historial, cese y archivo siguen señaladas como pendientes en sus controladores.
- **Trabajo y aceptación:** Existe interfaz, API y pruebas de los datos/documentos acordados; se conserva numeración, folios y permisos sin inventar información no definida.
- **Dependencias:** GOB-05, DOC-01.
- **Evidencia:** [frontend/src/features/legajos/config/legajoSections.js](../frontend/src/features/legajos/config/legajoSections.js).

#### SEC-11 · Implementar consulta/transferencia de legajos pasivos (13)

- **Prioridad / tamaño / estado:** P2 / L / Bloqueada por decisión.
- **Estado actual y falta:** El menú del legajo contiene 01–11. Las secciones 12–13 no tienen una vista propia equivalente; reglas de historial, cese y archivo siguen señaladas como pendientes en sus controladores.
- **Trabajo y aceptación:** Se consultan referencias, ubicación y transferencias acordadas; se preserva la lectura de expedientes cerrados y el historial de custodia/folios.
- **Dependencias:** GOB-05, SEC-09.
- **Evidencia:** [frontend/src/features/legajos/config/legajoSections.js](../frontend/src/features/legajos/config/legajoSections.js).

### REQ. Requerimientos documentales

#### REQ-01 · Corregir «Ver ficha» desde Requerimientos

- **Prioridad / tamaño / estado:** P1 / S / Abierta.
- **Estado actual y falta:** El enlace envía categoria=requerimientos-documentos, que no forma parte del menú de categorías del legajo.
- **Trabajo y aceptación:** El enlace abre una categoría válida del legajo de esa persona y permite volver al listado sin terminar en «La categoría solicitada no existe».
- **Dependencias:** —.
- **Evidencia:** [frontend/src/features/requirements/RequirementsPage.jsx](../frontend/src/features/requirements/RequirementsPage.jsx).

#### REQ-02 · Definir y aplicar estados y transiciones de requerimientos

- **Prioridad / tamaño / estado:** P1 / M / Abierta.
- **Estado actual y falta:** estado acepta cualquier texto de hasta 15 caracteres; no hay un servicio de transiciones del requerimiento.
- **Trabajo y aceptación:** Se acuerdan abierto, atención, prórroga, cumplimiento y cierre; estado, documento y cerrado_en son consistentes; se impiden saltos inválidos.
- **Dependencias:** GOB-01, GOB-03.
- **Evidencia:** [backend/app/Http/Controllers/Api/V1/RequerimientoDocumentoController.php](../backend/app/Http/Controllers/Api/V1/RequerimientoDocumentoController.php).

#### REQ-03 · Crear y editar solicitudes desde una pantalla dedicada

- **Prioridad / tamaño / estado:** P1 / L / Abierta.
- **Estado actual y falta:** La pantalla solo consulta, filtra y enlaza la ficha. No tiene acciones para crear, responder, revisar, prorrogar o cerrar. Su API mantiene validaciones básicas y texto libre para el estado.
- **Trabajo y aceptación:** Administración registra destinatario, documento, descripción y plazo con autor real y auditoría; no depende de abrir la escritura del CRUD genérico en producción.
- **Dependencias:** REQ-02.
- **Evidencia:** [frontend/src/features/requirements/RequirementsPage.jsx](../frontend/src/features/requirements/RequirementsPage.jsx).

#### REQ-04 · Vincular la respuesta documental de un requerimiento

- **Prioridad / tamaño / estado:** P1 / L / Abierta.
- **Estado actual y falta:** documento_id se valida por existencia; no hay una acción dedicada que valide la correspondencia con el destinatario y el tipo del pedido.
- **Trabajo y aceptación:** La pantalla permite seleccionar/subir evidencia y valida que corresponda a la persona, legajo y tipo permitido; un documento ajeno es rechazado por API.
- **Dependencias:** REQ-03.
- **Evidencia:** [frontend/src/features/requirements/RequirementsPage.jsx](../frontend/src/features/requirements/RequirementsPage.jsx).

#### REQ-05 · Conceder y consultar prórrogas

- **Prioridad / tamaño / estado:** P1 / M / Abierta.
- **Estado actual y falta:** La prórroga se muestra en el seguimiento, mientras la columna principal conserva la fecha límite original; no hay una acción propia de ampliación.
- **Trabajo y aceptación:** La ampliación exige permiso y motivo, conserva su historial y presenta la fecha efectiva en el listado y en Vencimientos.
- **Dependencias:** REQ-02, REQ-10.
- **Evidencia:** [frontend/src/features/requirements/RequirementsPage.jsx](../frontend/src/features/requirements/RequirementsPage.jsx).

#### REQ-06 · Recordatorios de solicitudes con trazabilidad

- **Prioridad / tamaño / estado:** P2 / M / Abierta.
- **Estado actual y falta:** La pantalla solo consulta, filtra y enlaza la ficha. No tiene acciones para crear, responder, revisar, prorrogar o cerrar. Su API mantiene validaciones básicas y texto libre para el estado.
- **Trabajo y aceptación:** Cada aviso tiene destinatario autorizado, fecha, resultado y protección contra duplicados; el modo de prueba no se presenta como entrega real.
- **Dependencias:** REQ-09, REQ-05, NOT-01, NOT-03.
- **Evidencia:** [frontend/src/features/requirements/RequirementsPage.jsx](../frontend/src/features/requirements/RequirementsPage.jsx).

#### REQ-07 · Corregir búsqueda por nombre completo

- **Prioridad / tamaño / estado:** P1 / S / Abierta.
- **Estado actual y falta:** La API busca cada campo de nombres/apellidos por separado; un término con nombre completo puede no coincidir.
- **Trabajo y aceptación:** Nombres y apellidos juntos encuentran la persona; el enlace de búsqueda enviado desde Vencimientos devuelve sus solicitudes.
- **Dependencias:** —.
- **Evidencia:** [backend/app/Http/Controllers/Api/V1/RequerimientoDocumentoController.php](../backend/app/Http/Controllers/Api/V1/RequerimientoDocumentoController.php).

#### REQ-08 · Revisar y observar la respuesta al requerimiento

- **Prioridad / tamaño / estado:** P1 / M / Abierta.
- **Estado actual y falta:** La pantalla solo consulta, filtra y enlaza la ficha. No tiene acciones para crear, responder, revisar, prorrogar o cerrar. Su API mantiene validaciones básicas y texto libre para el estado.
- **Trabajo y aceptación:** Un responsable autorizado acepta u observa la evidencia con motivo; la carga por sí sola no constituye revisión ni cumplimiento.
- **Dependencias:** REQ-04, DOC-01.
- **Evidencia:** [frontend/src/features/requirements/RequirementsPage.jsx](../frontend/src/features/requirements/RequirementsPage.jsx).

#### REQ-09 · Cerrar el requerimiento desde el panel

- **Prioridad / tamaño / estado:** P1 / M / Abierta.
- **Estado actual y falta:** La pantalla solo consulta, filtra y enlaza la ficha. No tiene acciones para crear, responder, revisar, prorrogar o cerrar. Su API mantiene validaciones básicas y texto libre para el estado.
- **Trabajo y aceptación:** El cierre verifica el estado y la revisión requerida, registra actor/fecha y retira la alerta de atraso; se rechazan cierres incompatibles o peticiones atrasadas.
- **Dependencias:** REQ-02, REQ-08.
- **Evidencia:** [frontend/src/features/requirements/RequirementsPage.jsx](../frontend/src/features/requirements/RequirementsPage.jsx).

#### REQ-10 · Validar plazos, fechas y coherencia de los requerimientos

- **Prioridad / tamaño / estado:** P1 / M / Abierta.
- **Estado actual y falta:** plazo_dias_habiles permite números negativos; las fechas solo se validan como fecha y no se comprueba su relación.
- **Trabajo y aceptación:** Se rechazan números negativos, fechas límite anteriores a la solicitud y prórrogas inconsistentes; se usa el calendario aprobado cuando corresponde calcular días hábiles.
- **Dependencias:** GOB-03.
- **Evidencia:** [backend/app/Http/Controllers/Api/V1/RequerimientoDocumentoController.php](../backend/app/Http/Controllers/Api/V1/RequerimientoDocumentoController.php).

### VEN. Vencimientos y seguimiento de actualización

#### VEN-04 · Permitir consultar todos los motivos de una persona

- **Prioridad / tamaño / estado:** P2 / M / Abierta.
- **Estado actual y falta:** El panel ya funciona; la respuesta limita a cinco motivos por persona. No existe una acción específica para paginar todos los motivos dentro del panel.
- **Trabajo y aceptación:** Desde el límite actual de cinco motivos se accede a los restantes con paginación y los mismos permisos; totales y detalle coinciden.
- **Dependencias:** —.
- **Evidencia:** [backend/app/Support/ConsultaVencimientos.php](../backend/app/Support/ConsultaVencimientos.php).

### FIS. Fiscalización y fedateo

#### FIS-02 · Construir bandeja y expediente de fiscalización

- **Prioridad / tamaño / estado:** P2 / L / Bloqueada por decisión.
- **Estado actual y falta:** Fiscalización aparece sin destino de navegación. Existen tablas/controladores genéricos, pero no una pantalla y flujo completos de gestión de casos.
- **Trabajo y aceptación:** Se inicia un caso, registra comunicaciones, evidencia y resultado con historial; no se confunde una recepción con una fiscalización conforme.
- **Dependencias:** FIS-01, DOC-01.
- **Evidencia:** [frontend/src/config/navigation.js](../frontend/src/config/navigation.js).

#### FIS-03 · Implementar controles de plazo y cierre de fiscalización

- **Prioridad / tamaño / estado:** P2 / L / Bloqueada por decisión.
- **Estado actual y falta:** Fiscalización aparece sin destino de navegación. Existen tablas/controladores genéricos, pero no una pantalla y flujo completos de gestión de casos.
- **Trabajo y aceptación:** Los avisos y vencimientos usan el calendario aprobado; cierre/reapertura y resultados requieren permisos, motivo y auditoría.
- **Dependencias:** FIS-02, NOT-01.
- **Evidencia:** [frontend/src/config/navigation.js](../frontend/src/config/navigation.js).

#### FIS-04 · Definir y registrar fedateo con sustento

- **Prioridad / tamaño / estado:** P2 / M / Bloqueada por decisión.
- **Estado actual y falta:** Fiscalización aparece sin destino de navegación. Existen tablas/controladores genéricos, pero no una pantalla y flujo completos de gestión de casos.
- **Trabajo y aceptación:** Se acuerda si basta una marca o exige evidencia; el registro identifica fedatario/fecha y evita que un rol no autorizado lo conceda.
- **Dependencias:** GOB-01, DOC-01.
- **Evidencia:** [frontend/src/config/navigation.js](../frontend/src/config/navigation.js).

#### FIS-05 · Registrar comunicaciones y evidencias de fiscalización

- **Prioridad / tamaño / estado:** P2 / M / Abierta.
- **Estado actual y falta:** Fiscalización aparece sin destino de navegación. Existen tablas/controladores genéricos, pero no una pantalla y flujo completos de gestión de casos.
- **Trabajo y aceptación:** Cada caso conserva oficios, destinatario, fecha y documentos de sustento dentro de su institución, con autor e historial.
- **Dependencias:** FIS-02.
- **Evidencia:** [frontend/src/config/navigation.js](../frontend/src/config/navigation.js).

#### FIS-06 · Registrar el resultado de la fiscalización

- **Prioridad / tamaño / estado:** P2 / M / Abierta.
- **Estado actual y falta:** Fiscalización aparece sin destino de navegación. Existen tablas/controladores genéricos, pero no una pantalla y flujo completos de gestión de casos.
- **Trabajo y aceptación:** Conformidad, observación o resultado adverso requieren evidencia y permiso; el efecto sobre el documento sigue las transiciones aprobadas y queda auditado.
- **Dependencias:** FIS-05, DOC-01.
- **Evidencia:** [frontend/src/config/navigation.js](../frontend/src/config/navigation.js).

### CUS. Accesos, préstamos y custodia

#### CUS-02 · Implementar solicitud, aprobación y revocación de acceso

- **Prioridad / tamaño / estado:** P2 / L / Bloqueada por decisión.
- **Estado actual y falta:** Accesos y custodia aparece sin destino de navegación. El modelo de solicitudes y bitácora no sustituye un circuito de solicitud/aprobación/préstamo/devolución.
- **Trabajo y aceptación:** Cada permiso identifica expediente/alcance, autorizador y fin; una revocación o vencimiento bloquea nuevas consultas y queda registrado.
- **Dependencias:** CUS-01.
- **Evidencia:** [frontend/src/config/navigation.js](../frontend/src/config/navigation.js).

#### CUS-03 · Gestionar préstamo, devolución y alertas de custodia

- **Prioridad / tamaño / estado:** P2 / L / Bloqueada por decisión.
- **Estado actual y falta:** Accesos y custodia aparece sin destino de navegación. El modelo de solicitudes y bitácora no sustituye un circuito de solicitud/aprobación/préstamo/devolución.
- **Trabajo y aceptación:** Se registra entrega, responsable, ubicación y devolución; se evitan préstamos simultáneos incompatibles y se detectan devoluciones pendientes.
- **Dependencias:** CUS-01, NOT-01.
- **Evidencia:** [frontend/src/config/navigation.js](../frontend/src/config/navigation.js).

#### CUS-04 · Exponer auditoría de accesos al responsable autorizado

- **Prioridad / tamaño / estado:** P2 / M / Bloqueada por decisión.
- **Estado actual y falta:** Accesos y custodia aparece sin destino de navegación. El modelo de solicitudes y bitácora no sustituye un circuito de solicitud/aprobación/préstamo/devolución.
- **Trabajo y aceptación:** Permite filtrar quién vio/descargó qué y cuándo, y exportar el alcance permitido; el superadmin no obtiene acceso al contenido por esta funcionalidad.
- **Dependencias:** CUS-01, AUD-01.
- **Evidencia:** [frontend/src/config/navigation.js](../frontend/src/config/navigation.js).

### REP. Reportes y formatos

#### REP-01 · Definir catálogo de reportes y habilitar una ruta real

- **Prioridad / tamaño / estado:** P2 / M / Bloqueada por decisión.
- **Estado actual y falta:** Reportes aparece sin destino. La exportación de personal y la ficha personal PDF existentes no equivalen al módulo global ni a todos los formatos pendientes.
- **Trabajo y aceptación:** La universidad prioriza reportes/campos/filtros; el ítem Reportes deja de ser un marcador sin pantalla y diferencia formatos existentes de reportes nuevos.
- **Dependencias:** GOB-01.
- **Evidencia:** [frontend/src/config/navigation.js](../frontend/src/config/navigation.js).

#### REP-02 · Generar la carátula institucional pendiente

- **Prioridad / tamaño / estado:** P2 / L / Planificada sin aprobar.
- **Estado actual y falta:** Reportes aparece sin destino. La exportación de personal y la ficha personal PDF existentes no equivalen al módulo global ni a todos los formatos pendientes.
- **Trabajo y aceptación:** El formato acordado se genera con datos y numeración reales, sin confundirlo con la ficha personal PDF ya existente.
- **Dependencias:** REP-01.
- **Evidencia:** [frontend/src/config/navigation.js](../frontend/src/config/navigation.js).

#### REP-03 · Exportar reportes con filtros y permisos

- **Prioridad / tamaño / estado:** P2 / M / Abierta.
- **Estado actual y falta:** Reportes aparece sin destino. La exportación de personal y la ficha personal PDF existentes no equivalen al módulo global ni a todos los formatos pendientes.
- **Trabajo y aceptación:** PDF/Excel respeta el conjunto filtrado completo, tipos de dato, caracteres y permisos sensibles; exportar no permite consultar más que la pantalla.
- **Dependencias:** REP-01.
- **Evidencia:** [frontend/src/config/navigation.js](../frontend/src/config/navigation.js).

#### REP-04 · Generar el control institucional de incorporaciones

- **Prioridad / tamaño / estado:** P2 / M / Planificada sin aprobar.
- **Estado actual y falta:** Reportes aparece sin destino. La exportación de personal y la ficha personal PDF existentes no equivalen al módulo global ni a todos los formatos pendientes.
- **Trabajo y aceptación:** Se aprueba el modelo y se genera el control con filtros y datos reales de incorporación de DOCUMENTOS al legajo (Anexo 06: motivo, documento, sección, folios y fecha); no es un registro de incorporación laboral.
- **Dependencias:** REP-01.
- **Evidencia:** [frontend/src/config/navigation.js](../frontend/src/config/navigation.js).

#### REP-05 · Generar la matriz institucional de legajos

- **Prioridad / tamaño / estado:** P2 / M / Planificada sin aprobar.
- **Estado actual y falta:** Reportes aparece sin destino. La exportación de personal y la ficha personal PDF existentes no equivalen al módulo global ni a todos los formatos pendientes.
- **Trabajo y aceptación:** El formato aprobado presenta el conjunto filtrado completo, con fechas/campos consistentes y sin datos sensibles para roles no autorizados.
- **Dependencias:** REP-01.
- **Evidencia:** [frontend/src/config/navigation.js](../frontend/src/config/navigation.js).

#### REP-06 · Generar la referencia de legajo pasivo

- **Prioridad / tamaño / estado:** P2 / M / Planificada sin aprobar.
- **Estado actual y falta:** Reportes aparece sin destino. La exportación de personal y la ficha personal PDF existentes no equivalen al módulo global ni a todos los formatos pendientes.
- **Trabajo y aceptación:** El formato acordado identifica expediente, ubicación y referencia/transferencia; los folios siguen la decisión de archivo.
- **Dependencias:** REP-01, SEC-11.
- **Evidencia:** [frontend/src/config/navigation.js](../frontend/src/config/navigation.js).

### CFG. Configuración y catálogos

#### CFG-04 · Centralizar parámetros funcionales aprobados

- **Prioridad / tamaño / estado:** P2 / M / Bloqueada por decisión.
- **Estado actual y falta:** Las pestañas son identidad institucional y funcionalidades. No hay una pantalla general para los parámetros funcionales de legajo actualmente definidos en configuración/parametros_sistema.
- **Trabajo y aceptación:** Días de aviso, tamaño de archivos y formato de legajo tienen límites, motivo, autor y valor efectivo visible; no requieren editar .env para cada ajuste de negocio.
- **Dependencias:** GOB-02, GOB-03.
- **Evidencia:** [frontend/src/features/settings/SettingsPage.jsx](../frontend/src/features/settings/SettingsPage.jsx).

### NOT. Notificaciones y tareas programadas

#### NOT-02 · Crear la bandeja de notificaciones del usuario

- **Prioridad / tamaño / estado:** P2 / L / Bloqueada por decisión.
- **Estado actual y falta:** No hay programación de avisos operativos en las rutas de consola revisadas; existen tabla/API de notificaciones, sin bandeja propia ni motor de eventos/entrega.
- **Trabajo y aceptación:** Existe una pantalla con paginación y enlaces autorizados; cada persona solo ve sus notificaciones y los datos sensibles respetan permisos.
- **Dependencias:** NOT-01.
- **Evidencia:** [backend/routes/console.php](../backend/routes/console.php).

#### NOT-03 · Procesar avisos por institución con reintentos seguros

- **Prioridad / tamaño / estado:** P1 / L / Bloqueada por decisión.
- **Estado actual y falta:** No hay programación de avisos operativos en las rutas de consola revisadas; existen tabla/API de notificaciones, sin bandeja propia ni motor de eventos/entrega.
- **Trabajo y aceptación:** Scheduler/colas restauran contexto, evitan duplicados y separan error de entrega incierta; las instituciones suspendidas o vencidas siguen la política vigente.
- **Dependencias:** NOT-01, OPS-03.
- **Evidencia:** [backend/routes/console.php](../backend/routes/console.php).

#### NOT-04 · Marcar notificaciones como leídas desde la bandeja

- **Prioridad / tamaño / estado:** P2 / S / Abierta.
- **Estado actual y falta:** No hay programación de avisos operativos en las rutas de consola revisadas; existen tabla/API de notificaciones, sin bandeja propia ni motor de eventos/entrega.
- **Trabajo y aceptación:** Existe acción individual y, si se aprueba, colectiva; la API no permite marcar notificaciones ajenas y conserva fecha de lectura.
- **Dependencias:** NOT-02.
- **Evidencia:** [backend/routes/console.php](../backend/routes/console.php).

### SAAS. Plataforma, instituciones y soporte

#### SAAS-01 · Registrar datos de contacto y contrato del cliente

- **Prioridad / tamaño / estado:** P2 / M / Planificada sin aprobar.
- **Estado actual y falta:** El panel ya registra y aprovisiona instituciones, gestiona administradores y periodos. La hoja de ruta documenta como pendientes contrato, baja/exportación, consumo y mejores resúmenes.
- **Trabajo y aceptación:** Se acuerdan campos y acceso; los datos contractuales se distinguen de la identidad visual editable por la institución y los cambios quedan auditados.
- **Dependencias:** —.
- **Evidencia:** [backend/routes/plataforma.php](../backend/routes/plataforma.php).

#### SAAS-02 · Mejorar resumen de servicio y periodos pagados

- **Prioridad / tamaño / estado:** P2 / M / Planificada sin aprobar.
- **Estado actual y falta:** El panel ya registra y aprovisiona instituciones, gestiona administradores y periodos. La hoja de ruta documenta como pendientes contrato, baja/exportación, consumo y mejores resúmenes.
- **Trabajo y aceptación:** Muestra próximas expiraciones, gracia y vencidas con enlaces; cualquier agregado de montos trata anulaciones y moneda según definición aprobada.
- **Dependencias:** SAAS-01.
- **Evidencia:** [backend/routes/plataforma.php](../backend/routes/plataforma.php).

#### SAAS-03 · Asignar administración a una persona institucional existente

- **Prioridad / tamaño / estado:** P1 / M / Abierta.
- **Estado actual y falta:** Agregar un administrador rechaza un correo ya registrado, aunque corresponda a una persona de esa institución.
- **Trabajo y aceptación:** Se verifica identidad y autorización; se concede el rol sin duplicar usuario ni cambiar documentos; se conserva la regla de no dejar sin administrador activo.
- **Dependencias:** GOB-01.
- **Evidencia:** [backend/app/Support/Plataforma/AdministradoresInstitucion.php](../backend/app/Support/Plataforma/AdministradoresInstitucion.php).

#### SAAS-04 · Dar de baja, exportar y archivar una institución

- **Prioridad / tamaño / estado:** P1 / L / Planificada sin aprobar.
- **Estado actual y falta:** El panel ya registra y aprovisiona instituciones, gestiona administradores y periodos. La hoja de ruta documenta como pendientes contrato, baja/exportación, consumo y mejores resúmenes.
- **Trabajo y aceptación:** Se aprueban autorización y retención; exportación incluye base/archivos verificables y no implica borrado inmediato ni acceso de soporte al contenido.
- **Dependencias:** SAAS-01, OPS-01.
- **Evidencia:** [backend/routes/plataforma.php](../backend/routes/plataforma.php).

#### SAAS-05 · Mostrar consumo y salud por institución

- **Prioridad / tamaño / estado:** P2 / L / Planificada sin aprobar.
- **Estado actual y falta:** El panel ya registra y aprovisiona instituciones, gestiona administradores y periodos. La hoja de ruta documenta como pendientes contrato, baja/exportación, consumo y mejores resúmenes.
- **Trabajo y aceptación:** El panel muestra métricas aprobadas de espacio, procesos y respaldo sin exponer expedientes; se fijan umbrales y acciones operativas.
- **Dependencias:** OPS-03.
- **Evidencia:** [backend/routes/plataforma.php](../backend/routes/plataforma.php).

### AUD. Auditoría, datos y cumplimiento institucional

#### AUD-01 · Crear consulta de auditoría institucional y global autorizada

- **Prioridad / tamaño / estado:** P1 / L / Abierta.
- **Estado actual y falta:** Se registran operaciones y hay información en el detalle/resumen de plataforma, pero falta una consulta dedicada de auditoría con filtros y alcance institucional/global.
- **Trabajo y aceptación:** Se filtra actor/acción/fecha con paginación; cada ámbito muestra solo sus operaciones y una exportación no filtra datos personales fuera de su autorización.
- **Dependencias:** GOB-01.
- **Evidencia:** [backend/app/Support/Plataforma/AuditoriaOperaciones.php](../backend/app/Support/Plataforma/AuditoriaOperaciones.php).

### OPS. Operación, calidad y experiencia transversal

#### OPS-02 · Automatizar controles de calidad en integración continua

- **Prioridad / tamaño / estado:** P1 / M / Abierta.
- **Estado actual y falta:** No se observa .github/workflows ni otra configuración de CI versionada en la línea base revisada; no se afirma que no exista un servicio externo.
- **Trabajo y aceptación:** Cada cambio ejecuta pruebas relevantes backend/frontend, lint y build; las migraciones críticas se prueban en PostgreSQL aislado y los fallos bloquean la integración.
- **Dependencias:** —.
- **Evidencia:** [frontend/package.json](../frontend/package.json).

#### OPS-05 · Crear pruebas de navegador de los recorridos prioritarios

- **Prioridad / tamaño / estado:** P1 / L / Abierta.
- **Estado actual y falta:** Hay pruebas de API y frontend unitario, pero no se encontró una suite E2E de navegador versionada en frontend.
- **Trabajo y aceptación:** Con datos ficticios se prueban login, alta, carga/recepción/subsanación, requerimientos, vencimientos y separación de instituciones; no se presentan pruebas de API como verificación visual.
- **Dependencias:** —.
- **Evidencia:** [backend/tests/Feature/VencimientosPanelTest.php](../backend/tests/Feature/VencimientosPanelTest.php).

#### OPS-08 · Actualizar la documentación de estado y pendientes

- **Prioridad / tamaño / estado:** P1 / S / Abierta.
- **Estado actual y falta:** La última actualización ya aísla el storage de PHPUnit. La documentación histórica y la automatización de pruebas de navegador/CI siguen siendo trabajos separados.
- **Trabajo y aceptación:** README y ESTADO identifican la línea base actual; sesiones, almacenamiento de pruebas y Vencimientos no vuelven a presentarse como tareas inexistentes o sin integrar.
- **Dependencias:** —.
- **Evidencia:** [backend/tests/bootstrap.php](../backend/tests/bootstrap.php).

### UI. Cuenta y navegación

#### UI-01 · Implementar el acceso «Mi perfil»

- **Prioridad / tamaño / estado:** P2 / M / Bloqueada por decisión.
- **Estado actual y falta:** Mi perfil sigue deshabilitado y Entorno local se escribe de forma fija. El formulario de cambio de contraseña se usa en el acceso con contraseña temporal, no hay entrada normal desde el menú de cuenta.
- **Trabajo y aceptación:** El ítem deja de estar deshabilitado y abre una vista propia con los campos aprobados; no permite editar roles ni datos ajenos.
- **Dependencias:** GOB-01.
- **Evidencia:** [frontend/src/layouts/AccountMenu.jsx](../frontend/src/layouts/AccountMenu.jsx).

#### UI-02 · Mostrar correctamente el entorno de ejecución

- **Prioridad / tamaño / estado:** P2 / S / Abierta.
- **Estado actual y falta:** AccountMenu muestra Entorno local en dos lugares mediante texto fijo.
- **Trabajo y aceptación:** El menú de cuenta no muestra «Entorno local» fijo cuando la aplicación se ejecuta en otro entorno; la información proviene de una configuración pública controlada.
- **Dependencias:** —.
- **Evidencia:** [frontend/src/layouts/AccountMenu.jsx](../frontend/src/layouts/AccountMenu.jsx).

#### UI-03 · Permitir cambiar voluntariamente la contraseña institucional

- **Prioridad / tamaño / estado:** P1 / M / Abierta.
- **Estado actual y falta:** Mi perfil sigue deshabilitado y Entorno local se escribe de forma fija. El formulario de cambio de contraseña se usa en el acceso con contraseña temporal, no hay entrada normal desde el menú de cuenta.
- **Trabajo y aceptación:** Una cuenta con sesión normal accede al formulario desde la interfaz; reutiliza PUT /api/v1/auth/password y mantiene validaciones e invalidación de sesiones ya implementadas.
- **Dependencias:** UI-01.
- **Evidencia:** [backend/app/Http/Controllers/Api/V1/AuthController.php](../backend/app/Http/Controllers/Api/V1/AuthController.php).

## 5. Decisiones que bloquean desarrollo (no asumir respuestas)

Se conservan los IDs anteriores. Resolverlas requiere registrar quién decidió, fecha, ejemplos y tareas afectadas. Una nota TODO no basta para declarar que la regla actual sea incorrecta.

| ID | Decisión pendiente | Resultado de aceptación | Fuente |
| --- | --- | --- | --- |
| GOB-01 | Acordar matriz de roles y separación de funciones | ORH aprueba quién carga, recepciona, revisa, fiscaliza, anula, gestiona cuentas y consulta información sensible; se identifican permisos nuevos sin ampliar los actuales por defecto. | `docs/ESTADO_PROYECTO.md` |
| GOB-02 | Definir completitud y actualización del legajo | Se documenta qué documentos cuentan como presentes, recibidos o aprobados, tratamiento de observados y condiciones Sin responder; no se equipara última edición con vigencia. | `docs/ESTADO_PROYECTO.md` |
| GOB-03 | Definir plazos y calendario institucional | Se acuerdan días calendario/hábiles, feriados, zona horaria, prórrogas y reglas por proceso; cada regla incluye ejemplos en límites de fecha. | `docs/ESTADO_PROYECTO.md` |
| GOB-04 | Cerrar decisiones de aplicabilidad por ley, vínculo y familia | Se resuelven contradicciones de catálogos/anexos, documentos por evento, familiares y excepciones; cada decisión referencia los tipos afectados. | `docs/ESTADO_PROYECTO.md` |
| GOB-05 | Precisar alcance de las secciones 12 y 13 | La universidad confirma datos, documentos, folios, responsables y reglas de consulta/archivo; no se añaden secciones al menú sin ese alcance. | `docs/ESTADO_PROYECTO.md` |
| SEC-01 | Cerrar reglas pendientes de información personal/familiar (01) | Se validan documentos por familiar, renovaciones de declaraciones, históricos y discapacidad; las banderas No/Sin responder no eliminan historia. | `docs/ESTADO_PROYECTO.md` |
| SEC-04 | Definir saldos vacacionales y alcance de movimientos (05) | Se decide si hay saldos/rol anual, unidad de días/horas y datos de salud; si se aprueba saldo, los solapamientos y anulaciones no duplican consumo. | `docs/ESTADO_PROYECTO.md` |
| SEC-05 | Conciliar compensaciones y liquidación entre 06 y 11 | Se acuerda dónde se registra y cómo se referencia un mismo sustento, evitando duplicar archivo/registro; ambas vistas preservan permisos e historial. | `docs/ESTADO_PROYECTO.md` |
| SEC-06 | Confirmar acceso a calificaciones y reglas docentes de la sección 07 | Se resuelve quién ve puntajes y cómo se acreditan ratificación/progresión; las respuestas de API no incluyen calificaciones para roles no autorizados. | `backend/config/permisos.php` |
| SEC-07 | Validar reglas de sanciones y relaciones laborales (08–09) | Se acuerdan suspensión, recursos, controversias y estados; se preserva confidencialidad y no se bloquean funciones existentes por reglas antiguas de otras ramas. | `docs/ESTADO_PROYECTO.md` |
| SEC-08 | Completar criterios de vigencia y confidencialidad SST/bienestar (10) | La universidad valida qué registros/documentos vencen y quién puede verlos; se prueban caducidades, titular y familiar, y acceso sin permiso sensible. | `docs/ESTADO_PROYECTO.md` |
| FIS-01 | Definir el proceso institucional de fiscalización | Se acuerdan responsables, muestreo, evidencias, resultados, plazos y tratamiento de falsedad; los plazos documentales previos se validan con la universidad antes de codificarlos. | `docs/ESTADO_PROYECTO.md` |
| CUS-01 | Definir solicitudes de acceso y custodia | Se acuerdan solicitantes, autorizadores, alcance, vigencia, datos sensibles, retiro físico y devolución; acceso digital y préstamo físico quedan diferenciados. | `docs/ESTADO_PROYECTO.md` |
| EVA-01 | Cerrar alcance del panel de Evaluaciones | Se distingue consulta documental de gestionar un proceso de evaluación; se acuerdan filtros, responsables y si existe cálculo de resultados. | `docs/preguntas_cliente_evaluaciones.md` |
| EVA-02 | Validar ciclos, alertas y correcciones de evaluación | Se decide año vigente/anterior, fechas de aviso y bloqueo de registros acreditados; los mismos casos coinciden entre sección 07 y panel global. | `docs/preguntas_cliente_evaluaciones.md` |
| CFG-01 | Validar el gobierno de cambios de configuración | Se confirma quién configura y si requiere sustento además de motivo; se mantiene ADMIN con configuracion.modulos explícito y se audita el alcance del cambio. | `docs/decisiones-configuracion-secciones.md` |
| NOT-01 | Definir eventos, destinatarios y canal de notificación | Se aprueba qué avisar, a quién y con qué contenido; correo y notificación interna no incluyen datos sensibles innecesarios y respetan preferencias/política institucional. | `docs/ESTADO_PROYECTO.md` |
| AUD-03 | Validar reglas para uso de datos reales y soporte | La universidad/proveedor acuerdan alojamiento, acceso, retención, respaldos y salida de datos con sus responsables competentes; se registra la aprobación sin asumir conformidad legal automática. | `docs/ESTADO_PROYECTO.md` |

Reglas concretas adicionales que deben quedar resueltas dentro de esas decisiones:

- **01:** precedencia de anexos, documentos por familiar y renovaciones; acceso del asistente a datos sensibles.
- **02:** anulación de vínculo y efecto en sus registros; historial del puesto, vínculos simultáneos, fecha efectiva de adendas y reingreso.
- **03–04:** aplicabilidad por ley/tipo de vínculo y evidencia de formación/experiencia; los paneles ya existen.
- **05:** rol anual de vacaciones, saldos si se requieren, días/horas y confidencialidad de licencias.
- **06 y 11:** ubicación y referencia de una misma liquidación, sin duplicar documentos.
- **07:** visibilidad de calificaciones, año de evaluación, ratificación, aviso y corrección de registros ya acreditados.
- **08–09:** suspensión, recursos, controversias y catálogo de estados; no restaurar restricciones antiguas de otras ramas.
- **10:** vigencia de documentos de salud/bienestar y titular/familiar.
- **11–13:** cese con varios vínculos, legajo pasivo, reapertura, transferencia y folios de referencia.

## 6. Verificaciones pendientes: no tratarlas como defectos confirmados

Estas acciones se mantienen fuera del conteo de implementación porque el repositorio por sí solo no demuestra la situación del despliegue o una falla actual:

| ID anterior | Qué comprobar | Evidencia necesaria para cerrar |
| --- | --- | --- |
| SEG-01 | Usuarios PostgreSQL operativos y de migración con privilegios separados | Roles/grants reales y prueba negativa en un entorno aislado; no publicar credenciales. |
| SEG-06 | Aislamiento de bases, archivos, sesiones, caché y colas | Resultados actuales de los casos entre instituciones y tras error. Ya existe implementación y pruebas. |
| DOC-06 | Archivo/metadatos/enlaces coherentes | Diagnóstico de solo lectura con cantidad/tipo de incidencias; reparar solo tras confirmar un caso. |
| PER-04 | Incoherencias históricas de personal/vínculos | Diagnóstico actualizado; las notas antiguas no prueban que sigan existiendo hoy. |
| VEN-01 | Aceptación de los criterios ya confirmados | Prueba funcional con 0, 6, 7, 30, 31 días, prórroga y subsanación, sin reinventar expiración universal del legajo. |
| VEN-02 | Alertas desaparecen al atenderlas | Verificar escenarios con acciones existentes y repetir al completar REQ-09; no crear un segundo estado persistido sin necesidad. |
| VEN-05 / OPS-07 | Rendimiento y paquetes grandes | Volumen/tiempo objetivo acordados y mediciones antes de optimizar. |
| CFG-02 | Rutas frente a funcionalidades inactivas | Pruebas de rutas principales/alternativas y lectura histórica; no afirmar un bypass sin evidencia. |
| SEC-03 | Aceptación funcional de formación/experiencia | Casos revisados con ORH; solo generar defectos cuando se reproduzcan. |
| AUD-02 | Protección y retención efectiva de auditoría | Revisar aplicación más permisos/controles de DB del despliegue. |
| OPS-01 / OPS-04 | Copia externa, restauración y recuperación | Registros de copias y ensayo de restauración de bases más archivos por institución. El script de respaldo ya existe. |
| OPS-03 | Supervisión de colas, disco, errores y copias | Configuración/alertas del entorno real; no se puede afirmar su ausencia solo por no verla en Git. |
| OPS-06 | Accesibilidad real de los recorridos | Navegador, teclado, foco, contraste y tamaños acordados. |

Si una verificación descubre un defecto, crear una tarea con caso reproducible, impacto y evidencia; no marcarla como desarrollo faltante por anticipado.

## 7. Propuestas retiradas del backlog ejecutable

No son funciones comprometidas ni defectos por no existir. Requieren una solicitud explícita o validación de alcance:

- Recuperación autónoma de contraseña por correo, más allá del restablecimiento administrativo existente (SEG-03).
- Segundo factor (SEG-05), portal del propio servidor (FUT-01) y asistencia de IA (FUT-02).
- Planes comerciales, límites y comprobantes adicionales (SAAS-06).
- Reprocesamiento avanzado de importaciones (PER-03), nuevos avisos en Inicio (EVA-03), catálogos administrativos ampliados (CFG-03) y saneamiento general sin diagnóstico (AUD-04).
- Añadir fechas especializadas a Vencimientos (VEN-03): la cobertura actual usa los campos confirmados; ampliar fuentes requiere definirlas y evitar duplicados.

Esto reemplaza el tratamiento anterior que mezclaba tareas faltantes, verificaciones y oportunidades futuras dentro de los 85 ítems.

## 8. Orden de trabajo recomendado y dependencias

| Entrega | Resultado concreto | Tareas |
| --- | --- | --- |
| A | Navegación y búsqueda correctas | REQ-01, REQ-07; resolver GOB-01 y GOB-03 para la siguiente entrega. |
| B | Requerimientos coherentes | REQ-02 y REQ-10; después REQ-03 y REQ-04. |
| C | Atención completa de solicitudes | DOC-01 según decisión; REQ-08, REQ-09 y REQ-05; comprobar la integración con Vencimientos. |
| D | Cuenta de usuario y motivos completos | UI-01, UI-03, UI-02 y VEN-04, según capacidad del equipo. |
| E | Protección y calidad transversal | DOC-02, DOC-03, DOC-04, OPS-02 y OPS-05; cerrar verificaciones de operación antes de usar datos reales según política. |
| F | Avisos operativos | NOT-01, NOT-02, NOT-04 y NOT-03; después REQ-06. |
| G | Módulos hoy sin pantalla | FIS y CUS tras sus decisiones; REP y secciones 12–13 tras aprobar modelos y alcance. |
| H | Evolución de plataforma | SEG-04/07 y SAAS por prioridad validada; no ampliar acceso del superadmin a expedientes. |

No hay fechas ni sprints comprometidos. No iniciar una tarea bloqueada fingiendo que sus reglas ya fueron aprobadas. Cada entrega debe ser pequeña y revisable; no fusionar ramas completas para resolver una corrección puntual.

## 9. Criterio común de terminado

1. Criterio funcional aprobado cuando dependa de decisión; responsable y alcance asignados.
2. Camino correcto y errores esperados verificados con datos ficticios.
3. Permisos de API, URL directa y otra institución/usuario comprobados donde apliquen.
4. Fechas en Lima y límites exactos; prórroga, anulado, subsanado, sin fecha/archivo cuando corresponda.
5. Loading, vacío, error/reintento y datos en interfaz; controles usables por teclado.
6. Pruebas pertinentes ejecutadas; lint/build/formato según el cambio. Registrar resultados reales, no copiar conteos históricos.
7. Uso del bootstrap temporal de pruebas existente; ninguna prueba usa storage o bases de trabajo reales.
8. Migración y recuperación cuando correspondan; compatibilidad con datos previos y documentación actualizadas.
9. PR/commit y aceptación referenciados en la tarea al cerrarla.

## 10. Límites y mantenimiento del documento

Solo se actualizó este backlog. No se ejecutaron migraciones, pruebas, despliegues ni modificaciones de funcionalidad. Se verificó la integridad de IDs/dependencias del documento, incluyendo ausencia de ciclos y existencia de las rutas de evidencia.

Las tareas que proceden de la hoja de ruta de ESTADO_PROYECTO deben revalidarse al seleccionarlas; no todas son obligatorias para el alcance contratado. No se reanalizó el PDF institucional ni se verificaron reglas legales. No se revisaron todas las ramas remotas ni servicios externos de operación.

Al cerrar una tarea, añadir fecha, referencia de cambio, verificación y qué decisión reemplaza. Al actualizar main, volver a contrastar navegación, API, pruebas y documentación: un texto histórico o un TODO obsoleto no debe convertirse automáticamente en trabajo nuevo.
