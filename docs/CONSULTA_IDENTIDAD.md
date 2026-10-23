# Consulta de identidad por DNI (registro manual)

Estado (21/10/2026): proveedores **simulado** (datos ficticios, solo local y pruebas) y **Factiliza** (servicio
externo de pago, NO oficial; aceptado por el equipo el 21/10/2026). No hay conexión con RENIEC ni con PIDE. La consulta
completa nombres y apellidos y, para quien ve datos sensibles, la ubicación del catálogo y el domicilio (22/10/2026), en
el paso 1 del registro manual, y **solo ahí**: ni la edición del legajo ni los
formularios de plataforma la usan. Es opcional: el registro manual funciona igual sin ella. La importación masiva no la
usa.

## Arquitectura

| Pieza | Archivo | Responsabilidad |
|---|---|---|
| Contrato | `backend/app/Support/Identidad/ConsultaDni.php` | `nombre()`, `esOficial()`, `esSimulado()`, `consultar($dni)` → `ResultadoIdentidad`; lanza `ProveedorNoDisponible` si falla |
| Resultado | `ResultadoIdentidad.php` | `nombres`, `apellidoPaterno`, `apellidoMaterno` y, opcionales, `ubicacion` (departamento, provincia y distrito tal como los informa el proveedor) y `direccion`, o «sin coincidencia» |
| Ubicación | `UbicacionCatalogo.php` | Empareja esa ubicación con la tabla `ubigeo` de la institución (ver «Ubicación») |
| Selección | `ProveedorIdentidad.php` | Lee `config('identidad.proveedor')`. Devuelve `null` (no disponible) si el valor es `ninguno`, desconocido, el simulado fuera de local/testing o Factiliza sin token |
| Simulado | `ConsultaDniSimulada.php` | Casos ficticios fijos, sin red; se niega a responder fuera de `local`/`testing` |
| Factiliza | `ConsultaDniFactiliza.php` | `GET https://api.factiliza.com/v1/dni/info/{dni}` con el token del servidor; solo toma nombres y apellidos |
| Configuración | `backend/config/identidad.php` | Proveedor, mapa de proveedores, tiempo de espera y límites |
| Endpoint | `ConsultaIdentidadController.php` | Permisos, validación, límite de consultas, auditoría y respuesta |
| Interfaz | `frontend/src/features/users/utils/consultaIdentidad.js` y `components/RegistroManual.jsx` | Consulta al completar el DNI (450 ms), botón para repetirla, estados, confirmación y avisos |

### Endpoints

Ambos están en el grupo `usuarios.gestionar`. El controlador exige además `legajo.abrir` y `legajo.editar`, los mismos
permisos del registro manual.

- `GET /api/v1/identidad/consulta-dni` → `{ habilitada, disponible, simulada }` (para mostrar el botón).
- `POST /api/v1/identidad/consulta-dni` con `{ "dni": "########" }`. El DNI va en el cuerpo, nunca en la URL.
  Cualquier otro campo (institución, slug, conexión, proveedor…) se rechaza con 422.

| Caso | Respuesta |
|---|---|
| Coincidencia | 200 `{ encontrado: true, simulada, nombres, apellido_paterno, apellido_materno }` y, solo con `datos_sensibles.ver`, `ubicacion` (`{ id, region, provincia, distrito }` o `null`), `ubicacion_estado` y `direccion` (texto o `null`) |
| Sin coincidencia | 200 `{ encontrado: false, simulada }` |
| DNI inválido o campo extra | 422 (errores de validación) |
| Sin permiso | 401 sin sesión / 403 |
| Módulo desactivado en la institución | 403 `codigo: modulo_desactivado` |
| Sin proveedor configurado (o simulado bloqueado) | 503 `codigo: proveedor_no_configurado` |
| Proveedor caído, error o tiempo agotado | 503 `codigo: proveedor_no_disponible` |
| Límite alcanzado | 429 `codigo: limite_consultas` + `Retry-After` |

