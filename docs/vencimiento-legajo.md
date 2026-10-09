# Especificación funcional: vencimiento y renovación del legajo docente

Fecha: 9 de octubre de 2026. Estado: arquitectura con implementación local. La sección 10 detalla el comportamiento implementado, la operación y cómo probarlo; las secciones anteriores conservan el diseño de referencia.

Esta especificación desarrolla el documento solicitado y lo adapta al repositorio. Los plazos de 3, 5 y 7 años son reglas funcionales proporcionadas por el solicitante; no se presentan como una conclusión normativa. Se conservan las 13 secciones actuales y el historial de evidencias.

## 1. Integración con el sistema existente

| Concepto solicitado | Estructura actual | Cambio propuesto |
| --- | --- | --- |
| Docente | `usuarios` → `vinculos_laborales` → `perfil_docente` | Reutilizar identidad y perfil; no crear otra tabla de personas. |
| Categoría | `perfil_docente.categoria`: auxiliar, asociado, principal | Incorporar un catálogo versionado de reglas de vigencia, relacionado por código. |
| Resolución y período docente | `ratificaciones_docentes`, con resolución, fechas y categorías | Reutilizar como evidencia del evento que inicia un ciclo; conservar el período oficial registrado. |
| Incorporación | `incorporaciones`, con datos de resolución | Considerar únicamente incorporaciones concluidas y su evidencia aprobada. |
| Legajo | `legajos.estado_legajo`, apertura, archivo y custodia | Añadir ciclos de vigencia independientes del estado activo/pasivo. |
| Documento | `documentos_usuario`, `archivos_documento`, tipos y datos por sección | Conservar revisión e inmutabilidad; añadir vigencia documental cuando corresponda. |
| Requerimiento | `requerimientos_documentos` | Vincular al ciclo, al motivo y al documento objetivo; conservar los requerimientos ordinarios. |
| Notificación del panel | `notificaciones_usuario` | Reutilizar; agregar seguimiento independiente de los envíos por correo. |
| Panel | `frontend/src/features/requirements/RequirementsPage.jsx` | Ampliar el módulo con la vista «Vencimientos docentes». |
| Automatización | `backend/routes/console.php` no programa este control; Docker no incluye scheduler ni worker | Incorporar ejecución programada, cola de envíos y seguimiento operativo en la futura implementación. |

La sección 07 ya distingue nombramiento, ratificación y promoción. También contiene un aviso de ratificación de 90 días, marcado como pendiente de confirmar. El nuevo aviso de legajo a 7 días debe tener evento y finalidad propios; no reemplaza silenciosamente ese aviso ni el vencimiento oficial del período docente.

## 2. Reglas de negocio

### 2.1. Plazos y fecha base

| Categoría | Vigencia | Ejemplo de fecha base | Vencimiento | Aviso automático |
| --- | --- | --- | --- | --- |
| Auxiliar | 3 años calendario | 2023-10-16 | 2026-10-16 | 2026-10-09 |
| Asociado | 5 años calendario | 2021-10-16 | 2026-10-16 | 2026-10-09 |
| Principal | 7 años calendario | 2019-10-16 | 2026-10-16 | 2026-10-09 |

Reglas propuestas para seleccionar la base:

1. Identificar el vínculo docente aplicable al legajo y su categoría acreditada. Si existen varios vínculos elegibles, exigir identificación del vínculo rector; no seleccionar arbitrariamente el último registro.
2. Buscar el último evento habilitado por la política: resolución de nombramiento, incorporación concluida o actualización integral aprobada. Una ratificación o promoción puede ser habilitada como evento de actualización cuando así se acuerde institucionalmente.
3. Excluir eventos anulados, pendientes, observados sin subsanar o aún no efectivos. Usar la fecha de resolución para los eventos documentados por resolución. En una renovación sin resolución, proponer como base la fecha local de aprobación por ORH, dejando registrada esa procedencia.
4. Guardar en el ciclo la categoría, los años, la versión de regla, la fecha base y la referencia a la evidencia. Una edición posterior del perfil no modifica un ciclo histórico.
5. Una carga parcial, una edición de datos personales, la recepción de un solo PDF o `updated_at` no reinician el contador.

Se suman años calendario, nunca `años × 365 días`. Convención propuesta para el 29 de febrero: si el año destino no es bisiesto, usar el 28 de febrero. Las fechas de vencimiento se almacenan como fechas; el cálculo de «hoy» y la ejecución a medianoche usan `America/Lima`.

Una fecha base ausente, una categoría no reconocida, un evento futuro o una contradicción entre evidencias genera «Requiere revisión de datos». No debe producir un vencimiento inventado. El sistema actual restringe esas categorías a docentes ordinarios en el formulario de incorporación; contratados y extraordinarios quedan «No aplica» hasta que exista una regla explícita.

Si el vencimiento calculado del legajo difiere de `ratificaciones_docentes.fecha_vencimiento_periodo`, mostrar ambos con sus respectivas fuentes y derivar la discrepancia a ORH. Son controles distintos; no sobrescribir la fecha oficial.

### 2.2. Estados y límites de fecha

Se propone que el vencimiento opere **a las 00:00 del día calculado**. Por tanto, `días_restantes = 0` significa «Vence hoy · pendiente de renovación», con las restricciones ya activas. Si la institución considera válido todo ese día, deberá cambiarse conjuntamente el criterio a `días_restantes < 0` y el inicio del bloqueo al día siguiente.

