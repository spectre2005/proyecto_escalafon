# Baja, exportación y archivo de instituciones

> **DISEÑO PROPUESTO — NO IMPLEMENTADO.**
> Este documento describe cómo se haría la baja de una institución. Salvo la **entrega 1 (protección del estado)**,
> nada de lo que aquí se describe existe en el código. Las decisiones de la sección 8 **no están aprobadas**: requieren
> confirmación del cliente o del equipo antes de programarlas.

Fecha del análisis: 2026-10-17 (sobre `main` en `d0e8b88`).

## 1. Hallazgos técnicos

1. **`tar -h` en `respaldar.sh` sigue enlaces simbólicos.** El paquete de archivos de cada institución se crea con
   `tar -czhf`. En el entorno de desarrollo, `storage/app/private/instituciones/uniq/` tiene tres enlaces
   (`documentos`, `fotos-personal`, `certificados-discapacidad`) a las carpetas comunes `storage/app/private/*`. El
   respaldo de UNIQ las incluye; el panel de uso, en cambio, no las cuenta porque no son exclusivas de la institución.
   Detalle y propuesta en la sección 7.
2. **`ResolverInstitucion` bloqueaba estados por lista negra** (registrada → 404, suspendida → 403). Un estado nuevo
   (p. ej. `archivada`) o desconocido habría operado. **Corregido en la entrega 1** (lista blanca).
3. **Transiciones permisivas.** `motivoNoActivable()` dejaba activar desde cualquier estado distinto de `activa` y
   `motivoNoSuspendible()` dejaba suspender cualquier estado distinto de `registrada`/`suspendida`. **Corregido en la
   entrega 1**: un estado no reconocido no se activa ni se suspende.
4. **Con `PLATAFORMA_MODO=unica` no hay aislamiento.** Hay una sola institución (la base del `.env`) y
   `ResolverInstitucion` no actúa: ni la suspensión ni un futuro archivo bloquean el acceso. El panel ya lo advierte
   (`suspension_bloquea_acceso = false`). Archivar en modo `unica` debería rechazarse.
5. **El panel no puede generar ni servir una exportación hoy.** La imagen PHP de producción no trae `pg_dump`
   (solo `libpq-dev`); `respaldos/` está en el servidor, fuera de los volúmenes del contenedor `app`; los respaldos se
   hacen desde el servidor con `docker compose exec`.
6. **La verificación compara contra el `SHA256SUMS` de la misma carpeta**, no contra las huellas registradas en la
   base de la plataforma (`respaldos.base_sha256`, `archivos_sha256`). Además `SHA256SUMS` se genera con `|| true`.
7. **`respaldar.sh` es todo o nada** (todos los objetivos del plan) y borra las carpetas `AAAAMMDD-HHMMSS` de más de
   `RESPALDO_DIAS` (14): una exportación con ese nombre de carpeta se borraría sola.
8. **Lo que ya protege:**
   - `respaldos`, `periodos_servicio` y `auditoria_plataforma` tienen claves foráneas a `instituciones` sin borrado en
     cascada: la fila de una institución no se puede borrar.
   - `archivos_documento` guarda `storage_path`, `tamano_bytes` y `hash_sha256` de cada PDF, y `usuarios.foto_path`
     la de cada foto: se puede inventariar documento por documento.
   - `ColasInstitucion` ya usa `operativa()` (solo `activa`): un trabajo de una institución que no opera falla sin
     ejecutarse.
9. **Todos los superadministradores tienen los mismos permisos.** No hay roles en el panel.
10. **El resumen del panel** (`InstitucionController::resumen`) cuenta solo los estados de `Institucion::ESTADOS`; una
    institución con un estado no reconocido no entraría en el total. A revisar cuando se agregue `archivada`.

## 2. Cómo funcionan hoy los respaldos y la restauración

- **`plataforma:respaldo-plan`**: una línea por objetivo (`objetivo  base  carpeta`): la plataforma, cada institución
  aprovisionada (también suspendida) y la base `principal` solo si no es de ninguna institución. Solo emite nombres que
  cumplen los patrones del modelo (se usan en comandos de shell).