La consulta no crea ni guarda personas. `POST /usuarios/con-vinculo` no cambió y no depende de la consulta.

## Disponibilidad por institución

- **Módulo de la institución:** `consulta_dni` en `ModulosSistema::CATALOGO`, el mismo mecanismo que «Evaluaciones».
  - Se activa o desactiva en Configuración (o desde el panel de plataforma), con motivo y auditoría.
  - Igual que los demás módulos, arranca **activo**.
  - El backend lo exige con `ModuloSistemaActivo:consulta_dni`.
  - La institución es siempre la de la sesión (subdominio); el cliente no puede elegir otra.
- **Proveedor:** se elige en el servidor (`IDENTIDAD_PROVEEDOR`). Es independiente del módulo: que el módulo esté
  activo no significa que haya conexión con RENIEC. Si no hay proveedor, la pantalla dice «La consulta de identidad no
  está disponible. Completa los datos manualmente.» y no muestra el botón.

## Comportamiento en el formulario

- Al completar los 8 dígitos del DNI, la consulta se lanza sola tras 450 ms (si el DNI cambia antes, no se envía).
  Cada DNI se consulta solo una vez de forma automática; el botón «Consultar datos de identidad» permite repetirla.
- Mientras consulta muestra «Consultando…» y el formulario sigue editable.
- Si los campos están vacíos, se completan; si ya tienen otro texto, se pide confirmación antes de reemplazarlo.
- Los campos completados siguen editables. Nada se guarda hasta pulsar «Registrar personal».
- Al cambiar el DNI se aborta la consulta en curso; una respuesta tardía de otro DNI se descarta.
- Si no hay coincidencia, el DNI es inválido, el proveedor falla o se alcanzó el límite, se muestra un mensaje y el
  registro sigue manual.

## Proveedor Factiliza

Servicio externo **de pago y no oficial** (no es RENIEC). Se activa con `IDENTIDAD_PROVEEDOR=factiliza` y
`FACTILIZA_TOKEN` en el `.env` del servidor.

- El token solo vive en el entorno del servidor (`config('identidad.factiliza.token')`). Nunca en el repositorio, en el
  frontend (no usar variables `VITE_`), en los logs ni en la auditoría. Sin token, la consulta figura como no
  disponible y `produccion:verificar` falla.
- **Factiliza recibe el DNI** (en la ruta de la URL) cada vez que se consulta: es un envío de datos personales a un
  tercero. `produccion:verificar` lo advierte.
- Petición: `GET https://api.factiliza.com/v1/dni/info/{dni}` con `Authorization: Bearer`, sin redirecciones, conexión
  de 3 s y espera total de `IDENTIDAD_TIEMPO_ESPERA` (8 s).
- Respuesta válida: `success: true` y `data.numero` igual al DNI consultado, con `nombres` y `apellido_paterno` no vacíos
  (máx. 120 y 80 caracteres). `apellido_materno` es opcional. `departamento`, `provincia` y `distrito` (máx. 100) se
  toman solo si vienen los tres; `direccion` (máx. 250, como el domicilio) si trae texto. Todo lo demás se descarta:
  `fecha_nacimiento`, `sexo`, ubigeos…
- **Comprobado el 22/10/2026 con el plan contratado** (una consulta autorizada, sin registrar valores): la respuesta trae
  todos esos campos, pero solo nombres, apellidos y `direccion` vienen con datos; `departamento`, `provincia`,
  `distrito`, los ubigeos y `fecha_nacimiento` llegan vacíos. Por eso, con ese plan, la ubicación y la fecha de
  nacimiento se ingresan a mano y el formulario avisa que el servicio no informó la ubicación.