| Días restantes | Estado de vigencia | Texto visible | Color |
| --- | --- | --- | --- |
| Más de 30 | Vigente | Vigente | Verde |
| De 8 a 30, inclusive | Por vencer | Vence en N días | Amarillo |
| De 1 a 7, inclusive | Por vencer | Urgente · vence en N días | Rojo |
| 0 | Vencido | Vence hoy · pendiente de renovación | Rojo |
| Menos de 0 | Vencido | Vencido hace N días | Rojo, con mayor énfasis |
| Sin base o categoría válida | Requiere revisión | Falta categoría o fecha base | Neutro, con icono de revisión |
| Fuera del ámbito configurado | No aplica | Sin política docente aplicable | Neutro |

El documento pide primero naranja/amarillo para próximos vencimientos y después rojo para menos de 7 días. Se prioriza la última escala y se completa el día 7 con rojo. Color, icono y texto se muestran juntos para que el significado no dependa únicamente del color.

Mantener separados tres ejes:

- **Estado administrativo:** activo/pasivo, custodia y archivo del legajo.
- **Vigencia temporal:** vigente/por vencer/vencido/revisión/no aplica.
- **Renovación:** borrador/en revisión/observada/aprobada/cancelada.

Un legajo activo puede estar vencido y tener una renovación en revisión. No cambiar `estado_legajo` a «vencido»: actualmente varias operaciones buscan explícitamente legajos activos y se impediría su propia renovación.

### 2.3. Documentos que requieren renovación

El vencimiento global no invalida automáticamente todos los documentos. Una política versionada por tipo documental y categoría determina el checklist; los ejemplos del pedido no constituyen por sí solos una lista obligatoria.

| Documento o situación | Criterio propuesto | Acción |
| --- | --- | --- |
| Título profesional ya acreditado | Evidencia permanente, salvo cambio, ausencia o invalidación | Conservar; solicitar reemplazo solo con motivo registrado. |
| Certificado de salud | Aplicar la fecha individual documentada o la antigüedad máxima aprobada para el tipo | Solicitar el documento que corresponda, con acceso restringido. |
| Declaración jurada | Renovar en cada ciclo si la política institucional así lo exige | Solicitar nueva declaración. |
| Documento obligatorio ausente | Falta evidencia aplicable al perfil | Crear requerimiento aunque no exista un documento anterior. |
| Documento observado o anulado | No satisface la exigencia | Solicitar subsanación o nueva evidencia según el caso. |
| Documento con plazo propio | Comparar su vigencia con la fecha de evaluación exigida por la política | Requerir renovación cuando venza o no cubra el horizonte exigido. |

Cada ítem guarda el tipo, la sección existente, la instancia requerida si hay varias, el motivo y la regla aplicada. El catálogo permite distinguir «por ciclo», «por vencimiento individual», «solo si falta/cambia» y «no renovable por plazo».

En SST se reutiliza la fecha de vencimiento ya registrada cuando exista. No crear una segunda fecha contradictoria. El estado temporal de un documento es independiente de su estado de recepción/fiscalización: un documento verificado puede vencer después.

Los documentos con plazo propio se controlan también cuando el legajo tiene más de 30 días de vigencia. Sus avisos se identifican como «Vencimiento documental» y se muestran separados del aviso obligatorio de legajo a 7 días; su anticipación requiere política propia. Renovar uno de ellos no reinicia el ciclo global.

### 2.4. Restricciones y permisos

| Operación | Legajo vigente | Legajo vencido |
| --- | --- | --- |
| Consultar expediente e historial con permisos existentes | Permitida | Permitida, indicando vencimiento. |
| Consultar requisitos y presentar renovación propia | Permitida | Permitida. |
| Recibir, observar, subsanar y verificar renovación por ORH | Permitida | Permitida. |
| Exportar historial o constancia que declare su estado real | Permitida | Permitida con estado explícito. |
| Emitir una constancia de legajo vigente | Permitida si cumple el resto de reglas | Bloqueada. |
| Finalizar un trámite expresamente configurado como dependiente de vigencia | Permitida si cumple sus requisitos | Bloqueada, mostrando el requisito y el acceso a renovación. |

No bloquear el inicio de sesión, la consulta propia ni el camino de subsanación. El vencimiento no cambia automáticamente remuneraciones, categoría, relación laboral o situación de empleo. El catálogo definitivo de trámites bloqueados requiere acuerdo institucional.

La autorización se valida en el backend con permisos, titularidad y estado actual calculado, incluso si el cron no ha ejecutado. Proponer permisos de consulta de vencimientos, gestión, notificación manual, presentación propia, revisión y aprobación. El permiso actual `legajo.propio` no autoriza por sí solo la carga: se requiere una capacidad acotada para presentar documentos de la renovación propia.

Las presentaciones del docente ingresan a una bandeja de recepción; la ORH incorpora la evidencia al legajo con sección, tipo y folios conforme al flujo existente. Quien carga no verifica su propia evidencia. Se conservan las restricciones de las secciones 01, 09 y 10 y los documentos validados no se reemplazan ni eliminan: se enlaza la nueva evidencia y se mantiene el historial.

Si una sección deshabilitada contiene un requisito obligatorio, mostrar un conflicto de configuración a ORH. No eliminar el requisito ni habilitar acceso automáticamente.