- **`docker/produccion/respaldar.sh`** (servidor, cron): `respaldos/AAAAMMDD-HHMMSS/<objetivo>/` con `base.dump`
  (`pg_dump -Fc` desde `db`), `archivos.tar.gz` (`tar -h` desde `app`) y `SHA256SUMS`. Bases no listadas → `otras/`.
  Registra cada resultado con `plataforma:registrar-respaldo` (los fallos también en la auditoría). Copia externa con
  rclone en Docker, solo a remotos `crypt` y nunca a la raíz. Conserva 14 días locales y 30 externos.
- **`restaurar.sh verificar`**: `SHA256SUMS`, restauración en una base temporal (siempre se borra), exige tabla
  `migrations` con filas, `tar -tzf` del paquete; registra la verificación.
- **`restaurar.sh restaurar`**: una institución que figure en el plan actual; verifica antes, pide escribir el
  objetivo, guarda el estado actual en `antes-de-restaurar-…/`, restaura con `--single-transaction`, aparta la carpeta
  actual (no la borra) y aplica migraciones.
- **Suspensión**: `InstitucionController::transicion()` con `lockForUpdate`, auditoría antes/después y motivo.
- **Aislamiento**: `ConexionInstitucion` cambia la base y reubica todos los discos de `storage/app/private` en
  `storage/app/private/{almacenamiento}`, además de caché y sesiones.
- **Aprovisionamiento**: si falla a mitad, retira solo lo creado en esa ejecución.

## 3. Componentes reutilizables

| Pieza | Uso en la baja | Ajuste |
|---|---|---|
| `respaldar_base` / `respaldar_archivos` (sh) | Volcado y paquete | Llevarlos a funciones comunes; en la exportación, `tar` sin `-h` (sección 7) |
| `restaurar.sh verificar` | Verificar la exportación | Comparar además con huellas registradas y con el inventario |
| `restaurar.sh restaurar` | Recuperar una archivada sin base | Aceptar una carpeta de exportación como origen |
| `PlanRespaldo` y patrones del modelo | Base y carpeta desde la base de datos, nunca desde la petición | Plan de un solo objetivo |
| `transicion()` + `lockForUpdate` + `AuditoriaOperaciones` | Archivar / recuperar | Nuevos estados y reglas |
| `VerificacionAprovisionamiento` | Comprobar base y carpeta al recuperar | Ninguno |
| `UsoInstitucion`, `servicio()`, `estadoRespaldo()` | Vista previa y comprobaciones | Ninguno |
| `ConfirmarEstado` (dialog), `Aviso`, `EstadoTecnico`, `EstadoRespaldo` | Interfaz | Ninguno |

No hace falta otro mecanismo de respaldo: la exportación es un respaldo de un objetivo, con inventario, verificación
más estricta y otra carpeta y otras reglas de conservación.

## 4. Cambios propuestos

**Base de la plataforma**
- `instituciones`: valor `archivada` en `estado`; columnas `archivada_en`, `motivo_baja`, `exportacion_id`.
- Tabla nueva `exportaciones_institucion` (solo crece): `id`, `institucion_id`, `solicitada_por`, `estado`
  (`solicitada`, `exportando`, `verificada`, `fallida`, `cancelada`, `interrumpida`), `carpeta`, `motivo`, tamaños y
  huellas de base, archivos y paquete, `cifrado`, resumen del inventario (solo cifras), `iniciada_en`, `terminada_en`,
  `verificada_en`, `detalle`.

**Backend**
- `ResolverInstitucion` en lista blanca — **hecho (entrega 1)**.
- `Institucion`: `motivoNoArchivable()`, `motivoNoRecuperable()`; activar no se permite desde `archivada`; suspender
  una archivada se rechaza (el rechazo de estados no reconocidos ya está hecho).
- `PlanRespaldo` / `MigrarInstituciones`: qué hacer con las archivadas (decisión 5).
- Comandos: `plataforma:exportacion-plan {slug}` (solo con una solicitud pendiente), `plataforma:registrar-exportacion`,
  `plataforma:inventario-institucion` (rutas relativas, tamaño y sha256 desde `archivos_documento` y `foto_path`, sin
  nombres ni DNI).