- 404 = sin coincidencia. Conexión fallida, tiempo agotado, 401/403 (token inválido o revocado), 429 (límite del plan),
  5xx o respuesta con otra forma o de otro DNI = `ProveedorNoDisponible` (503 `proveedor_no_disponible`). El log solo
  lleva el código HTTP, nunca el DNI, la URL, el cuerpo ni el token.
- No se guarda nada: no hay tabla de identidades ni caché. Cada consulta llega al proveedor y cuenta en los límites.
- Las respuestas llevan `simulada: false`; la auditoría registra `proveedor: factiliza`, `oficial: false`.
- **Por verificar con el proveedor:** si responde 404 o `success: false` con 200 cuando no encuentra el DNI (hoy el
  segundo caso cuenta como respuesta inesperada), cuotas y costo del plan, y el tratamiento de datos (Ley 29733).

## Ubicación

- La propone el proveedor (departamento, provincia y distrito) y `UbicacionCatalogo` la busca en la tabla `ubigeo` de la
  institución, comparando sin mayúsculas, tildes ni espacios repetidos. Solo se usa una **coincidencia única de los
  tres niveles**; sin coincidencia (`sin_coincidencia`), con varias (`ambigua`) o sin el dato (`sin_dato`), el formulario
  pide elegirla a mano. No se aproxima ni se inventan códigos.
- Es un dato sensible: sin `datos_sensibles.ver` la respuesta no trae `ubicacion` ni `ubicacion_estado` y el catálogo ni
  se consulta.
- En el formulario, la ubicación elegida a mano prevalece; la que trajo una consulta se descarta al cambiar el DNI, y
  una respuesta tardía de otro DNI no se aplica. Si el proveedor no la envió, se avisa. Nada se guarda hasta pulsar
  «Registrar personal».
- **Domicilio:** la `direccion` del proveedor se propone en el domicilio, con las mismas reglas que los nombres (rellena
  si está vacío, pide confirmación antes de reemplazar y se marca como dato de la consulta). Al cambiar el DNI se borra
  si sigue siendo el de la consulta anterior. No se deduce el distrito a partir de la dirección.
- La lógica de emparejamiento se tomó de `origin/feature/accesos-dni`, sin su tabla `identidades_dni`: no se guarda
  ninguna identidad consultada.

## Modo simulado

`backend/.env.example` trae `IDENTIDAD_PROVEEDOR=ninguno`. El simulado se activa a propósito
(`IDENTIDAD_PROVEEDOR=simulado`) solo en un entorno de demostración aislado, nunca en una instalación conectada a bases
con datos reales. Casos de demostración (personas ficticias):

| DNI | Resultado |
|---|---|
| `00000001` | Ana Lucía Demostración Ficticia (ubicación CUSCO / LA CONVENCION / SANTA ANA, del catálogo nacional, y dirección ficticia) |
| `00000002` | Carlos Alberto Prueba Simulado |
| `00000003` | María Ejemplo (sin apellido materno) |
| `00000004` | Lucía Fernanda Muestra Simulada (los tres campos; ubicación ficticia que no está en ningún catálogo) |
| `00000009` | Simula un servicio caído (503) |
| cualquier otro | Sin coincidencia: no se inventan nombres |

Por qué no es una consulta oficial:

- No usa la red ni envía el DNI a ningún servicio.
- Solo conoce esos casos y no acredita la identidad de nadie.
- Cada respuesta lleva `simulada: true`. La pantalla muestra «Simulación: datos ficticios, no consultados a RENIEC» y
  marca los campos como «Dato simulado (ficticio)».

Bloqueo en producción (falla cerrada):

- `ProveedorIdentidad` no lo entrega fuera de `APP_ENV` `local` o `testing`, incluido un entorno vacío o desconocido.
- La propia clase se niega a responder fuera de esos entornos.
- `php artisan produccion:verificar` rechaza `IDENTIDAD_PROVEEDOR=simulado`.
- `.env.produccion.example` trae `ninguno`.

## Auditoría y datos personales