## 3. Modelo de datos propuesto

### 3.1. Diagrama entidad-relación textual

```text
USUARIO (docente)
  1 ── N VINCULO_LABORAL
            1 ── 0..1 PERFIL_DOCENTE ── categoría por código
                           1 ── N RATIFICACION_DOCENTE (evidencia oficial)
  1 ── N LEGAJO
            1 ── N CICLO_VIGENCIA_LEGAJO
                     N ── 1 REGLA_VIGENCIA_CATEGORIA (versión y años)
                     N ── 1 PERFIL_DOCENTE (perfil que fundamenta el ciclo)
                     1 ── N REQUERIMIENTO_DOCUMENTO
                                  N ── 1 TIPO_DOCUMENTO
                                  N ── 0..1 DOCUMENTO_USUARIO (evidencia anterior)
                     1 ── N RENOVACION_LEGAJO
                                  1 ── N ITEM_RENOVACION
                                             N ── 1 REQUERIMIENTO_DOCUMENTO
                                             N ── 0..1 DOCUMENTO_USUARIO (nueva evidencia)
                     1 ── N EVENTO_NOTIFICACION
                                  1 ── N ENTREGA_NOTIFICACION (destinatario/canal)
  1 ── N DOCUMENTO_USUARIO ── N:1 LEGAJO / TIPO_DOCUMENTO
  1 ── N NOTIFICACION_USUARIO (destinatario del panel)

TIPO_DOCUMENTO 1 ── N REGLA_RENOVACION_DOCUMENTAL
EVENTO_NOTIFICACION 1 ── N NOTIFICACION_USUARIO
```

La cardinalidad de un perfil aplicable por vínculo es una regla lógica propuesta: la migración actual de `perfil_docente` no impone unicidad sobre `vinculo_laboral_id`. Antes de calcular, identificar duplicados y resolver el perfil aplicable; no dar por garantizada esa unicidad en los datos históricos.

La relación documento–requerimiento admite un documento objetivo nulo: también se pide documentación faltante. Los requerimientos de un ciclo se agrupan para enviar un único aviso con el checklist, evitando un correo por archivo.

### 3.2. Entidades y campos esenciales

Los nombres nuevos son propuestas; las tablas existentes mantienen sus identificadores actuales. Las fechas de negocio son `DATE`; eventos, envíos y aprobaciones registran además instante y actor.

| Entidad | Campos o extensiones propuestas | Integridad |
| --- | --- | --- |
| `reglas_vigencia_categoria` | id, codigo_categoria, version, anios_vigencia, efectiva_desde, efectiva_hasta | Intervalos sin solapamiento por categoría; años positivos; versiones históricas conservadas. |
| `ciclos_vigencia_legajo` | id, legajo_id, perfil_docente_id, regla_id, categoria_aplicada, anios_aplicados, fecha_base, fecha_vencimiento, fecha_aviso, origen_base, incorporacion_id/ratificacion_id/renovacion_origen_id, vigente_desde, cerrado_en, motivo_cierre | Una fuente de base identificada; como máximo un ciclo actual por legajo; fechas y snapshots coherentes. |
| `legajos` | ciclo_vigencia_actual_id opcional | Debe apuntar a un ciclo de ese mismo legajo; no sustituye el estado administrativo. |
| `reglas_renovacion_documental` | id, version, categoria_aplicable, tipo_documento_id, modo, antiguedad_maxima opcional, horizonte_exigido opcional, obligatoria, efectiva_desde/hasta | Categoría, perfil y tipo compatibles; requisitos versionados. |
| `documentos_usuario` y datos por sección | fecha_vencimiento individual opcional donde no exista; fuente_vencimiento | Una única fuente autoritativa por documento; fecha y estado de revisión independientes. |
| `requerimientos_documentos` | ciclo_id opcional, regla_documental_id opcional, documento_objetivo_id opcional, motivo, clave_exigencia, unidad_plazo, origen automático/manual | Conservar `documento_id` para su evidencia de cumplimiento; unicidad de clave por ciclo. |
| `renovaciones_legajo` | id, ciclo_id, estado, presentada_en, aprobada_en, aprobada_por, motivo_observacion, resolucion/fecha_resolucion opcionales, ciclo_nuevo_id | Solo una renovación en trámite por ciclo; aprobación única y auditable. |
| `items_renovacion` | id, renovacion_id, requerimiento_id, archivo_propuesto_id opcional, documento_nuevo_id opcional, estado_revision, revisado_por/en, motivo | Archivo presentado y evidencia formal diferenciados; nuevo documento pertenece al titular y legajo. |
| `eventos_notificacion` | id, ciclo_id, tipo_evento, fecha_programada, checklist/version, creado_en, origen, solicitado_por opcional | Aviso automático único por ciclo, tipo y fecha; eventos manuales identificados separadamente. |
| `entregas_notificacion` | id, evento_id, destinatario_id, canal, destino, estado, intentos, proximo_intento_en, enviado_en, error, referencia_proveedor, clave_idempotencia | Único por evento, destinatario y canal; sin mezclar lectura con entrega. |
| `notificaciones_usuario` | evento_notificacion_id opcional | Una notificación de panel por evento y destinatario; mantiene su lectura actual. |
| Asignación de jefe inmediato | docente/vinculo, jefe_usuario_id, vigencia_desde/hasta | Relación explícita y vigente; pertenecer a la misma unidad no basta. |

