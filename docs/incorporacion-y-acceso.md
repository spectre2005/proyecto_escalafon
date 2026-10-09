# Incorporación y acceso al legajo

Se conservan las 13 secciones y sus identificadores existentes, según la confirmación del usuario. «Relaciones laborales» corresponde a la sección 09; «Desvinculación», a la 11. No se renumeran documentos ni se separa información personal/familiar.

## Flujo funcional

| Actor | Acciones |
|---|---|
| Administrador | Registrar una persona nueva o seleccionar una existente, definir documentos exigidos, asignar responsable y tareas, revisar PDF cargados por otro usuario, observar, rechazar, admitir, incorporar y configurar secciones. |
| Responsable delegado con `legajo.editar` | Consultar sus incorporaciones asignadas, cargar/subsanar PDF, completar datos de resolución, marcar tareas y enviar a revisión. La asignación no concede admisión, activación ni administración. |
| Personal incorporado con rol `personal` | Iniciar sesión y consultar únicamente su propio legajo. No puede listar el personal ni modificar expedientes. |

El tablero tiene cuatro columnas: Registrado, En revisión, Observado y Aceptado · por activar (`admitido`). Los incorporados y rechazados se consultan mediante la vista de archivo. Filtros por nombre/DNI, tipo de personal y cargo; paginación de 40 registros. Los contadores de columna corresponden a la página visible.

1. El administrador selecciona una persona existente o registra nuevo personal con cuenta inactiva. No se crea otro usuario cuando se selecciona uno existente. Solo se admite un expediente de incorporación por persona.
2. Configura una lista de requisitos documentales por puesto. Los tres nombres sugeridos son editables; no constituyen requisitos legales universales.
3. Puede delegar la carga y el registro de datos. El responsable solo accede al expediente asignado; una nueva asignación reemplaza sus tareas actuales y queda registrada en el historial.
4. Los PDF se guardan en almacenamiento privado, con hash, fecha y autor. Las subsanaciones conservan las versiones anteriores. Un documento revisado no admite reemplazo.
5. En revisión, el administrador marca cada documento como revisado o rechazado. Quien lo cargó no puede revisarlo. Los rechazos y observaciones exigen motivo.
6. «Marcar como Admitido» exige todos los documentos revisados, tareas completas, número/fecha de resolución e inicio del vínculo.
7. «Aceptar e incorporar» exige el estado admitido y un rol activo reconocido por el sistema. Para una cuenta inactiva también exige una contraseña válida. La contraseña se guarda mediante hash y no se devuelve ni entra al historial; su entrega es manual por el canal institucional.
8. La activación, asignación de rol, vínculo y apertura/reutilización del legajo se ejecutan en una transacción. Si ya existe un vínculo abierto, debe coincidir con la resolución, cargo e inicio; de lo contrario se informa el conflicto. Un legajo activo se reutiliza y conserva sus documentos.

Las escrituras llevan una versión del expediente; una petición atrasada recibe HTTP 409. Incorporado y Rechazado son estados cerrados. El historial identifica acción, actor, fecha, motivo y detalles de las decisiones.

## Secciones activas

`/accesos` contiene los trece interruptores, selector de disponibilidad, vista previa del menú y explicación del guardado. Guardar aplica los cambios a todo el equipo; Restaurar descarta el borrador y consulta la configuración persistida. Se usa `If-Match` para evitar sobrescribir cambios de otro administrador.

- El interruptor controla la visibilidad y el acceso a categorías/documentos. Desactivar no borra datos.
- `siempre_activa` y `periodo_pendiente` son etiquetas de disponibilidad. No existe todavía un calendario automático de apertura/cierre; «Período pendiente» no define fechas.
- Se conserva inicialmente la disponibilidad de las secciones existentes. Las secciones 09, 11 y 12 quedan etiquetadas como período pendiente.
- En 09, únicamente se permite afiliación de sindicato y su tipo documental `S09_C_AFILIACION_SINDICATO`. Las controversias individuales/colectivas y «otros» se bloquean también en API, consultas genéricas y descargas, incluso para administradores.
- Los permisos de datos sensibles continúan aplicándose. El personal solo puede leer datos de su propio expediente.
- Una URL guardada de una sección desactivada muestra una indicación para elegir una sección activa.

