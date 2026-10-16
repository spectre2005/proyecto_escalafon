# Contrato de API v1

Base `/api/v1`. Respuestas JSON salvo descargas de archivo. Autenticación SPA por
cookies Sanctum: obtener `/sanctum/csrf-cookie`, enviar credenciales en `/auth/login`
y conservar cookie de sesión y cabecera `X-XSRF-TOKEN` en escrituras. El nombre de
la cookie depende de la configuración del despliegue. No se usan bearer tokens.

## Respuestas y errores

- Recurso: `{ "data": { ... } }`. Colección paginada: `data`, `links`, `meta`.
- Validación: HTTP 422, `message` y `errors` por campo. No reintentar sin corregir.
- HTTP 401: sesión inválida; 419: CSRF/sesión expirada; recuperar sesión.
- HTTP 403: permiso, sección desactivada o entorno no habilitado.
- HTTP 404: recurso inexistente o no visible; no inferir existencia de información sensible.
- HTTP 405: operación sin ruta, incluidos cambios en registros históricos.
- HTTP 409: conflicto de estado, versión o unicidad. Recargar antes de repetir.
- HTTP 423: archivo en cuarentena. No se entrega su contenido.
- HTTP 429: límite de acceso; respetar Retry-After cuando esté presente.
- HTTP 5xx o timeout: resultado de escritura no confirmado; recargar antes de repetir.

Las lecturas de archivos verifican titular, permiso y sección antes de servir PDF
privado y registrar acceso. No se publican rutas del almacenamiento como enlaces.

## Contratos principales

| Endpoint | Entrada | Resultado y permisos |
|---|---|---|
| POST `/auth/login` | email válido, password string; ambos obligatorios, máximo 255 caracteres | data.usuario, data.permisos, data.ve_datos_sensibles; errores de credenciales genéricos; límite de intentos |
| GET `/auth/me` | Cookie | Sesión y permisos actuales; cuenta sin permisos rechazada |
| POST `/auth/logout` | Cookie y CSRF | Sesión invalidada |
| GET `/usuarios` | page entero >=1; per_page 1–100; q texto <=255; tipo_personal todos/docente/administrativo; estado todos/activo/inactivo | Colección paginada y resumen global; personal.ver; q trata % y _ literalmente |
| GET `/usuarios/resumen` | Sin parámetros | data: total, activos, inactivos, docentes, administrativos, con_correo; personal.ver; sin filas personales |
| POST `/usuarios` | usuarios: lista de 1–1000 registros | 201; usuarios.gestionar; transacción por lote y cuentas inicialmente inactivas |
| GET `/expedientes/{usuario}` | UUID del titular | Ficha con información filtrada según permisos |
| GET `/expedientes/{usuario}/seccion-NN` | UUID; sección según ruta | Datos y documentos de la sección; permisos adicionales en middleware/controlador |
| POST `/expedientes/{usuario}/documentos` | multipart: pdf, seccion_legajo_id, tipo_documento_id, folio_inicio, folio_fin, fecha_incorporacion, emisor, fecha_emision, titular_nombre, titular_documento; modalidad y datos adicionales según tipo | 201; documento.subir; folios correlativos; corrección mediante subsanación |
| GET `/expedientes/{usuario}/documentos/{documento}/archivo` | UUIDs; accion opcional ver/descargar | PDF privado o 423; legajo.ver y controles sensibles del tipo/sección |
| POST `/expedientes/{usuario}/documentos/{documento}/recepcionar` | UUIDs y CSRF | Recepción única; documento.recepcionar |
| GET `/acceso-secciones` | Cookie | Configuración de secciones y versión |
| PUT `/acceso-secciones` | secciones y If-Match con versión obtenida | sistema.administrar; 409 si versión cambió |
| GET `/vencimientos` | Filtros definidos en VencimientoLegajoController | Vista paginada, corte y resumen; permisos de vencimientos o renovación propia |
| POST `/vencimientos/ciclos/{ciclo}/notificar` | UUID y CSRF | Aviso/reintento controlado; 409 si existe pendiente, en proceso o incierto |

Cada usuario de POST /usuarios requiere DNI de 8 dígitos, nombres (120), apellido
paterno (80), email institucional (255), tipo_personal y régimen compatible.
Apellido materno y teléfono son opcionales; teléfono presente debe tener 9 dígitos.
DNI y correo no pueden repetirse; el correo se normaliza a minúsculas.

## Inventario completo y cambios

`api-contract.json` contiene todas las rutas, acciones, restricciones y middleware,
y hashes de controladores y fuentes de validación. Incluye CRUD de desarrollo y
rutas funcionales; el inventario no habilita ninguna de ellas.

Generar desde la raíz del repositorio:

```sh
docker compose run --rm --no-deps app php artisan api:contrato > docs/api-contract.json
```

En CI, con `docs` montado como solo lectura:

```sh
php artisan api:contrato --check=/var/www/docs/api-contract.json
```

El inventario detecta cambios del código fuente; no sustituye esquemas detallados
por operación ni pruebas funcionales. Queda pendiente completar esos esquemas para
los formularios especializados. No se presenta este archivo como OpenAPI completo.