No es necesario duplicar nombre o fecha de ingreso en una tabla «docentes»: salen de `usuarios` y del vínculo laboral aplicable. «Fecha de última actualización» corresponde a la base del ciclo vigente, con su fuente, y no al timestamp genérico del perfil.

`requerimientos_documentos.plazo_dias_habiles` es actualmente obligatorio. Para este flujo deberá admitir «no aplica» y agregarse la unidad de plazo, o usarse una fecha límite explícita sin duración en hábiles. El aviso a 7 días es calendario; no registrar esos 7 como días hábiles. Las prórrogas de requerimientos no amplían por sí solas la vigencia del legajo.

La automatización necesita origen «sistema» y actor técnico auditable. El campo obligatorio actual `solicitado_por` debe adaptarse para ese origen; no atribuir el requerimiento a un administrador elegido arbitrariamente.

### 3.3. Ejemplo JSON de conexión

Representación de consulta con identificadores ilustrativos; no es un esquema físico ni un endpoint implementado. Corte: 2026-10-09 en Lima.

```json
{
  "docente": {
    "usuario_id": "usuario-001",
    "nombre": "María Pérez",
    "vinculo_laboral_id": "vinculo-001",
    "perfil_docente_id": "perfil-001",
    "fecha_ingreso": "2023-10-16"
  },
  "legajo_id": "legajo-001",
  "ciclo": {
    "id": "ciclo-001",
    "regla_id": "auxiliar-v1",
    "categoria": "auxiliar",
    "anios_vigencia": 3,
    "fecha_ultima_actualizacion": "2023-10-16",
    "origen_base": "resolucion_nombramiento",
    "ratificacion_id": "ratificacion-001",
    "fecha_vencimiento": "2026-10-16",
    "fecha_notificacion_programada": "2026-10-09",
    "dias_restantes": 7,
    "estado_vigencia": "por_vencer"
  },
  "requerimientos": [
    {
      "id": "req-001",
      "ciclo_id": "ciclo-001",
      "tipo_documento_id": "tipo-ddjj",
      "documento_objetivo_id": "doc-anterior-001",
      "documento_nuevo_id": null,
      "motivo": "renovacion_por_ciclo",
      "fecha_limite": "2026-10-16",
      "unidad_plazo": "calendario",
      "estado": "abierto"
    }
  ],
  "notificacion": {
    "evento_id": "aviso-001",
    "ciclo_id": "ciclo-001",
    "fecha_programada": "2026-10-09",
    "canal": "correo",
    "destinatario_id": "usuario-001",
    "estado_entrega": "pendiente"
  }
}
```

## 4. Pseudocódigo del cálculo y control diario

### 4.1. Cálculo de vigencia

```text
CALCULAR_VIGENCIA(categoría, fecha_base, hoy_Lima, versión_regla):
    regla ← buscar regla aprobada de categoría y versión
    si falta regla o fecha_base, o fecha_base es futura:
        devolver revisión de datos, fechas nulas, días nulos

    vencimiento ← sumar años calendario(fecha_base, regla.años)
                    ajustando 29-feb a 28-feb cuando corresponda
    aviso ← vencimiento menos 7 días calendario
    días ← diferencia de fechas firmada(vencimiento menos hoy_Lima)

    si días <= 0: estado ← vencido
    si 1 <= días <= 30: estado ← por_vencer
    si días > 30: estado ← vigente

    devolver vencimiento, aviso, días, estado, regla aplicada
```

La función recibe una base previamente seleccionada y acreditada; no decide qué PDF es válido. Los ciclos existentes se calculan con la regla guardada en ellos, no con una nueva regla global que cambie su historia.

### 4.2. Proceso diario a las 00:00

```text
CONTROL_DIARIO(hoy_Lima):
    obtener bloqueo de ejecución para evitar solapamientos
    recorrer por lotes los legajos activos dentro del ámbito docente
        validar ciclo actual y coherencia de sus datos
        calcular vigencia con su regla y fecha base
        registrar transición temporal si cambió

        si fecha_aviso = hoy_Lima:
            CREAR_AVISO_UNICO(ciclo, fecha_aviso, origen automático)
        si fecha_aviso < hoy_Lima y falta el evento automático del ciclo:
            CREAR_AVISO_UNICO(ciclo, fecha_aviso, origen recuperación tardía)

        si está vencido:
            registrar pendiente de renovación y restricciones aplicables
            asegurar checklist/requerimientos sin duplicarlos

    registrar resultado, errores por legajo y última ejecución exitosa
    liberar bloqueo

CREAR_AVISO_UNICO(ciclo, fecha_programada, origen):
    dentro de una transacción:
        comprobar que el ciclo sigue actual
        reservar evento único por ciclo/tipo/fecha_programada
        obtener checklist según política versionada
        asegurar requerimientos únicos para sus exigencias
        crear notificaciones de panel para administradores autorizados
        crear entregas pendientes al docente y, si aplica, a jefe vigente
    después de confirmar la transacción:
        habilitar envíos pendientes para el worker
```

El panel puede mostrar vencimientos y preparar una renovación antes del aviso; generar un requerimiento previamente no debe impedir el aviso único del día 7. Si no hay documentos exigibles, presentar «Revisión de legajo requerida» y remitir a ORH para definir su cierre; no reiniciar automáticamente el ciclo.

