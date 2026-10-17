# Revisión e integración de acceso, incorporación y vencimientos

## 1. Alcance y estado

Revisión estática de la rama `feature/Panel-Acceso_e_Incorporacion` en `56f6c50`, frente a `main` en `9153888`. Las referencias local y remota almacenadas de la feature coinciden; no se ha consultado GitHub para comprobar actualizaciones. La revisión inicial fue estática. La funcionalidad se adaptó selectivamente sobre main sin un merge íntegro; las comprobaciones posteriores se detallan en la sección 8. No se aplicaron migraciones a las bases de trabajo del usuario.

La rama contiene cinco commits exclusivos y 144 archivos diferentes desde el ancestro común. Incluye más que acceso e incorporación: vencimientos y renovación, auditoría, antivirus, permisos persistidos, herramientas operativas, CI y despliegue. Integrarla íntegramente amplía el alcance y requiere revisar esas áreas.

## 2. Componentes y lógica de negocio

- `frontend/src/features/incorporation/IncorporacionPage.jsx`: tablero con filtros, paginación, selección y acciones sobre expedientes. Usa React, Axios, permisos de sesión y `useLiveQuery`.
- `frontend/src/features/settings/SectionAccessPage.jsx`: configuración de disponibilidad de las trece secciones y borrador de cambios.
- `backend/app/Http/Controllers/Api/V1/IncorporacionController.php`: creación, delegación, carga documental, revisión y activación. `Incorporacion` conserva documentos, tareas e historial en JSON.
- Estados de incorporación: registrado, en revisión, observado, admitido, incorporado y rechazado. Para admitir se exigen documentos revisados, tareas completas y datos de resolución/inicio. La incorporación activa cuenta, asigna rol y crea o reutiliza vínculo y legajo dentro de una transacción.
- Se usa bloqueo de fila y versión del expediente para rechazar escrituras atrasadas con 409. Quien carga un documento no puede revisarlo. Las contraseñas no se incluyen en el historial.
- `AccesoSeccionesController`, `AccesoSecciones` y `AccesoLegajo` controlan visibilidad y acceso a secciones. Desactivar conserva datos; las etiquetas de período no implementan calendario. El guardado de configuración usa `If-Match`.
- Rutas institucionales: `/api/v1/incorporaciones`, `/api/v1/acceso-secciones`, `/api/v1/vencimientos`. Frontend: `/incorporacion`, `/accesos`, `/vencimientos` y `/mi-legajo`.
- El panel `frontend/src/features/requirements/VencimientosPage.jsx` ya existe en la feature. Incluye listado, filtros de categoría/estado/fechas, paginación, detalle y renovación. No corresponde crear otro módulo duplicado.
- Vencimientos usa `CicloVigencia`, `RenovacionLegajo`, `PresentacionRenovacion`, `AvisoVencimiento`, `EnvioVencimiento`, `VigenciaLegajo` y `AvisosVencimiento`. Calcula ciclos docentes ordinarios de auxiliar/asociado/principal de 3/5/7 años, con resolución acreditada; no representa automáticamente todos los documentos o contratos administrativos.
- La consulta ordena por vencimiento ascendente y luego por identificador. Vigencia: hoy o antes = vencido; siguientes 30 días = por vencer. Color: 7 días o menos = rojo, hasta 30 = amarillo. La fecha de trabajo usa America/Lima.
- Dependencias: no hay cambios de Composer entre el ancestro y la feature. Se añade `@playwright/test` y `test:e2e` en frontend. La instrucción de `backend/AGENTS.md` exige aprobación antes de cambiar dependencias; la integración funcional puede conservar el manifiesto de main y evaluar E2E por separado.

## 3. Hallazgos y riesgos antes de integrar

### Alto: scheduler sin contexto de institución

`backend/routes/console.php` programa control y envío globales. `ControlVencimientos::handle()` consulta la conexión predeterminada y no recorre instituciones. En main el contexto se establece mediante `ConexionInstitucion`; los comandos programados no pasan por el middleware HTTP. Al integrar sin adaptación, las instituciones secundarias podrían quedar sin procesar. Adaptar la ejecución por institución y probar bases, discos y caché separados.

### Alto: incompatibilidad de reglas de negocio del panel solicitado

`VencimientoLegajoController::index()` restringe a usuarios docentes y usa un horizonte fijo de 30 días. `VigenciaLegajo::calcular()` considera hoy vencido y rojo <=7. El pedido propone rango configurable y rojo <7. Confirmar alcance, umbral e inclusión de hoy antes de implementar; separar el filtro de consulta de la regla que determina vigencia y del calendario de avisos.

