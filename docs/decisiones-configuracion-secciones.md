# Decisiones: configuración de secciones y funcionalidades

**Referencia única** de las decisiones y propuestas de diseño sobre secciones, funcionalidades configurables, cuentas y modelo SaaS.
Si otro documento (auditorías, notas de entrega) dice algo distinto, manda este. El estado general del proyecto está en `docs/ESTADO_PROYECTO.md`.

- **Estado:** **implementado** desde el 09-11/10/2026 (`7828b56` catálogo, `1d4c159` configuración y puntaje de la 07, `3ffefd4` pestaña Funcionalidades). Código: `config/funcionalidades.php`, `app/Support/ConfiguracionFuncionalidades.php`, `FuncionalidadActiva`, `ConfiguracionFuncionalidadController`. Antecedente [HISTÓRICO]: hasta el 08/10 el enfoque estaba aprobado solo de forma conceptual y sin autorización para implementarlo.
- **Última revisión:** 17/10/2026, sobre `main`. Ese dato puede cambiar: compruébalo con `git log -1`. Revisiones anteriores: 08/10/2026 (`integracion/secciones`, `72aba65`) y 10/10/2026 (selección e ingreso, sección 6). Las marcas [VERIFICADO 08/10/2026] describen el código de esa fecha; donde cambió, se añade una verificación del 17/10/2026.
- Este documento estuvo solo en un stash local hasta el 17/10/2026, aunque el código ya lo citaba.

## Leyenda

| Etiqueta | Significado |
|---|---|
| **[APROBADO]** | Confirmado expresamente por el usuario (se indica dónde) |
| **[PROVISIONAL]** | Aceptado expresamente por el usuario como temporal |
| **[PROPUESTA]** | Recomendación del asistente o de una auditoría que **no** ha sido aprobada |
| **[PENDIENTE UNIVERSIDAD]** | Requiere confirmación de la institución o de sus responsables |
| **[PENDIENTE EQUIPO]** | Requiere una decisión explícita del equipo (incluidas las del modelo comercial) |
| **[VERIFICADO]** | Hecho comprobado en el código o en la Directiva, con su fecha |
| **[SUPERADO]** | Propuesta reemplazada después |
| **[HISTÓRICO]** | Antecedente que se conserva como referencia |

Fuentes de aprobación citadas:
- **«requisito de secciones»:** mensaje del usuario sobre la gestión de secciones.
- **«ajuste SaaS»:** mensaje del usuario que aprueba conceptualmente el enfoque y pide los ajustes SaaS.
- **«corrección»:** mensaje del usuario que pide corregir la propuesta.
- **«depuración»:** lista de decisiones que el usuario dio para depurar la documentación.
- **«revisión de etiquetas»:** mensaje del usuario que pide esta corrección de etiquetas.
- **«selección externa»:** decisión provisional del usuario (10/10/2026) sobre el proceso de selección y la documentación de ingreso.
- **«alcance de registro»:** decisiones del proyecto que dio el usuario (10/10/2026) sobre el alcance del sistema, la sección 02 y la selección externa. Confirman y amplían «selección externa».

---

## 1. Responsabilidades

- [APROBADO] Se distinguen el administrador de la plataforma (proveedor), el administrador de la institución y el usuario del sistema («ajuste SaaS», «depuración»).
- [APROBADO] Si en el futuro hay planes con módulos por contrato, el proveedor determina qué está disponible y el administrador institucional activa o desactiva lo disponible y autorizado («ajuste SaaS»). No hay planes confirmados.
- [APROBADO] La configuración de módulos y los permisos de las cuentas son cosas distintas: activar algo no da permiso a nadie («requisito de secciones», «depuración»).
- [APROBADO] En el alcance inicial no se asume que cada trabajador tenga cuenta. Persona registrada ≠ cuenta de acceso («depuración»). Esto no garantiza que nunca vaya a existir un portal.
- [PENDIENTE UNIVERSIDAD] El personal de apoyo del administrador no está confirmado. No se diseñan roles definitivos.
- [PROPUESTA] La cuenta del administrador de la plataforma vive fuera de la base de la institución; las cuentas institucionales, dentro. Depende del modelo de aislamiento (sección 7).
- [APROBADO 17/10/2026] Activa y desactiva el administrador de cada institución (rol ADMIN), con motivo obligatorio y auditoría. Otra persona puede hacerlo si recibe el rol `configuracion_modulos` (sección 8).

## 2. Dimensiones de la configuración