La fecha programada es exactamente `vencimiento − 7 días`. Una indisponibilidad puede retrasar la entrega real: se registra como aviso tardío y nunca se simula un envío pasado. La recuperación solo afecta ciclos actuales; ciclos renovados o archivados cancelan entregas pendientes que ya no correspondan.

## 5. Wireframe descriptivo del panel

```text
Control de Vencimiento de Legajo
Panel de Requerimientos y Vencimientos · Corte: 09/10/2026 · Lima

[Por vencer en 30 días: 42] [Vencidos: 8] [Revisión de datos: 3]
[Avisos de legajo] [Vencimientos documentales] [Requerimientos ordinarios]

Buscar docente [____________________]
Categoría [Todas ▼] Estado [Todos ▼]
Vencimiento [Desde __/__/____] [Hasta __/__/____]
[Esta semana] [Próximos 30 días] [Limpiar filtros]

Docente       Categoría  Vencimiento  Días       Documentos     Acciones
María Pérez   Auxiliar   16/10/2026   7          2 pendientes  [Ver detalle]
Luis Torres   Asociado   25/10/2026   16         1 pendiente   [Ver detalle]
Ana Rojas     Principal  05/10/2026   Hace 4 d.  3 pendientes  [Ver detalle]

Verde: >30 días · Amarillo: 8–30 · Rojo: 0–7 y vencidos
[Página 1 de N]                                    [20 por página]
```

Los números y nombres del wireframe son ilustrativos. Las filas se ordenan por vencimiento ascendente; las vencidas conservan prioridad. Los casos sin datos se consultan en su filtro específico para evitar ocultarlos entre registros calculados.

«Por vencer en 30 días» cuenta legajos distintos con `1 <= días <= 30`; «Vencidos» incluye `días <= 0`. Para consultar hoy y los siguientes 30 días, el filtro de fechas usa extremos inclusivos y la tarjeta informa su criterio. «Esta semana» significa lunes a domingo en Lima. Los filtros se combinan; las tarjetas muestran el ámbito filtrado y un indicador cuando hay filtros activos.

### Detalle lateral o página de requerimiento

- Cabecera: nombre, categoría, legajo, fecha base y su evidencia, vencimiento, días restantes y estado.
- Checklist: tipo documental, sección, motivo, fecha individual si aplica, evidencia anterior y estado de la nueva presentación.
- Estados por ítem: pendiente → presentado → recibido/en revisión → aprobado u observado. Una casilla marcada no reemplaza la revisión documental.
- Bloque de avisos: fecha programada, fecha real, destinatario autorizado, canal, estado y error de envío sin datos técnicos sensibles.
- Historial: avisos, presentaciones, observaciones, aprobaciones y ciclos anteriores.
- **«Notificar al docente»**: disponible con permiso cuando el envío automático falló o requiere recuperación; registra actor y motivo, y evita doble clic o envío concurrente.
- **«Registrar renovación»**: abre carga y checklist; informa que el período se reinicia al aprobarse la renovación completa.
- **«Presentar para revisión»**: para el titular o el operador habilitado; valida archivos y requisitos.
- **«Aprobar renovación»**: solo para un revisor habilitado después de cumplir la recepción y fiscalización aplicables.

Adaptar el panel al diseño existente y sus colores semánticos de Tailwind. En móvil, mostrar tarjetas por docente y conservar fecha, estado y acciones. Incluir estados vacíos, carga, error recuperable y explicación del bloqueo. No revelar documentos sensibles al listar cantidades o motivos a personas sin acceso.

## 6. Flujo de notificación y renovación

1. **Faltan 7 días.** A las 00:00 de Lima se identifica el ciclo, se fija su checklist y se registra el evento único y sus requerimientos.
2. **Se publica el aviso administrativo.** Los responsables autorizados lo ven en el panel, con docente, categoría, vencimiento y lista permitida de requisitos.
3. **Se prepara el correo.** El docente recibe un enlace autenticado a su renovación; el jefe solo recibe aviso cuando existe asignación vigente y política habilitada. Una dirección ausente queda como entrega fallida y tarea para ORH.
4. **Se entrega y registra el resultado.** El worker procesa las entregas. «Enviado» significa aceptado por el proveedor, no leído. Los rechazos y fallos quedan visibles; la lectura del panel conserva su estado independiente.
5. **El docente presenta archivos.** Se guardan como nueva presentación con titularidad, tipo e ítem requerido; no alteran la evidencia anterior ni el contador de años.
6. **ORH recibe y revisa.** Incorpora los documentos con los metadatos y folios existentes, registra conformidad u observaciones y exige fiscalización cuando corresponda. Los observados vuelven a subsanación.
7. **Llega el vencimiento.** Si aún no existe aprobación integral, se activa «Pendiente de renovación». Una solicitud en revisión no extiende el plazo automáticamente.
8. **Se aprueba la renovación completa.** En una transacción se comprueban todos los requisitos, se registra el actor, se cierra el ciclo anterior, se crea el siguiente con categoría y regla acreditadas, se enlaza la nueva evidencia y se cierran los requerimientos satisfechos. Se reevalúa la vigencia individual de la evidencia en la fecha de aprobación y el horizonte exigido; una revisión anterior no permite aceptar un documento que venció durante el trámite. Cualquier cambio de aplicabilidad del checklist se registra y se comunica antes de aprobar.
9. **Se recalculan las fechas y restricciones.** El nuevo ciclo sustituye al anterior, se cancelan avisos pendientes obsoletos y se informa la nueva fecha. Si una resolución aprobada usa una fecha base retroactiva y el nuevo período ya venció, se mantiene el bloqueo: aprobar evidencia no inventa vigencia.