### Alto: conflictos con main y políticas de producción

Main conserva superadministración, instituciones, configuración funcional y formularios documentales posteriores al ancestro. La feature introduce `ApiDisponible`, mientras main usa `ApiHabilitada`. Resolver rutas conservando la política vigente de main y el aislamiento institucional; no reemplazar archivos completos con la versión antigua de la feature.

### Medio: carga de incorporación distinta al circuito documental

`IncorporacionController::subir()` valida PDF y guarda versiones privadas, pero no llama directamente a `Antivirus`, a diferencia del circuito de renovación. Revisar el contrato de `ArchivoPdf` y decidir cómo aplicar la política de antivirus de main a este flujo. No se afirma explotación: es una diferencia del circuito que requiere prueba.

### Medio: responsabilidades concentradas

Controladores de incorporación y vencimientos combinan validación, autorización, transiciones, persistencia, almacenamiento y presentación; los componentes agrupan formularios y acciones extensos. Mantener cambios funcionales acotados y extraer consultas/helpers cuando se reutilicen; evitar una refactorización general durante la integración.

### Medio: datos JSON e historial creciente

Documentos, versiones, tareas e historial de incorporación se reescriben dentro de la misma fila. La versión protege contra actualizaciones atrasadas, pero el crecimiento incrementa tamaño y contención. Verificar tamaño de respuesta, límites y paginación del historial antes de ampliar el volumen.

### Medio: material ajeno al alcance

La feature incorpora más de 18.000 líneas en `docs/codigo-backlog.md`, contrato API, otra configuración de producción y CI. No son necesarios para portar los paneles y deben revisarse separadamente.

## 4. Estrategia de integración aplicada

Adaptación selectiva de los commits funcionales sobre main, equivalente a cherry-pick revisado por archivo. Se descarta rebase porque reescribiría la rama compartida; un merge completo introduciría cambios operativos y de dependencias ajenos al objetivo.

1. Mantener intactos `.env`, copias de respaldo y resultados locales. Tomar main como base.
2. Portar acceso e incorporación del commit `95916e1`, conservando mejoras de main en usuarios, vínculos, documentos, configuración y plataforma.
3. Portar el núcleo de vencimientos de `3e9d5ce` y evaluar correcciones posteriores de los otros commits; no asumir que el commit inicial contiene el resultado final.
4. Integrar migraciones, relaciones, permisos, rutas y navegación. Respetar conexiones institucionales y rutas separadas del superadmin.
5. Adaptar tareas programadas a cada institución y comprobar restauración del contexto después de cada ejecución.
6. Con el criterio confirmado, ampliar el panel existente con rango validado y configurable, indicadores textuales y visuales, y filtro aplicable de categoría/área. Conservar estados loading, error con reintento, vacío y datos.
7. Ejecutar pruebas funcionales y de regresión; validar lint/build frontend y formato PHP. No ejecutar migraciones sobre los datos del usuario como parte de las pruebas.
8. Crear commits descriptivos separados para integración, adaptación de vencimientos y documentación/pruebas. Integrar a main únicamente con comprobaciones satisfactorias.

## 5. Plan de pruebas

Unitarias: cálculo en 0, 6, 7, 29, 30 y 31 días; año bisiesto; fecha local cerca de medianoche; validación de rango y parámetros.

Funcionales: acceso sin sesión; administrador; delegado asignado/no asignado; titular propio/ajeno; transiciones inválidas; versión atrasada 409; separación cargador/revisor; autorización en API aunque se oculte el menú; secciones inactivas; filtros y orden antes de paginar; filtros combinados; cuentas y archivos separados entre instituciones; scheduler procesa todas las instituciones y restaura contexto tras error.

Regresión: login admin y superadmin, configuración funcional, formularios documentales actuales y edición de vínculos. Migraciones en SQLite de pruebas y PostgreSQL, incluyendo ejecución por institución.

Manuales: navegación por teclado, foco visible y devolución del foco al cerrar diálogos; labels; contraste; indicadores con texto además de color; carga lenta; errores de red y reintento; vacío; cambio de filtros desde páginas posteriores; rango de un solo día y bordes del umbral.

Pruebas existentes relevantes en la feature: `AccesoSeccionesTest`, `IncorporacionTest`, `VencimientoLegajoTest`, `UsuariosPaginacionTest` y pruebas de concurrencia PostgreSQL. Todavía no ejecutadas en esta revisión.