- [APROBADO] "Esencial" es una restricción independiente, no un estado como activa, inactiva o no disponible («corrección»).
- [APROBADO] Una funcionalidad no está activa si su sección está inactiva («corrección»).
- [APROBADO] La configuración es centralizada y auditable («ajuste SaaS»).
- [PROPUESTA] Dimensiones:

| Dimensión | Valores | Dónde se define |
|---|---|---|
| Implementación | implementada / no implementada | Catálogo en código |
| Carácter (solo secciones) | normativa (01–12) / adicional (art. 3.2) | Catálogo en código |
| Disponibilidad | disponible / no disponible | Una consulta |
| Activación | activa / inactiva | Almacenamiento auditable (ver sección 7) |
| Restricción "estructura normativa" | la sección no se puede desactivar entera | Catálogo en código |
| Restricción "esencial" | no se puede desactivar mientras exista la dependencia, con su motivo | Catálogo en código |

- [PROPUESTA] Al reactivar una sección, cada funcionalidad recupera la elección que tenía guardada.

## 3. Secciones

- [APROBADO] Las 12 secciones normativas (art. 3.1) se conservan en la estructura del legajo («depuración»).
- [PENDIENTE UNIVERSIDAD] ¿Se puede ocultar o desactivar por completo una sección normativa? Lo decide la universidad con su asesoría legal. [VERIFICADO 08/10/2026] El art. 3.1 dice que el legajo contiene "como mínimo" lo señalado y que "debe contener" las 12 secciones.
  - [APROBADO] Para la **sección 02** ya está decidido: forma parte del expediente y no es opcional, aunque la selección ocurra fuera del sistema («alcance de registro»). Ver §6, "Selección e ingreso".
- [SUPERADO] ~~Mientras no se confirme, la primera versión no permite desactivar secciones normativas completas. Lo configurable son funcionalidades dentro de ellas («depuración», «revisión de etiquetas»).~~
- [APROBADO 09/10/2026] La institución puede desactivar cualquier funcionalidad, también secciones normativas enteras, con la advertencia del art. 3.1, motivo y auditoría; nada se borra y todo es reversible. El núcleo fijo (identidad 01 y vínculo 2.2) no se desactiva. [VERIFICADO 17/10/2026] Implementado así en `config/funcionalidades.php` (`advertencia_normativa`; una sección se oculta cuando no le queda ninguna funcionalidad activa).
- [APROBADO] Una funcionalidad solo se puede configurar si existe una alternativa para las operaciones que dependen de ella («depuración»).
- [PROPUESTA] Esa alternativa: una forma mínima de incorporar un documento (PDF + metadatos + folio) a cualquier sección normativa.
- [VERIFICADO 08/10/2026] Hoy la subida común rechaza algunos documentos de la 04, la 06 y la 10 y obliga a usar sus formularios propios (`DocumentoLegajoController`, líneas 70–72). Por eso hoy esas funcionalidades no tienen alternativa.
- [APROBADO] Una sección o funcionalidad inactiva no aparece en la navegación habitual y no permite operaciones nuevas desde el backend; ocultarla en el frontend no basta («requisito de secciones»).

### Sección 12
- [VERIFICADO 08/10/2026] Sin controlador, pantalla ni tipos de documento: no se puede usar.
- [APROBADO] Sigue sin implementarse hasta conocer su contenido institucional; no se inventa («corrección», «depuración»).
- [PENDIENTE UNIVERSIDAD] Qué guarda la universidad en ella.

### Sección adicional de legajos pasivos (art. 3.2 y Anexo 07)
- [VERIFICADO] El art. 3.2 dice que las ORH "pueden" agrupar los legajos pasivos en una sección adicional para el servidor que reingresa; el Anexo 07 la rotula "SECCIÓN 13".
- [VERIFICADO 08/10/2026] Hoy el catálogo la trata como la 13 y `AperturaLegajoController::store` exige que exista para abrir un legajo.
  - [VERIFICADO 17/10/2026] Ya no lo exige: es optativa y no se crea al abrir un legajo (`AperturaLegajoController`, `config/funcionalidades.php`). Su funcionalidad (`adicional.referencias_pasivas`) sigue bloqueada mientras se decide lo de los folios (abajo).