Contenido propuesto del correo al titular:

> Estimado/a [Nombre], su legajo como docente [Categoría] vence el [Fecha]. Por favor, presente los documentos pendientes: [lista permitida]. Consulte su requerimiento en [enlace al sistema].

El jefe recibe información mínima del vencimiento y del estado del trámite; no diagnósticos, PDFs médicos ni datos de secciones sensibles. El correo remite al sistema con autenticación, sin adjuntar evidencia sensible ni usar enlaces públicos.

### Fiabilidad y operación

- Ejecutar el control diariamente a medianoche de Lima. Es necesario un proceso de scheduler real y un worker supervisado; declarar una tarea en la aplicación no basta.
- Usar bloqueo contra solapamientos, procesamiento por lotes, transacciones, índices por legajo/fecha y restricciones de unicidad. Dos ejecuciones del mismo día no crean dos avisos.
- Utilizar una bandeja de salida persistente: panel y solicitudes de envío se confirman en la misma transacción. Los correos salen después, con reintentos progresivos y límite configurado.
- Reintentar una entrega fallida conserva su identificador y clave de idempotencia. Ante timeout después de una posible aceptación, conciliar con el proveedor antes de reenviar cuando sea posible; la entrega exactamente una vez depende de su soporte de idempotencia.
- Una notificación manual deja motivo y actor; si el automático está en curso, se evita un envío paralelo. Revalidar ciclo vigente y destinatario antes de cualquier entrega.
- Monitorizar última ejecución, ciclos sin datos, avisos tardíos, antigüedad de entregas pendientes y errores. Los fallos de un docente no interrumpen el lote completo.

## 7. Plan de migración e integración futura

1. **Acordar políticas.** Confirmar fuentes de fecha, inicio del bloqueo, requisitos documentales, trámites bloqueados y tratamiento de categorías fuera del ámbito actual.
2. **Añadir estructuras compatibles.** Crear reglas, ciclos, renovaciones y bandeja de salida; extender relaciones existentes sin renombrar estados administrativos ni estados de revisión.
3. **Preparar datos.** Relacionar perfil, vínculo, legajo y resolución; identificar fechas faltantes, vínculos múltiples y contradicciones. Previsualizar la población afectada para ORH.
4. **Inicializar ciclos acreditados.** Importar solo bases válidas con procedencia y regla guardadas; el resto queda para revisión. No usar la fecha de migración como última actualización.
5. **Publicar consulta y renovación.** Ampliar el panel existente y añadir operaciones específicas, con permisos y acceso propio acotado. Los listados incluyen filtros, paginación y resúmenes calculados en servidor.
6. **Activar automatización.** Configurar scheduler, worker, correo y destinatarios administrativos. Los avisos atrasados se emiten como recuperación, con fecha real y sin duplicación.
7. **Activar restricciones acordadas.** Validar vigencia en cada operación afectada y conservar acceso a renovación. Registrar cualquier excepción autorizada con actor, motivo y vigencia.

Contratos funcionales necesarios: consulta de resumen/listado; detalle de ciclo y checklist; presentación de renovación; recepción/revisión por ítem; aprobación integral; reintento manual de notificación. Cada operación valida el legajo y el ciclo actuales; una petición desde una pantalla desactualizada debe exigir recarga y no aprobar el ciclo equivocado.

La migración no calcula una nueva categoría a partir de nombres, no anula documentos antiguos y no modifica los períodos oficiales de ratificación. No se añaden campos de entidad ajenos al modelo de despliegue actual.

## 8. Criterios de aceptación

| Escenario | Resultado esperado |
| --- | --- |
| Auxiliar, base 2023-10-16 | Vence 2026-10-16; aviso programado 2026-10-09. |
| Asociado y Principal con las bases de la tabla | Mismo vencimiento usando 5 y 7 años respectivamente. |
| Base 2024-02-29 para Auxiliar | Vence 2027-02-28 bajo la convención propuesta. |
| Quedan 31, 30, 8, 7, 1, 0 y −1 días | Verde, amarillo, amarillo, rojo, rojo, vencido hoy y vencido hace 1 día; sin límites sin clasificar. |
| Se ejecuta el control dos veces o en paralelo | Un único evento automático y un requerimiento por exigencia del ciclo. |
| Scheduler no funciona durante el día del aviso | Se recupera el aviso con fecha real tardía; las operaciones siguen comprobando la vigencia. |
| El correo falla y luego se reintenta | No se duplica el aviso del panel; queda trazabilidad de la entrega. |
| Se carga solo uno de varios documentos o existe una observación | La renovación permanece abierta y no cambia la fecha base. |
| Se aprueba dos veces desde sesiones concurrentes | Solo un ciclo nuevo; la segunda operación informa que ya fue aprobada. |
| Falta fecha, categoría o vínculo rector | Revisión de datos; no se asignan plazos por defecto. |
| Vence un certificado antes del legajo | Requerimiento documental independiente; no se reinicia ni se vence automáticamente el ciclo global. |
| Cambia la política de años | Los ciclos existentes conservan su regla; los nuevos aplican la versión acordada. |
| Un titular intenta presentar documentos de otra persona | Se deniega en backend. |
| Un jefe consulta documentación médica o sensible | No se revela contenido ni detalle restringido. |
| El cargador intenta verificar su propia evidencia | Se deniega la verificación. |
| Una renovación se aprueba antes de vencer el ciclo anterior | El nuevo ciclo comienza según su evento aprobado; se cancelan avisos obsoletos. |
| El legajo pasa a pasivo | Se conserva historial y se detienen nuevos avisos automáticos del ciclo activo. |