## 6. Decisiones confirmadas y adaptación

El usuario confirmó los tres tipos: ciclos, contratos/vínculos y documentos. También confirmó próximos 30 días incluyendo hoy, rojo con menos de 7 días y amarillo para el resto del rango.

Se implementó `/vencimientos` como listado unificado y se conservó el circuito docente en `/vencimientos?vista=renovaciones`. La API nueva es `GET /api/v1/vencimientos/proximos`, con filtros validados, rango 1–365 días, orden global en SQL antes de paginar y autorización sobre cada fuente. Las fechas sin registrar no se incluyen; una persona puede tener más de un vencimiento.

La regla histórica del trámite docente (vencido desde el propio día y rojo <=7) se conserva en su vista de gestión, diferenciada de los criterios del nuevo listado; no se cambia el calendario de avisos ni las duraciones de los ciclos. Los contratos usan la última adenda válida y los documentos el campo común `fecha_caducidad`.

Resoluciones de conflictos:

- Se conservaron las rutas de plataforma, `ApiHabilitada`, cambio obligatorio de contraseña, funcionalidades configurables, solo lectura de legajos cerrados y configuración de colas institucionales de main.
- Se mantuvieron las controversias y demás funciones actuales de la sección 09. No se portó la antigua prohibición general de la feature a todo lo que no fuera afiliación sindical.
- Incorporación reutiliza el catálogo dinámico de leyes y helpers de main. El tipo de vínculo nuevo queda Sin definir para completarlo en 02; no se guarda una ley como si fuera tipo de vínculo.
- Se protegieron requisitos de ciclos frente a modificaciones por CRUD genérico y se adaptó la precarga de roles de avisos al sistema de permisos de main.
- Se corrigió la consulta de vigencia de roles para comparar fechas y admitir asignaciones creadas hoy en SQLite. Las pruebas existentes se ajustaron a los roles que ahora crea la migración y a los modelos sin CRUD genérico.
- Las tareas programadas pasan por `plataforma:procesar-vencimientos`, que procesa cada institución activa/aprovisionada con contexto aislado y continúa ante fallos individuales.
- Se reutilizaron `useLiveQuery`, Axios, permisos, componentes del legajo y modal del panel. Se añadieron un servicio de consulta y un helper compartido para la fecha efectiva del vínculo.
- No se modificaron manifiestos ni lockfiles de dependencias. Se excluyeron los cambios de CI, otro despliegue, permisos persistidos y grandes archivos de backlog ajenos al alcance funcional.

## 7. Inventario de la integración

No se eliminan archivos de main. Los respaldos `.env.save`, `backend/.env.save` y `frontend/test-results/` pertenecían al entorno y no se incluyen. Tampoco se versiona `.env`.

### Archivos modificados en ambas ramas desde el ancestro (evaluados para conflictos)

- `.dockerignore`
- `.gitignore`
- `README.md`
- `backend/.env.example`
- `backend/app/Http/Controllers/Api/V1/Legajos/DocumentoLegajoController.php`
- `backend/app/Http/Controllers/Api/V1/Legajos/ExpedienteController.php`
- `backend/app/Http/Controllers/Api/V1/Legajos/Seccion01Controller.php`
- `backend/app/Http/Controllers/Api/V1/UsuarioController.php`
- `backend/app/Models/Legajo.php`
- `backend/app/Models/Usuario.php`
- `backend/app/Providers/AppServiceProvider.php`
- `backend/app/Support/Documentos/CargaDocumento.php`
- `backend/config/permisos.php`
- `backend/database/factories/SeccionLegajoFactory.php`
- `backend/phpunit.xml`
- `backend/routes/api.php`
- `backend/tests/Feature/AuthenticationTest.php`
- `backend/tests/Feature/ResourceApiTest.php`
- `backend/tests/Feature/Seccion10ApiTest.php`
- `docker-compose.yml`
- `frontend/package.json`
- `frontend/pnpm-lock.yaml`
- `frontend/src/app/App.jsx`
- `frontend/src/config/navigation.js`
- `frontend/src/features/auth/login_principal.jsx`
- `frontend/src/features/dashboard/DashboardPage.jsx`
- `frontend/src/features/legajos/components/GeneralDataEditor.jsx`
- `frontend/src/features/legajos/components/documentos/ListaDocumentos.jsx`
- `frontend/src/features/legajos/components/seccion01/Campo.jsx`
- `frontend/src/features/legajos/components/seccion03/Seccion03Panel.jsx`
- `frontend/src/features/legajos/components/seccion04/Seccion04Panel.jsx`
- `frontend/src/features/legajos/components/seccion06/Seccion06Panel.jsx`
- `frontend/src/features/legajos/components/seccion08/Seccion08Panel.jsx`
- `frontend/src/features/legajos/components/seccion10/Seccion10Panel.jsx`
- `frontend/src/features/legajos/config/legajoSections.js`
- `frontend/src/features/legajos/pages/LegajosPage.jsx`
- `frontend/src/features/users/pages/UsersPage.jsx`
- `frontend/src/layouts/Sidebar.jsx`