- Rutas: `GET instituciones/{id}/baja`, `POST instituciones/{id}/exportaciones`,
  `POST …/exportaciones/{exp}/cancelar`, `POST instituciones/{id}/archivar`, `POST instituciones/{id}/recuperar`.
- Script `docker/produccion/exportar.sh` con funciones comunes con `respaldar.sh`.

**Frontend**
- Sección «Baja y archivo» en `InstitucionDetallePage`, debajo de Respaldos.
- `archivada` en `ESTADOS`, `accionesDisponibles()` y el filtro de `InstitucionesPage`.
- Textos de las acciones nuevas en `ACCIONES` (historial).

## 5. Flujo propuesto

**Qué significa cada operación**

| Operación | Qué hace | Reversible |
|---|---|---|
| Suspender (existe) | Corta el acceso; conserva todo | Sí (activar) |
| Retirar del funcionamiento | Suspensión + exportación verificada | Sí |
| Archivar (propuesto) | `estado = archivada`; no opera; **base y archivos intactos** | Sí (recuperar → `suspendida`) |
| Eliminar definitivamente | Borrar base y carpeta | **No.** Fuera de este diseño; solo con plazo confirmado y como operación aparte |

**Pasos**
1. **Requisito**: institución `suspendida` y modo `subdominio` (en `unica`, rechazar).
2. **Vista previa** (solo lectura): nombre, subdominio, cliente y contrato, servicio, uso, último respaldo. Nunca
   nombres de personas ni documentos.
3. **Comprobaciones** (correcto / aviso / error / no verificado): base y migraciones, carpeta sin enlaces simbólicos,
   respaldo reciente, espacio libre, ninguna exportación en curso, inventario completo (cada documento registrado con
   su archivo, tamaño y huella).
4. **Solicitud** (panel): con motivo, transacción con `lockForUpdate`, ligada por UUID, auditada.
5. **Exportación** (servidor): `sh docker/produccion/exportar.sh <slug>`. Solo con una solicitud pendiente; base y
   carpeta desde el plan; pide escribir el slug. Escribe en `respaldos/exportaciones/<slug>-<fecha>.parcial/` (la
   limpieza de 14 días no la toca): `base.dump`, `archivos.tar.gz`, `inventario.json`, `plataforma.json` (contrato,
   periodos, respaldos; sin contraseñas), `MANIFIESTO`, `SHA256SUMS`.
6. **Verificación**: restauración temporal y lectura del paquete, comparación con huellas registradas, inventario contra
   el paquete. Solo si todo pasa se cifra y se renombra la carpeta quitando `.parcial` (un solo paso).
7. **Conservación**: permisos `700`, fuera de `public/` y de lo que sirve Caddy. Nunca a Drive ni a otro servicio sin
   configuración y autorización propias.
8. **Archivo** (panel): solo con una exportación `verificada`, posterior a `suspendida_en` y del mismo esquema; pide
   escribir el slug; transacción: `archivada`, `archivada_en`, `motivo_baja`, `exportacion_id`. **No borra nada.**
9. **Auditoría**: solicitud, inicio, resultado, verificación, cancelación, archivo, recuperación. Solo metadatos.
10. **Recuperación**: `archivada → suspendida` si `VerificacionAprovisionamiento` confirma base y carpeta; luego se
    activa como siempre. Sin base: restaurar desde la exportación con `restaurar.sh` ajustado.

**Interfaz**: la sección sigue esos pasos (vista previa, comprobaciones, «Solicitar exportación» con la instrucción del
comando, estado de la exportación, «Archivar» con confirmación escrita, «Recuperar» si está archivada) y el historial
usa la lista de auditoría que ya existe. Sin descarga web en las primeras entregas; si se aprueba (decisión 2 y 3):
carpeta montada en solo lectura fuera de `public/`, contraseña de nuevo, enlace de un solo uso que caduca, *streaming*
con `no-store`, auditado.

