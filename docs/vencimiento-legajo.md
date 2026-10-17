# Vencimientos y renovación del legajo

## Panel de próximos vencimientos

Ruta frontend: `/vencimientos`. API: `GET /api/v1/vencimientos/proximos`.

El panel reúne tres fuentes, dentro de la base de la institución identificada por el subdominio:

| Tipo | Fecha consultada | Condiciones |
| --- | --- | --- |
| Ciclo del legajo | `ciclos_vigencia_legajo.fecha_vencimiento` | Ciclo actual y legajo activo. |
| Contrato / vínculo | Fin de la última adenda no anulada; en su ausencia, `fecha_fin_prevista` | Vínculo iniciado, no anulado, sin cese y asociado a un legajo activo. |
| Documento | `documentos_usuario.fecha_caducidad` | Documento no anulado, sección habilitada, legajo activo y permisos suficientes. |

Cada fila representa un vencimiento; una persona puede tener varias filas. No se inventan fechas para contratos indefinidos, documentos sin caducidad ni legajos sin ciclo acreditado. Las fechas documentales corresponden al campo común del documento; no se incluyen automáticamente plazos que existan únicamente en otros registros especializados.

El rango inicial es desde hoy hasta hoy + 30 días, con ambos extremos incluidos. El usuario puede elegir de 1 a 365 días en el formulario; la selección queda en la URL. El día se calcula en `America/Lima`. Los vencidos anteriores a hoy se consultan, para ciclos docentes, en la vista de renovación.

- Rojo / Urgente: de 0 a 6 días restantes.
- Amarillo / Próximo: desde 7 días hasta el límite elegido.
- La criticidad incluye texto; no depende únicamente del color.

Filtros: búsqueda por nombre, documento de identidad o número de legajo; tipo de vencimiento; tipo de personal; área/unidad orgánica. Para contratos se usa el área del vínculo; para ciclos y documentos, el área del vínculo abierto más reciente, con respaldo en el área histórica de la persona. El filtro por tipo corresponde a la fuente del vencimiento, no al catálogo documental.

El servidor combina las tres fuentes, filtra y ordena por fecha ascendente antes de paginar en grupos de 20. Los desempates usan origen, identificador y legajo. La interfaz contempla carga, vacío, error con reintento y datos; controles con labels, tablas con encabezados, foco visible y paginación por teclado.

## Permisos y aislamiento

`vencimientos.ver` permite consultar vencimientos institucionales. `renovacion.propia` permite consultar exclusivamente los de la persona autenticada. El permiso del panel no concede acceso a documentos sensibles, sanciones ni a secciones desactivadas. El filtro y la autorización se aplican en el servidor.

Se mantienen las conexiones, discos y caché por institución de main. El panel de plataforma/superadministrador mantiene sus rutas y sesión separadas. La configuración de funcionalidades y la apertura de API de main siguen aplicándose.

## Renovación docente

La vista `/vencimientos?vista=renovaciones` reutiliza el módulo de la rama feature, sin crear un segundo circuito de renovación. Permite revisar fechas base acreditadas, requisitos, evidencias, observaciones, aprobación y avisos.

Los ciclos corresponden a docentes ordinarios: auxiliar 3 años, asociado 5 y principal 7, conforme a las reglas versionadas que guarda este módulo. El inicio exige una resolución aplicable y aprobada; la apertura del legajo no se utiliza como fecha base. La renovación aprobada inicia un nuevo ciclo y conserva los anteriores.

La regla histórica de vigencia del módulo considera vencido desde el día de vencimiento y usa rojo hasta 7 días inclusive. Esta regla de trámite y el calendario de avisos se conservan; la consulta de próximos vencimientos usa el rango e indicadores confirmados arriba y no cambia plazos ni estados persistidos.

Permisos específicos: `vencimientos.gestionar`, `vencimientos.notificar`, `renovaciones.gestionar`, `renovaciones.aprobar`, `documento.fiscalizar` y `renovacion.propia`. El CRUD genérico no permite modificar o borrar requisitos ligados a ciclos para evitar saltar las transiciones de renovación.

## Preparación y operación

Desde la raíz, después de incorporar los commits:

```sh
docker compose exec app php artisan migrate --no-interaction
docker compose exec app php artisan plataforma:migrar-instituciones --no-interaction
```

Las migraciones añaden incorporación/acceso y las tablas de ciclos, renovaciones, avisos y envíos. No es necesario repetir los seeders de demostración para habilitar estos módulos. Cierra sesión y vuelve a entrar para renovar los permisos que usa el frontend.

El scheduler ejecuta los siguientes comandos por institución activa y aprovisionada, con restauración del contexto al finalizar:

```sh
docker compose exec app php artisan plataforma:procesar-vencimientos controlar
docker compose exec app php artisan plataforma:procesar-vencimientos enviar
```

El control se programa cada hora y el envío cada minuto; requiere el scheduler habitual del despliegue. En modo de una institución se procesa la conexión predeterminada. Si faltan migraciones, se informa un fallo sin inicializar tablas ni continuar con esa institución. Una institución fallida no impide intentar las siguientes.

Los avisos automáticos existentes corresponden a ciclos docentes; incorporar contratos y documentos al listado no activa envíos automáticos para esos tipos. El correo en `log` o `array` se registra como simulado. Configura el transporte de correo de main y `FRONTEND_URL` con la URL pública del frontend antes de enviar correos reales. El enlace usa el subdominio de la institución durante el procesamiento institucional. Los tiempos de espera dejan el envío incierto para conciliarlo antes de reenviar.

La política opcional de antivirus está en `config/antivirus.php`; si se exige, una carga o descarga sin análisis limpio se bloquea. No se instalan servicios antivirus ni se cambian las dependencias con esta integración.

## Verificación

Los casos y resultados están en [revision-integracion-paneles.md](revision-integracion-paneles.md). Las pruebas automatizadas usan SQLite o una base PostgreSQL temporal; las migraciones no se aplicaron a las bases de trabajo del usuario durante la integración.