- [APROBADO] Es optativa, se distingue de las 12 normativas y **no es requisito para abrir un legajo** («corrección», «depuración»).
- [PROPUESTA] No cuenta para la completitud ni para lo exigido.
- [PROPUESTA] Se gestiona como la funcionalidad "referencias a legajos pasivos".
- [PROPUESTA] Inactiva por defecto. Las referencias que ya existan se pueden leer.
- [PENDIENTE EQUIPO] Comprobar si las referencias usan folios del legajo activo (`referencias_legajo_pasivo` guarda folios del legajo **pasivo**).
- [PENDIENTE UNIVERSIDAD] Si la universidad usa esta agrupación.

## 4. Datos existentes

- [APROBADO] Desactivar una sección o funcionalidad no elimina registros, documentos, folios ni referencias históricas. Desactivar no es eliminar («requisito de secciones», «corrección», «depuración»).
- [APROBADO] La información histórica no se expone a cuentas sin permiso («corrección»).
- [PROPUESTA] Desactivar solo cambia el estado y deja un evento de auditoría. No altera archivos, historial, bitácora, `legajo_secciones.ultimo_folio`, `subsana_a_documento_id` ni los enlaces documento ↔ dato. Al reactivar, los folios siguen donde quedaron.
- [PROPUESTA] Lo inactivo deja de contar para lo exigido, los resúmenes y las alertas mientras dure la desactivación.
- [PROPUESTA] Lo histórico se puede leer y descargar siempre con los permisos de lectura actuales (`legajo.ver`; `datos_sensibles.ver` en 01, 09, 10 y tipos sensibles; `seccion07.ver`; permisos de sanciones de la 08), desde un acceso aparte de la navegación habitual.
- [PROPUESTA] No crear un permiso especial para ver lo inactivo.

## 5. Matriz de operaciones

Conceptos que no se deben confundir:
- **Estado del legajo:** `legajos.estado_legajo`.
- **Situación laboral:** que exista un vínculo vigente.
- **`usuarios.activo`:** si la cuenta puede iniciar sesión.

La situación laboral y `usuarios.activo` no intervienen en esta matriz, salvo en las reglas propias de cada registro [VERIFICADO 08/10/2026].

| Operación | ¿Exige disponible? | ¿Exige activa? | Permiso | Estado del legajo |
|---|---|---|---|---|
| Creación | [PROPUESTA] Sí | [APROBADO] Sí | [VERIFICADO] El de escritura actual | [VERIFICADO] Activo (hoy) |
| Modificación | [PROPUESTA] Sí | [APROBADO] Sí | [VERIFICADO] El de escritura actual | [VERIFICADO] Activo (hoy) |
| Lectura | [PROPUESTA] No | [PROPUESTA] No | [VERIFICADO] El de lectura actual | [VERIFICADO] Cualquiera (hoy) |
| Descarga y visor | [PROPUESTA] No | [PROPUESTA] No | [VERIFICADO] El de lectura actual; queda en la bitácora | [VERIFICADO] Cualquiera (hoy) |
| Anulación | [PENDIENTE UNIVERSIDAD] | [PENDIENTE UNIVERSIDAD] | [VERIFICADO] `sistema.administrar` (+ datos sensibles) | [VERIFICADO] Activo (hoy) |
| Recepción | [PENDIENTE UNIVERSIDAD] | [PENDIENTE UNIVERSIDAD] | [VERIFICADO] `documento.recepcionar` | [VERIFICADO] Activo (hoy) |
| Verificación documental | [PENDIENTE UNIVERSIDAD] | [PENDIENTE UNIVERSIDAD] | [VERIFICADO] `documento.revisar` / `seccion08.sanciones.revisar` | [VERIFICADO] Activo (hoy); art. 4.5 |
| Exportación del legajo | [PROPUESTA] No | [PROPUESTA] No | [PROPUESTA] El de lectura; sin datos sensibles si no tiene `datos_sensibles.ver` | [PROPUESTA] Cualquiera |
| Cambiar la configuración | — | — | [APROBADO] `configuracion.modulos` («depuración») | — |

- [VERIFICADO 08/10/2026] Hoy `LegajoSoloLectura` bloquea toda escritura en un legajo que no está activo y deja pasar la lectura y la descarga.
- [VERIFICADO 08/10/2026] Hoy no existe una exportación completa del legajo, solo el PDF de la ficha personal.
- [PENDIENTE UNIVERSIDAD] Anular, recepcionar o verificar registros existentes de algo inactivo. Opciones [PROPUESTA]:
  - **Opción 1:** bloquear; hay que reactivar temporalmente.
  - **Opción 2:** permitirlo solo sobre registros existentes.
  - Ninguna está aprobada ni es valor por defecto.