### Archivos creados

- `backend/app/Console/Commands/ControlVencimientos.php`
- `backend/app/Console/Commands/EnviarAvisosVencimiento.php`
- `backend/app/Console/Commands/ProcesarVencimientosInstituciones.php`
- `backend/app/Http/Controllers/Api/V1/AccesoSeccionesController.php`
- `backend/app/Http/Controllers/Api/V1/IncorporacionController.php`
- `backend/app/Http/Controllers/Api/V1/ProximosVencimientosController.php`
- `backend/app/Http/Controllers/Api/V1/VencimientoLegajoController.php`
- `backend/app/Http/Middleware/AccesoSecciones.php`
- `backend/app/Http/Middleware/ExigirVigenciaLegajo.php`
- `backend/app/Models/AvisoVencimiento.php`
- `backend/app/Models/CicloVigencia.php`
- `backend/app/Models/EnvioVencimiento.php`
- `backend/app/Models/Incorporacion.php`
- `backend/app/Models/PresentacionRenovacion.php`
- `backend/app/Models/RenovacionLegajo.php`
- `backend/app/Support/AccesoLegajo.php`
- `backend/app/Support/AvisosVencimiento.php`
- `backend/app/Support/ConsultaVencimientos.php`
- `backend/app/Support/Documentos/Antivirus.php`
- `backend/app/Support/VencimientoVinculo.php`
- `backend/app/Support/VigenciaLegajo.php`
- `backend/config/antivirus.php`
- `backend/config/vencimientos.php`
- `backend/database/factories/AvisoVencimientoFactory.php`
- `backend/database/factories/CicloVigenciaFactory.php`
- `backend/database/factories/EnvioVencimientoFactory.php`
- `backend/database/factories/IncorporacionFactory.php`
- `backend/database/factories/PresentacionRenovacionFactory.php`
- `backend/database/factories/RenovacionLegajoFactory.php`
- `backend/database/migrations/2026_10_09_181402_create_incorporaciones_and_section_access.php`
- `backend/database/migrations/2026_10_09_192514_create_vencimiento_legajo_tables.php`
- `backend/tests/Feature/AccesoSeccionesTest.php`
- `backend/tests/Feature/IncorporacionTest.php`
- `backend/tests/Feature/ProximosVencimientosTest.php`
- `backend/tests/Feature/VencimientoLegajoTest.php`
- `docs/incorporacion-y-acceso.md`
- `docs/revision-integracion-paneles.md`
- `docs/vencimiento-legajo.md`
- `frontend/src/components/ui/Modal.jsx`
- `frontend/src/features/incorporation/IncorporacionPage.jsx`
- `frontend/src/features/legajos/pages/MiLegajoPage.jsx`
- `frontend/src/features/requirements/ProximosVencimientos.jsx`
- `frontend/src/features/requirements/VencimientosPage.jsx`
- `frontend/src/features/settings/SectionAccessPage.jsx`

### Archivos modificados

- `README.md`
- `backend/app/Http/Controllers/Api/V1/Legajos/ExpedienteController.php`
- `backend/app/Http/Controllers/Api/V1/Legajos/Seccion02Controller.php`
- `backend/app/Http/Controllers/Api/V1/RequerimientoDocumentoController.php`
- `backend/app/Models/Legajo.php`
- `backend/app/Models/NotificacionUsuario.php`
- `backend/app/Models/RequerimientoDocumento.php`
- `backend/app/Models/SeccionLegajo.php`
- `backend/app/Models/Usuario.php`
- `backend/app/Providers/AppServiceProvider.php`
- `backend/config/permisos.php`
- `backend/phpunit.xml`
- `backend/routes/api.php`
- `backend/routes/console.php`
- `backend/tests/Feature/AuthenticationTest.php`
- `backend/tests/Feature/BanderasTresEstadosTest.php`
- `backend/tests/Feature/ResolverInstitucionTest.php`
- `backend/tests/Feature/ResourceApiTest.php`
- `frontend/src/app/App.jsx`
- `frontend/src/config/navigation.js`
- `frontend/src/features/dashboard/DashboardPage.jsx`
- `frontend/src/features/legajos/pages/LegajosPage.jsx`
- `frontend/src/layouts/Topbar.jsx`
- `frontend/src/shared/queries/useLiveQuery.js`