- Cada consulta que llega al proveedor deja un evento en `auditoria_eventos` con `App\Support\Auditoria`:
  - `accion` = `identidad.consultar_dni`, `entidad` = `consultas_identidad`.
  - La cadena de hash es por usuario: `entidad_id` = quien consulta.
  - En `datos_despues`: resultado, proveedor, `oficial` y `simulada`, institución (slug), `dni_parcial` (`*****678`) y
    `dni_huella` (HMAC-SHA256 del DNI con la `APP_KEY`).
  - No se guarda el DNI completo ni los nombres devueltos. Con la huella se puede comprobar si se consultó un DNI
    concreto, sin guardarlo legible.
- No se auditan las peticiones rechazadas antes de consultar: validación, permiso, módulo, falta de proveedor o límite.
- Los logs técnicos solo llevan el nombre del proveedor y la clase del error, nunca el DNI ni el mensaje del error (el
  de un cliente HTTP puede incluir la URL).
- Límite de consultas: 10 por minuto por usuario y 60 por minuto por institución (configurable).

## Pruebas

```
docker compose exec app php artisan test --filter=ConsultaIdentidad
docker compose exec app php artisan test --filter=ProduccionTest
docker compose exec frontend pnpm test
```

Las pruebas del backend usan `Http::preventStrayRequests()`, así que ninguna sale a la red; las de Factiliza
(`ConsultaIdentidadFactilizaTest`) responden con `Http::fake`, DNI ficticios y un token ficticio. `phpunit.xml` fuerza
`IDENTIDAD_PROVEEDOR=ninguno` y `FACTILIZA_TOKEN` vacío, y cada prueba elige su proveedor.

## Conectar un proveedor oficial (pendiente)

**Nada de lo siguiente está verificado.** No hay URL, protocolo, campos ni requisitos de RENIEC o PIDE confirmados en
este proyecto. Antes de escribir código hace falta:

1. **Acceso autorizado de la universidad.** Convenio o acceso a PIDE o al servicio que corresponda, según sea pública
   o privada. Pendiente de confirmar con el cliente.
2. **Documentación técnica oficial:** endpoint, autenticación, formato de petición y respuesta, códigos de error,
   límites de uso y registro de consultas exigido.
3. **Credenciales de prueba y de producción**, entregadas por un canal seguro.

Implementación prevista (PROPUESTO):

- **Clase nueva** que implemente `ConsultaDni`, con `esOficial() = true`. Debe:
  - Hacer la llamada con el cliente HTTP de Laravel, con el tiempo de espera de `identidad.tiempo_espera`.
  - Convertir cualquier fallo en `ProveedorNoDisponible`, sin el DNI en el mensaje.
- **Registro en la configuración:** se agrega en `config/identidad.php` → `proveedores` y se selecciona con
  `IDENTIDAD_PROVEEDOR`. El controlador y el frontend no cambian.
- **Mapeo:** solo nombres o prenombres → `nombres`, apellido paterno → `apellido_paterno` y apellido materno →
  `apellido_materno`. Los demás datos que devuelva el servicio se descartan. Cómo se llaman esos campos en la
  respuesta real está por verificar.
- **Credenciales:**
  - Solo en el servidor (variables de entorno o un almacén de secretos), nunca en el frontend, en el repositorio ni en
    los logs.
  - Si cada universidad tiene las suyas, irán cifradas en su configuración, no en `parametros_sistema` (texto plano,
    200 caracteres).
  - Decidir quién aporta las credenciales: la plataforma o cada universidad.
- **Antes de habilitarlo en producción:**
  - Pruebas con `Http::fake` de la respuesta documentada.
  - Error, tiempo agotado y respuesta malformada.
  - Que el DNI no aparezca en logs ni excepciones.
  - Límites acordes al convenio.
  - Prueba en el entorno de pruebas del proveedor.
  - Revisión del tratamiento de datos (Ley 29733) y del registro de consultas que exija el convenio.