- [PENDIENTE UNIVERSIDAD] Anular, recepcionar o verificar en un legajo cerrado (hoy no se permite).

## 6. Dependencias esenciales

- [PROVISIONAL] La **identidad** (ficha general y documento de identidad, sección 01) es esencial («depuración», «revisión de etiquetas»).
  - Motivo [VERIFICADO 08/10/2026]: el titular de todo documento se completa con los datos de la persona (`DocumentoLegajoController`, línea 78).
- [PROVISIONAL] La **creación del vínculo laboral** (2.2) es esencial («depuración», «revisión de etiquetas»).
  - Motivo [VERIFICADO 08/10/2026]: los vínculos solo se crean en `Seccion02Controller::storeVinculo`.
  - Dependencias [VERIFICADO 08/10/2026]: los usan `PerfilDocumental` (01, 03, 04, 06, 07, 08), la 05 (`periodos_vacacionales.vinculo_laboral_id`), la 07 (progresiones y ratificaciones), la 11 (`desvinculaciones.vinculo_laboral_id`) y `referencias_legajo_pasivo`.
- Ambas se revisan cuando se resuelva:
  - Si existe un proceso de selección o incorporación previo a la vinculación, y dónde nace el vínculo (02, proceso de incorporación o carga inicial).
    - Historial: [PENDIENTE UNIVERSIDAD] desde el 08/10/2026.
    - [PROVISIONAL] Respondido en parte («selección externa», 10/10/2026).
    - [APROBADO] Confirmado («alcance de registro», 10/10/2026): la selección ocurre fuera del sistema y se registra al personal ya seleccionado o aceptado. Ver "Selección e ingreso" más abajo.
    - [PENDIENTE UNIVERSIDAD] Sigue pendiente dónde nace el vínculo en la carga inicial del personal existente.
  - [PENDIENTE UNIVERSIDAD] Cuándo se considera formalizada la vinculación y se abre el legajo.
  - [PENDIENTE EQUIPO] Si se mantiene que el vínculo exija un legajo abierto (`exigirLegajo`). [VERIFICADO] El art. 4.1 abre el legajo "concluido el Proceso de Vinculación".
- [PENDIENTE UNIVERSIDAD] Si familiares, declaraciones y discapacidad (01) y 2.1, 2.3 y 2.4 (02) se pueden desactivar. No son esenciales técnicamente, pero son contenido normativo.
  - [PROPUESTA] No permitirlo mientras no haya respuesta.
  - [PROVISIONAL] Excepción ya decidida: S02_A (2.1 a) no se desactiva ni pasa a opcional porque la selección ocurra fuera del sistema («selección externa»).
  - [APROBADO] Confirmado: los documentos del resultado de la selección (2.1 a) y los de contratación o designación (2.2) se conservan en el expediente («alcance de registro»). Siguen pendientes 2.1 b), 2.3 y 2.4.

### Selección e ingreso

Origen: la universidad comunicó que no gestiona la selección con este sistema y que registra a las personas cuando ya han sido seleccionadas.

Antecedente [HISTÓRICO]: la primera decisión fue provisional («selección externa», 10/10/2026). Decía:
- la selección ocurre fuera del sistema;
- S02_A se conserva y no pasa a opcional solo por eso;
- el designado debe tener documentación de su contratación o designación;
- se mantiene el código.

Ese mismo día la confirmó y amplió «alcance de registro»:
- [APROBADO] **Alcance del sistema:** el registro y la carga de la información del personal ya seleccionado o aceptado para incorporarse a la universidad. El sistema no gestiona su proceso de selección.
- [APROBADO] La selección ocurre **fuera del sistema**. No se implementan convocatorias, postulaciones, entrevistas, clasificación de candidatos ni decisiones de selección.
- [APROBADO] La **sección 02** forma parte del expediente. No es opcional ni se elimina porque la selección ocurra fuera del sistema.
- [APROBADO] Los documentos que acreditan el resultado de la selección se conservan en el expediente: S02_A, "Resultado final del proceso de selección" (art. 3.1, sección 02, 2.1 a).
- [APROBADO] También se conserva la documentación de contratación o designación (2.2).
- [APROBADO] El personal designado también debe tener la documentación que acredite su contratación o designación. No queda sin documentación de ingreso por no haber participado en un concurso.
- [PROVISIONAL] Por el momento se mantiene el comportamiento actual del código. Esta decisión **no autoriza** a cambiar validaciones, matriz documental, catálogos, migraciones ni interfaces.
- Alcance de la obligatoriedad:
  - Que la sección 02 sea obligatoria **no significa** que cada uno de sus tipos documentales sea exigible a todos los regímenes.
  - Lo decidido: la sección se conserva, S02_A y la documentación de contratación o designación se conservan, y todo ingresante tiene documentación de ingreso.
  - Qué tipo concreto se exige a cada régimen sigue pendiente (ver la contradicción de abajo).