## 9. Decisiones institucionales pendientes

- Confirmar el criterio de vencimiento al inicio del día y el ajuste del 29 de febrero.
- Confirmar la fecha que inicia una renovación sin resolución y si ratificación/promoción habilitan un nuevo ciclo.
- Aprobar la lista documental por categoría/perfil, la antigüedad exigida y los horizontes de vigencia individual. Los títulos y certificados del pedido son ejemplos, no reglas automáticamente aprobadas.
- Definir trámites concretos sujetos al bloqueo, excepciones y si existen prórrogas que realmente cambien la vigencia.
- Identificar destinatarios administrativos y jefes inmediatos; acordar canales, reintentos y tratamiento de dirección ausente.
- Resolver discrepancias con períodos oficiales y el aviso de ratificación de 90 días actualmente pendiente de confirmar.
- Acordar política para contratados, extraordinarios, vínculos docentes simultáneos y casos sin documentos exigibles.

Estas decisiones no impiden describir la arquitectura, pero deben quedar resueltas antes de activar cálculos, bloqueos y envíos reales.

## 10. Implementación local y prueba desde el sistema

### Acceso y preparación

Abrir `http://localhost:5173/vencimientos`, o seleccionar **Vencimientos** en el menú. Recargar la aplicación para obtener los permisos actualizados de la sesión.

1. Entrar con un administrador de ORH. Abrir **Configurar documentos exigibles por categoría** y guardar las reglas institucionales. Se habilitan tres modos: cada ciclo, solo si falta/no está aprobado y por vencimiento/antigüedad. No se precargan títulos ni certificados como exigencias obligatorias.
2. Seleccionar **Procesar vencimientos ahora**. El control inicializa automáticamente los casos con un perfil ordinario activo único y una resolución formal aprobada, con relación acreditada al vínculo. Los empates entre resoluciones de la misma fecha se dejan para selección humana.
3. Abrir **Ver detalle**. Si falta un ciclo, seleccionar el perfil docente aplicable y una resolución aprobada de incorporación o nombramiento; se puede identificar un jefe inmediato para el correo adicional. La fecha base procede de la evidencia, no de una fecha libre ni de la apertura del legajo.
4. Si no aparecen perfiles o resoluciones, completar el vínculo/perfil en la sección 02 y registrar la evidencia correspondiente. La resolución de la sección 02 debe estar enlazada al vínculo y tener un tipo de formalización que exija número de resolución. Un nombramiento de la sección 07 debe tener sus datos vinculados al perfil.

Los registros sin base acreditada se muestran para revisión y no reciben una fecha inventada. Los docentes sin una política ordinaria aplicable tampoco reciben un período de 3/5/7 años por defecto. La primera ejecución local no encontró ciclos acreditados que pudiera inicializar automáticamente.

### Renovación de principio a fin

1. En el detalle, seleccionar **Registrar renovación** para preparar el checklist.
2. El titular con permiso `renovacion.propia` puede presentar sus PDFs. Un asistente puede presentar los del personal dentro de su ámbito autorizado y de los accesos a secciones sensibles.
3. Seleccionar **Presentar para revisión** una vez que cada requisito tenga presentación o evidencia incorporada. La renovación pasa a revisión y sus presentaciones quedan protegidas contra nuevas cargas hasta que ORH la observe.
4. ORH descarga la presentación y registra el mismo PDF mediante el formulario de su sección, con tipo, titular, metadatos y folios. Se completa la recepción y la revisión aplicables. El módulo no inserta PDFs sin esos metadatos en el expediente formal.
5. Si el tipo acredita el perfil del puesto, se puede registrar su **fiscalización conforme** desde el requisito: seleccionar evidencia recibida, indicar el inicio y las conclusiones y adjuntar como referencia un informe ya incorporado y aprobado en el mismo legajo. Se requiere confirmación explícita de la comprobación realizada. Se registra el proceso, el responsable, el historial y la auditoría; no se comprueba la autenticidad automáticamente.
6. Un revisor distinto del cargador selecciona **Vincular evidencia**. Se comprueban titular, legajo, tipo, recepción, fiscalización, vigencia, antigüedad y, si existe presentación, coincidencia del hash del PDF.
7. Seleccionar **Aprobar renovación completa**. Se vuelve a verificar la evidencia; se cierra el ciclo anterior y se crea el siguiente con fecha base igual a la fecha de aprobación en Lima y la regla correspondiente a la categoría acreditada. Se conservan los documentos y las presentaciones anteriores.

Los errores de datos o evidencias se muestran en la operación que los detecta. Una renovación parcial, observada o pendiente de fiscalización no reinicia el plazo. El CRUD genérico no permite alterar ni eliminar requerimientos pertenecientes a un ciclo de vigencia.