## Estructura de datos

Se añaden `activa` y `disponibilidad` a `secciones_legajo`. La tabla `incorporaciones` referencia al usuario, responsable, autor y vínculo; mantiene estado, cargo, resolución, fechas, versión y los JSON de requisitos, tareas e historial.

Ejemplo de configuración (cada guardado incluye las 13 secciones):

```json
{
  "secciones": [
    {"id": 1, "activa": true, "disponibilidad": "siempre_activa"},
    {"id": 9, "activa": true, "disponibilidad": "periodo_pendiente"}
  ]
}
```

Ejemplo conceptual de incorporación; los identificadores reales son UUID:

```json
{
  "usuario_id": "uuid-persona-existente",
  "estado": "en_revision",
  "cargo": "Analista",
  "numero_resolucion": "R-123",
  "fecha_resolucion": "2026-10-01",
  "fecha_inicio": "2026-10-09",
  "responsable_id": "uuid-responsable",
  "version": 4,
  "documentos": [
    {"id": "uuid-requisito", "nombre": "Documento nacional de identidad", "estado": "cargado", "versiones": [{"nombre": "dni.pdf", "subido_por": "uuid-responsable"}]}
  ],
  "tareas": [{"id": "uuid-tarea", "titulo": "Cargar documentos", "completada": false}]
}
```

Las rutas y hashes privados de los PDF no se incluyen en las respuestas. Los PDF de incorporación se conservan en su expediente de admisión; no se asignan automáticamente a un tipo documental ni a folios del legajo, porque la lista de requisitos es configurable. La carga formal al legajo usa los formularios documentales existentes.

## Alcance de firma y operación

«Solicitar firma» registra la solicitud, fecha y autor para seguimiento interno. No envía correos ni implementa una firma digital externa; la interfaz informa que debe coordinarse la firma.

Migración: `2026_10_09_181402_create_incorporaciones_and_section_access`. Añade la estructura y los roles `personal` y `asistente_legajo` si faltan, sin cambiar roles existentes. Su reversión conserva los roles para no afectar asignaciones posteriores.

La API mantiene la restricción previa a entornos `local`/`testing`; este cambio no constituye un despliegue de producción.

## Archivos principales

- Backend: `IncorporacionController`, `AccesoSeccionesController`, middleware `AccesoSecciones`, soporte `AccesoLegajo`, modelo/factory `Incorporacion` y la nueva migración.
- Integración backend: `ExpedienteController`, `Usuario`, `SeccionLegajo`, `AppServiceProvider`, `config/permisos.php` y `routes/api.php`.
- Frontend: `features/incorporation/IncorporacionPage.jsx`, `features/settings/SectionAccessPage.jsx`, rutas, navegación, menú del legajo, inicio y barra de búsqueda.
- Consultas: `shared/queries/useLiveQuery.js` admite consultas deshabilitadas para no enviar peticiones sin URL o sin permiso.
- Pruebas: incorporación, acceso, autenticación, recursos genéricos y secciones 01/09; configuración PHPUnit para aislar caché/sesión de Docker.

## Preparar al equipo de trabajo

Si solo hay una cuenta operadora, en Incorporación utiliza **Habilitar asistente**. Selecciona personal con un legajo activo existente; para cuentas inactivas o sin permisos establece una contraseña inicial. El sistema asigna `asistente_legajo`, conserva el legajo y registra quién concedió el rol. Si la cuenta ya podía iniciar sesión, conserva su contraseña. Esta acción requiere administración y no permite que el asistente admita postulantes ni cambie la configuración.

Después asigna al asistente las tareas y la carga de documentos. El administrador revisa los PDF cargados por el asistente, completa la admisión e incorpora al postulante. No se habilita ninguna cuenta automáticamente durante la instalación.