## 6. Riesgos, medidas y fallas

| Riesgo | Medida |
|---|---|
| Archivos de otra institución en la exportación | Sin `-h`; inventario por referencias de su propia base (sección 7) |
| Una institución archivada o con estado desconocido sigue operando | Lista blanca en `ResolverInstitucion` (**hecho**) |
| Solicitud manipulada | UUID en la ruta, `lockForUpdate`, exportación ligada a su `institucion_id`, base desde la BD, confirmación escrita |
| Exportación expuesta | Fuera de `public/`; cifrada; sin descarga web al principio |
| Datos sensibles en la auditoría | Solo metadatos; la contraseña de cifrado nunca se guarda ni se audita |
| Archivar con datos posteriores a la exportación | Suspensión previa; exportación posterior a `suspendida_en` |
| Borrado accidental | Ninguna ruta borra; claves foráneas sin cascada |

| Falla | Resultado |
|---|---|
| La base no responde | Comprobación en error; no se solicita. Durante la exportación: `fallida`; sigue `suspendida` |
| Faltan documentos | Error de inventario con conteo y rutas relativas; no se archiva (salvo decisión 6) |
| Falla la integridad | `fallida`; la carpeta queda `.parcial` para revisarla; no se habilita archivar |
| Falla a mitad | Igual: nada sale de `.parcial`; el estado no cambia |
| Falta espacio | Estimación previa (base + carpeta, margen ×2, contra `df`); no empieza |
| Interrupción tras el respaldo | Queda `exportando` → `interrumpida` pasado un tiempo; reintento; nunca archiva sola |
| Cancelación | No cambia nada; se audita |
| Ya archivada | 409 |
| Activa | Se pide suspender antes |

Ninguna etapa borra datos y el único cambio de estado (archivar) es una transacción aparte, que solo se habilita al
final.

**Pruebas propuestas**: archivada recibe rechazo (también con sesión abierta) y sus trabajos fallan; transiciones;
archivar sin exportación verificada, anterior a la suspensión o con id de otra institución; superadmin sin sesión o con
contraseña temporal; auditoría sin datos sensibles; inventario (falta un archivo, huella distinta, ruta fuera de su
carpeta); plan de exportación solo con solicitud pendiente; scripts en entorno aislado (paquete restaurable, sin
archivos de otra institución, enlace simbólico, byte alterado, falta de espacio, interrupción); exportación no servida
por Caddy; frontend con `archivada`. Probar siempre también en PostgreSQL.

## 7. Enlaces simbólicos (UNIQ) y respaldos

**Situación (entorno de desarrollo, verificada en solo lectura el 2026-10-17)**
- `instituciones/uniq/{documentos,fotos-personal,certificados-discapacidad}` son enlaces a
  `storage/app/private/{documentos,fotos-personal,certificados-discapacidad}`.
- UNIQ usa la base `escalafon`, que también es la `DB_DATABASE` del `.env`. Por eso `plataforma:respaldo-plan` **no**
  genera el objetivo `principal` (ya pertenece a una institución): esas carpetas comunes **solo** entran al respaldo a
  través de los enlaces de UNIQ y del `-h` de `tar`.
- `uno` y `dos` tienen carpetas propias sin enlaces.

**Por qué no se cambia `respaldar.sh` ahora**
Quitar `-h` dejaría a UNIQ con un paquete de enlaces vacíos: sus documentos actuales **dejarían de respaldarse**. Se
mantiene tal cual.

**Riesgo que permanece**
- Con `-h`, si una carpeta de institución tuviera un enlace a la carpeta de **otra** institución (o a una carpeta
  común con archivos de varias), su respaldo incluiría esos archivos. El respaldo queda en el servidor (`700`) y, si se
  configura, cifrado en la copia externa, pero el paquete de una institución no sería exclusivo de ella. Hoy no hay
  ninguna comprobación que lo impida o lo avise.
- En el dev actual, las carpetas comunes contienen los archivos creados en modo `unica` con la base `escalafon`, es
  decir, de UNIQ; no se puede demostrar en general que no haya archivos de otra procedencia.