### Avisos, colores y restricciones

La aplicación usa el criterio de vencimiento al inicio del día calculado y el ajuste del 29 de febrero al 28 de febrero para años no bisiestos. Los colores cubren todos los límites: verde >30 días, amarillo 8–30, rojo ≤7 días; el día 0 ya está vencido.

El aviso automático se genera a 7 días calendario; si hubo una interrupción se registra recuperación tardía. El detalle permite consultar envíos y solicitar recuperación manual de fallos. La bandeja de salida usa `avisos_vencimiento` y `envios_vencimiento`; un consumidor programado procesa sus entregas persistentes. Los estados incluyen pendiente, procesando, enviado, fallido, simulado, incierto y cancelado. Un resultado incierto requiere conciliación para evitar reenvíos duplicados.

El entorno local utiliza `MAIL_MAILER=log`: los correos se registran como **simulados**, y no como entregados. Para correo real se deben configurar SMTP o un proveedor compatible en `backend/.env`; el módulo no cambia ni publica credenciales. El enlace de los correos usa `FRONTEND_URL`, con valor local por defecto `http://localhost:5173`.

**Descargar constancia de vigencia** genera un PDF previa comprobación en el servidor. Se bloquea cuando el legajo está vencido, aunque el control diario aún no haya ejecutado. La consulta y la renovación siguen disponibles. Otros trámites solo se bloquean cuando se incorporan al catálogo `rutas_requieren_vigencia` de `backend/config/vencimientos.php`; su selección institucional sigue pendiente.

### Operación y cambios de datos

La migración aplicada es `backend/database/migrations/2026_10_09_192514_create_vencimiento_legajo_tables.php`. Añade reglas, políticas versionadas, ciclos, renovaciones, presentaciones y avisos, y adapta los requerimientos existentes para distinguir días hábiles de calendario y origen de sistema. Conserva estados administrativos y los períodos oficiales de ratificación.

El servicio `scheduler` de Docker Compose ejecuta el control a las 00:00 de Lima, repite una comprobación horaria para recuperar interrupciones y consume los correos pendientes cada minuto. Comparte el código, el almacenamiento y los bloqueos con la aplicación. Está activado en el entorno local.

Operaciones manuales disponibles:

```sh
docker compose exec app php artisan legajos:controlar-vencimientos
docker compose exec app php artisan legajos:enviar-avisos
docker compose exec app php artisan schedule:list
docker compose logs --tail=30 scheduler
```

La copia de categoría, años y versión en cada ciclo evita que una edición del perfil cambie fechas históricas. Las resoluciones nuevas no reinician por sí solas un ciclo ya existente: su actualización integral debe pasar por la renovación. El nuevo aviso de legajo no modifica el aviso de ratificación de 90 días pendiente de definición institucional.

### Verificación realizada

La suite completa del backend pasó con 481 pruebas y 5098 aserciones. Las 28 pruebas del frontend, el análisis estático y la compilación también pasaron. Además, 22 pruebas específicas del módulo se ejecutaron contra una base PostgreSQL aislada, eliminada al finalizar la verificación. La compilación conserva la advertencia de tamaño del paquete principal.

El panel local respondió HTTP 200 y la API respondió HTTP 401 a una consulta JSON sin sesión, como corresponde. El servicio `scheduler` está en ejecución y sus tareas aparecen en `schedule:list`. Estas comprobaciones no acreditan entrega real de correos mientras el entorno use el transporte `log`.

### Archivos incorporados o modificados

| Área | Archivos |
| --- | --- |
| API y restricciones | `backend/app/Http/Controllers/Api/V1/VencimientoLegajoController.php`, `RequerimientoDocumentoController.php`, `backend/app/Http/Middleware/ExigirVigenciaLegajo.php`, `backend/routes/api.php` |
| Lógica y automatización | `backend/app/Support/VigenciaLegajo.php`, `AvisosVencimiento.php`, `backend/app/Console/Commands/ControlVencimientos.php`, `EnviarAvisosVencimiento.php`, `backend/routes/console.php` |
| Modelos | `backend/app/Models/CicloVigencia.php`, `RenovacionLegajo.php`, `PresentacionRenovacion.php`, `AvisoVencimiento.php`, `EnvioVencimiento.php`, `Legajo.php`, `RequerimientoDocumento.php`, `NotificacionUsuario.php` |
| Migración y fábricas | Migración indicada arriba; `backend/database/factories/CicloVigenciaFactory.php`, `RenovacionLegajoFactory.php`, `PresentacionRenovacionFactory.php`, `AvisoVencimientoFactory.php`, `EnvioVencimientoFactory.php` |
| Configuración | `backend/config/vencimientos.php`, `permisos.php`, `mail.php`, `docker-compose.yml` |
| Interfaz | `frontend/src/features/requirements/VencimientosPage.jsx`, `RequirementsPage.jsx`, `frontend/src/app/App.jsx`, `frontend/src/config/navigation.js`, `frontend/src/layouts/Sidebar.jsx` |
| Pruebas y documentación | `backend/tests/Feature/VencimientoLegajoTest.php`, `AuthenticationTest.php`, `ResourceApiTest.php`, este documento |

No se cambiaron ramas, no se crearon commits y no se modificaron archivos `.env`.