## 8. Comprobaciones y límites

- PostgreSQL temporal: 44 pruebas de acceso, incorporación, vencimiento docente y consulta unificada aprobadas, con 324 aserciones. La base temporal se eliminó al terminar; las bases de trabajo del usuario no se tocaron.
- SQLite, regresión enfocada: 104 pruebas aprobadas, con 1077 aserciones, incluyendo autenticación, banderas, recursos, incorporación y consulta unificada.
- Scheduler con instituciones SQLite temporales: aprobado dentro de una ejecución de 16 pruebas, 119 aserciones. Comprueba dos instituciones activas, exclusión de una suspendida y restauración de contexto.
- Frontend: 104 pruebas existentes aprobadas; lint y build aprobados. El build avisa de bundles superiores a 500 kB; la división adicional de bundles queda como mejora de rendimiento.
- Pint aplicado a los archivos PHP cambiados. `--dirty` no funciona dentro de Docker porque `.git` no está montado: se pasó la lista explícita de archivos a `pint --format agent`.
- La primera suite completa detectó nueve fallos: expectativas de roles, fixture de acceso sensible de asistente y clasificación de los nuevos modelos sin CRUD genérico. Se corrigieron y la ejecución enfocada pasó. **La repetición final completa pasó: 682 pruebas y 7911 aserciones (SQLite).**
- No se ejecutó una prueba visual de navegador ni una auditoría automatizada de accesibilidad. Quedan como comprobaciones manuales: teclado/foco, contraste real, adaptación a pantallas pequeñas, carga lenta, reintento y estados vacíos.

### Commits de código en main

- `a4f4a0c` — acceso, incorporación y renovación con aislamiento institucional.
- `9d79086` — consulta unificada de ciclos, contratos y documentos, filtros y pruebas.

La integración es selectiva: no marca toda la rama feature como fusionada ni incorpora sus cambios operativos ajenos al alcance. Para continuar esa rama, revisar únicamente sus cambios posteriores y evitar un merge íntegro que reintroduzca versiones antiguas.

## 9. Preparación de la instalación

Después de incorporar los commits, ejecutar desde la raíz:

```sh
docker compose exec app php artisan migrate --no-interaction
docker compose exec app php artisan plataforma:migrar-instituciones --no-interaction
```

Cerrar sesión y entrar de nuevo. Abrir `http://uniq.localhost:5173/incorporacion`, `/accesos` o `/vencimientos` con los permisos correspondientes. No hace falta repetir seeders demo. El scheduler del despliegue debe seguir activo. Las notificaciones automáticas continúan limitadas a ciclos docentes; contratos y documentos se incorporan a la consulta sin activar correos nuevos.

## 10. Riesgos y mitigaciones

| Riesgo | Mitigación / estado |
| --- | --- |
| Perder avances de main | Adaptación selectiva, conservación explícita de plataforma y configuración; regresión automatizada. |
| Mezclar instituciones | Contexto por institución y prueba de scheduler con bases separadas. |
| Filtrar información sensible | Autorización del servidor y pruebas con asistente y titular. |
| Confundir días y reglas docentes | Rango local de Lima y pruebas en 0, 6, 7 y 30; gestión docente separada y documentada. |
| Interpretar un registro sin fecha como vigente | El listado no inventa fechas; vacío explicado en la interfaz. |
| Omitir fechas de tablas especializadas | El documento usa el campo común de caducidad; ampliar fuentes requiere definición explícita posterior. |
| Deuda en controladores y JSON crecientes | Mantener el comportamiento; refactorización y medición separadas de esta integración. |
| Tamaño del frontend | Build válido con aviso de bundles grandes; evaluar carga diferida como mejora posterior. |
| Alterar datos del usuario | No se aplicaron migraciones de trabajo; comandos de preparación documentados. |
| Accesibilidad visual sin validar en navegador | Labels, foco y semántica implementados; comprobación manual pendiente. |