- [VERIFICADO 10/10/2026] El sistema no tiene ninguna funcionalidad de convocatorias, postulaciones ni candidatos. De la selección solo registra:
  - el documento S02_A;
  - dos campos opcionales del vínculo: `proceso_seleccion` y `fecha_resultado_seleccion` (`Seccion02Controller`).
- Contradicción técnica con el personal designado. Está documentada y **no se corrige** sin decisión. No hay solución propuesta.
  - [VERIFICADO 10/10/2026] Todos los tipos obligatorios de la 02 se exigen a los tres regímenes, también a `designado` (`Seccion02CatalogoSeeder::BASE`: `obligatorio` y todos los `aplica_*` en `true`). Por eso `PerfilDocumental::exigencia` exige a un designado:
    - S02_A (resultado de selección);
    - S02_B;
    - las resoluciones de designación (C) y de carrera (E) y el contrato (F), todas a la vez.
  - [VERIFICADO 10/10/2026] La 2.1 solo cuenta como completa si un vínculo vigente tiene `proceso_seleccion` y `fecha_resultado_seleccion` (`Seccion02Controller.php`, líneas 113–114). Un designado sin proceso de selección registrado nunca la completa.
  - [HISTÓRICO] `docs/auditoria2/01-catalogo-vs-directiva.md` ya lo señaló como inferencia: un designado de confianza no pasa concurso.
  - Ya decidido (no está pendiente): el designado debe tener documentación de ingreso, y S02_A y la documentación de contratación o designación se conservan.
  - [PENDIENTE UNIVERSIDAD] Las reglas específicas por régimen, que siguen abiertas. Son el pendiente 11 de `ESTADO_PROYECTO.md`, y además lo señala un TODO del seeder:
    - si a un designado se le exige S02_A y, si no, qué documento acredita su ingreso en su lugar;
    - qué documento de formalización (C a G) corresponde a cada régimen;
    - a quién aplica 2.1 b).

## 7. Disponibilidad, almacenamiento y SaaS

- [APROBADO] No se asumen planes comerciales. En la primera versión, toda funcionalidad implementada se considera disponible, sin complejidad innecesaria («ajuste SaaS», «depuración»).
- [APROBADO] No se agrega `entidad_id` ni `tenant_id` («ajuste SaaS»; regla de `CLAUDE.md`).
- [APROBADO] El diseño debe ser compatible con una aplicación y una base de datos por institución, **sin implementarlo todavía** («ajuste SaaS»).
- [APROBADO 13/10/2026] Modelo de aislamiento: una base por institución (`ESTADO_PROYECTO.md`, decisión 6), implementado con el panel de plataforma y la identificación por subdominio. Siguen pendientes [PENDIENTE EQUIPO] el número esperado de instituciones, el alojamiento propio, el soporte con acceso a datos y la baja de clientes.
- [VERIFICADO 17/10/2026] La tabla de activación (`configuracion_funcionalidades`), sus eventos de auditoría y los roles de las cuentas viven en la base de cada institución.
- [PROPUESTA] En la primera versión, la disponibilidad es una función que responde "disponible" para lo implementado, sin tabla ni plano de control.
- [PENDIENTE EQUIPO] Solo si hay planes: de dónde se lee la disponibilidad, cómo se audita a un operador de la plataforma y qué pasa con lo que deja de estar disponible.

## 8. Cuentas y permisos

- [APROBADO] `configuracion.modulos` es un permiso explícito, fuera del comodín `'*'` («depuración»). Un rol que solo tenga `'*'` no lo recibe.
- [VERIFICADO 08/10/2026] Hoy `Usuario::permisos()` solo reconoce `'*'` o listas de permisos por rol, y no hay asignación por usuario. Para dejar `configuracion.modulos` fuera de `'*'` hay que cambiar esa lógica.
- [VERIFICADO 17/10/2026] Mecanismo implementado: opción (a), categoría "asignación explícita" (`config/permisos.php`, `explicitos`) más el rol adicional `configuracion_modulos`. Un rol puede combinar el comodín con explícitos: `['*', 'configuracion.modulos']`.
- [APROBADO 17/10/2026] El rol **ADMIN incluye explícitamente** `configuracion.modulos` (`'ADMIN' => ['*', 'configuracion.modulos']`): el administrador de cada institución (jefe de la ORH) configura sus funcionalidades, también el primero que crea el aprovisionamiento. Cada cambio exige motivo y queda auditado. El rol `configuracion_modulos` queda para otra persona que no sea ADMIN.
  - Antecedente [HISTÓRICO]: del 10 al 17/10/2026 el ADMIN no lo tenía; había que asignarle a mano el rol `configuracion_modulos` y ninguna institución nueva podía configurar sus funcionalidades.