- El panel de uso muestra el espacio de UNIQ como «incompleto» (no cuenta los enlaces).

**Ajuste propuesto para la exportación (no implementado)**
Exportar por **pertenencia demostrada**, no por recorrido de carpeta:
1. Archivos físicos dentro de `storage/app/private/{almacenamiento}`, recorridos **sin seguir enlaces** (`tar` sin `-h`).
2. Más los archivos **referenciados por la base de la propia institución** (`archivos_documento.storage_path` en su
   disco, `usuarios.foto_path`, y los demás discos que se agreguen), resueltos con las raíces de `ConexionInstitucion`.
   Se siguen enlaces **solo para resolver esas rutas concretas**, y se rechaza la ruta si su `realpath` cae dentro de
   la carpeta de otra institución (`instituciones/<otro>/`) o fuera de `storage/app/private`.
3. Cada archivo referenciado se compara con `tamano_bytes` y `hash_sha256` registrados.
4. Lo que haya en una carpeta común enlazada y **no** esté referenciado por su base no se exporta: se informa como
   «sin referencia» (cifra, sin nombres) para decidir.

**Propuesta para normalizar UNIQ (requiere aprobación; no ejecutar sin ella)**
Copiar (no mover) a `instituciones/uniq/` los archivos referenciados por la base de UNIQ, verificar huellas, y solo
entonces reemplazar cada enlace por la carpeta real, conservando las carpetas comunes intactas hasta confirmar que nada
más las usa. Debe hacerse con un respaldo verificado previo y la institución suspendida.

**Ajuste posterior a `respaldar.sh` (propuesto)**
Sin cambiar qué se respalda: avisar en el registro del respaldo cuando una carpeta de institución tenga enlaces, y que
`plataforma:respaldo-plan` rechace una carpeta cuyo enlace apunte a otra institución. Quitar `-h` solo después de
normalizar UNIQ.

## 8. Decisiones pendientes (NO aprobadas)

1. **Plazos de conservación** de la exportación y de la base archivada, y si alguna vez se eliminan (contrato, Ley 29733
   de protección de datos personales, normas de archivo de cada universidad). Confirmar con el cliente o asesoría legal.
2. **Entrega al cliente**: formato (volcado PostgreSQL + PDF, o también algo legible), medio (en mano, descarga, Drive
   del cliente) y cifrado (contraseña aparte o clave pública del cliente).
3. **Quién lanza la exportación**: desde el panel (instalar `postgresql-client-16` y montar la carpeta) o por comando
   en el servidor (recomendado al principio).
4. **Uno o dos superadministradores** para archivar.
5. **¿Una archivada se sigue respaldando y migrando?**
6. **¿Se permite archivar con documentos faltantes** (con constancia en la auditoría) o se bloquea siempre?
7. **UNIQ**: ¿normalizar sus archivos (sección 7) antes de cualquier exportación?
8. **¿La auditoría de la plataforma va en el paquete?** (incluye IP de superadministradores).
9. **Exportación cancelada o fallida**: ¿se conserva o se elimina? Contiene datos personales.

## 9. Entregas propuestas

1. **Protección del estado** — **HECHA**: `ResolverInstitucion` en lista blanca (`Institucion::ESTADOS_OPERATIVOS`),
   rechazo de activar o suspender un estado no reconocido, pruebas. Sin migraciones ni interfaz.
2. **Exportación por comando**: funciones comunes, `exportar.sh` (sin `-h`, inventario, manifiesto, `.parcial`),
   verificación estricta, tabla `exportaciones_institucion`, comandos. Pruebas en entorno aislado. Depende de 3, 7, 9.
3. **Panel: solicitud y seguimiento**, sin descarga.
4. **Archivar y recuperar**: estado `archivada`, transiciones, auditoría, historial, restauración desde exportación.
   Depende de 4, 5, 6.
5. **Cifrado y entrega o descarga autorizada**. Depende de 2 y 3.
6. **Eliminación definitiva**: solo con la decisión 1 confirmada, como operación aparte.
