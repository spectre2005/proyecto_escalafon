# ESTADO_PROYECTO.md

Documento vivo. Se actualiza al cerrar cada tarea y se vuelve a subir al proyecto. Última actualización: 30/09/2026.

## Estado del frontend

Carpetas existentes en `src/`: `app/App.jsx`, `assets`, `components/ui/Icon.jsx`, `config/institution.js` y `navigation.js`, `features/auth/login_principal.jsx`, `features/dashboard/DashboardPage.jsx`, `features/settings`, `features/users/` (UsersPage, useUsers, usuariosApi, usuariosCsv), `layouts/` (AdminLayout, NavItem, Sidebar, Topbar).

Patrón de cada módulo: `Page` + hook `useX` + `xApi.js`. Sin `react-router` confirmado. Tailwind confirmado.

**Módulo `features/legajos/`** (generado, aún sin probar en el proyecto real): `LegajosPage`, `LegajoDetalle`, `SeccionAcordeon`, `DocumentoFila`, `Badge`, `EstadoBadge`, `Iconos`, `estilos.js`, `useLegajos`, `legajosApi` (datos de ejemplo), `legajosMock`, `constants`.
- Pendiente: reemplazar `Iconos.jsx` por `Icon.jsx` real, quitar "Próximo" en `navigation.js`, conectar en `App.jsx`, ajustar tablas al estilo de `UsersPage`.
- `estilos.js` concentra los colores; si hay tokens de Tailwind, cambiarlos ahí.

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

1. Sección 12 no tiene formulario de datos en el SQL: ¿solo PDF o falta tabla?
2. "Evaluaciones" está en el menú del Figma: ¿entra al proyecto?
3. Fórmula de completitud del legajo (¿cuenta cargados o solo verificados?).
4. ¿El asistente ve datos sensibles?
5. Portal del servidor: ¿ahora o después?
6. Una base por cliente (recomendada) o compartida.
7. Fedateo: ¿solo un check o se sube evidencia?

## Siguientes pasos

1. Integrar el módulo de legajos al panel real (necesita `navigation.js`, `App.jsx`, `Icon.jsx`, `UsersPage.jsx`).
2. Formulario "Subir documento" con los metadatos obligatorios y folios sugeridos (`ultimo_folio + 1`).
3. Acción "Recepcionar" y visor de PDF.
4. Bandeja de requerimientos y observaciones.
5. Endpoints en Laravel para reemplazar los datos de ejemplo.

## Historial

- 30/09/2026: se definió el panel principal y la sección Legajos (mockups), y se generó el módulo `features/legajos/` con datos de ejemplo.