- [PENDIENTE UNIVERSIDAD] Si se exige sustento documental para cada cambio de configuración, además del motivo.
- [VERIFICADO 08/10/2026] No existe un mecanismo seguro para crear el primer administrador en producción. `AdministradorSeeder` solo corre en `local` y `testing`.
  - [VERIFICADO 17/10/2026] Ya existe: el primer superadministrador se crea con `php artisan plataforma:crear-administrador` y el primer administrador de cada institución, al aprovisionarla desde el panel, con una contraseña temporal que debe cambiar.

## 9. Cuestiones abiertas que condicionan la implementación

| Cuestión | Clasificación | Qué bloquea |
|---|---|---|
| Línea base de pruebas reproducible (ver `ESTADO_PROYECTO.md`) | [PENDIENTE EQUIPO] | Toda la implementación |
| Coordinación de ramas, en especial `feature/registro-guiado-relacion-titular` | [PENDIENTE EQUIPO] | Middleware, exigencias, rutas y menú |
| Rama de trabajo | [PENDIENTE EQUIPO] | Toda la implementación |
| Alternativa de subida para 04, 06 y 10 | [PROPUESTA] / [PENDIENTE EQUIPO] | Desactivar esas funcionalidades |
| ~~Mecanismo para `configuracion.modulos`~~ | [SUPERADO 17/10/2026] Resuelto (sección 8) | — |
| Folios de las referencias a legajos pasivos | [PENDIENTE EQUIPO] | Paso de la sección adicional |
| ~~Desactivación de secciones normativas completas (art. 3.1)~~ | [SUPERADO] Permitida con advertencia desde el 09/10/2026 (sección 3) | — |
| Cerrar la escritura del CRUD genérico de las tablas de sección | [PROPUESTA] / [PENDIENTE EQUIPO]. No hay aprobación expresa del usuario. [VERIFICADO 17/10/2026] Hoy el código la cierra por defecto (`API_CRUD_GENERICO_ESCRITURA=false`, `ProduccionTest`) y `FuncionalidadActiva` rechaza la escritura de lo inactivo; falta confirmar que esa es la decisión | Sin ese cierre, el control de configuración se puede saltar por el CRUD genérico |
| ~~Primer administrador seguro y retiro de `LocalDevelopmentOnly`~~ | [SUPERADO 17/10/2026] Resuelto: `plataforma:crear-administrador`, aprovisionamiento con contraseña temporal y `ApiHabilitada` (la API fuera de local solo responde si se habilita) | — |

## 10. Propuestas superadas e históricas

- [SUPERADO] "Esencial" como un estado más, junto a activa e inactiva → restricción independiente (sección 2).
- [SUPERADO] Una sola fórmula (`disponible ∧ activa ∧ permiso ∧ legajo abierto`) para todas las operaciones → matriz por operación (sección 5).
- [SUPERADO] Tratar la 13 como sección obligatoria creada al abrir el legajo → sección adicional optativa (sección 3).
- [SUPERADO] "La 02 entera es esencial" → solo la creación del vínculo, de forma provisional (sección 6).
- [HISTÓRICO] Desactivar secciones completas como mecanismo general. No se descarta: queda pendiente de la universidad (sección 3).
- [HISTÓRICO] Las recomendaciones de `docs/auditoria/` y `docs/auditoria2/` (ver sus `LEEME.md`). No son decisiones salvo lo que recoge este documento. Al 17/10/2026 esas carpetas no están en el repositorio (solo en un stash local).
- [SUPERADO 09/10/2026] "La primera versión no permite desactivar secciones normativas completas" → se permite con advertencia (sección 3).
- [SUPERADO 17/10/2026] "El comodín de ADMIN no incluye `configuracion.modulos`; hace falta el rol `configuracion_modulos`" → ADMIN lo incluye explícitamente (sección 8).
