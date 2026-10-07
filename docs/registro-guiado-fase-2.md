# Registro guiado y relación con el titular — Fase 2

Rama: `feature/registro-guiado-relacion-titular`. Cambios sin commit ni push. No se añadieron dependencias.

## Uso

Abre un expediente con legajo activo y elige **Iniciar registro guiado** sobre el menú de secciones. Para regresar, usa **Continuar donde me quedé**. La posición pertenece al responsable que usa el sistema y al legajo activo.

La lista es la intersección de `legajo_secciones`, el catálogo `secciones_legajo` y `config('legajo.secciones_registro_guiado')`. Se ordena por `secciones_legajo.orden` e ID como desempate. Solo se habilitan las pantallas 01–11, según la decisión del usuario.

Los requisitos reutilizan `PerfilDocumental`, `ExigenciasSeccion01` y las condiciones de subsecciones/formación/reconocimientos compartidas mediante `ExigenciasDocumentales`. No se alteró el guardado de cada sección, sus callbacks ni sus atributos name, id o action. El recorrido abre los botones existentes mediante atributos data.

## Avance y conservación

- Pendiente, cargado, aceptado, observado, en fiscalización y verificado cuentan como registrados cuando la evidencia supera la verificación. Anulado y falso no cuentan. La recepción, fedateo y aprobación no bloquean el registro.
- El servidor comprueba existencia del documento en el legajo activo, archivo en el disco privado configurado, hash SHA-256 válido y coincidente con el contenido, estado de antivirus presente y no infectado, y folios positivos coherentes.
- Una carga sin PDF en la sección 10 se conserva con las reglas existentes, pero no se confirma como evidencia registrada ni produce avance.
- Solo se salta una sección cuyos ítems estén explícitamente marcados como no aplicables. Un catálogo vacío, requisitos por determinar o acceso restringido no se interpretan como no aplica.
- Se permite omitir únicamente secciones con ítems opcionales, tras una confirmación. La omisión guarda una intención de navegación, nunca documentos ficticios ni una marca de completitud.
- La barra y el resumen recalculan su estado. Si luego cambia el catálogo, el perfil, la evidencia o su estado, las exigencias pendientes vuelven a aparecer.
- El avance automático ocurre tras verificar la carga y cerrar el formulario, incluidos los formularios de datos enlazados. La respuesta original llega intacta al formulario.
- El recorrido no modifica documentos, archivos, folios ni estados de revisión. Sus acciones iniciar, verificar, continuar, omitir, anterior e ir se auditan en `auditoria_eventos`.

## Relación con el titular

Nuevas cargas: El propio servidor, Cónyuge, Conviviente, Hijo / Hija, Padre / Madre, Hermano / Hermana, Otro. Otro exige **Especifique la relación**, máximo 150 caracteres, en `documentos_usuario.relacion_titular_otro`.

El código `conyuge_concubino` se conserva en registros anteriores y se muestra como **Cónyuge o concubino(a) (valor anterior)**. No se convierte automáticamente ni se ofrece para nuevas cargas. Los documentos históricos con Otro sin detalle siguen siendo legibles. El parentesco de familiares mantiene su catálogo propio. El PDF de ficha personal usa ese parentesco, no la relación de documentos, y no requiere cambios. Las lecturas por sección y genéricas incluyen el detalle nuevo.

## Permisos y alcance

Iniciar o consultar el recorrido exige `legajo.ver` y `documento.subir`. Se conservan las restricciones de datos sensibles, sección 07 y sanciones de sección 08. Actualmente ADMIN puede recorrer todas las pantallas; los demás usuarios necesitan los permisos vigentes correspondientes. No se crearon roles ni se habilitó acceso del propio servidor sin autorización previa.

Se cuentan documentos del legajo activo. Los documentos históricos permanecen en las consultas existentes y no se trasladan ni duplican. Los catálogos inactivos no añaden obligaciones nuevas al recorrido.

## Docker

Desde la raíz del proyecto, con los archivos .env y dependencias existentes:

```bash
docker compose up -d

docker compose exec -T app php artisan migrate --path=database/migrations/2026_10_07_071417_add_relacion_titular_otro_to_documentos_usuario_table.php --path=database/migrations/2026_10_07_072316_create_recorrido_legajos_table.php --no-interaction

docker compose exec -T -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --compact

docker compose exec -T frontend pnpm test
docker compose exec -T frontend pnpm lint
docker compose exec -T frontend pnpm build
```

API local: `http://localhost:8000`; frontend: `http://localhost:5173`. Las pruebas usan SQLite en memoria. Los overrides de caché y sesión evitan que las variables de Docker, que fijan `file`, contaminen las pruebas de autenticación.

Las migraciones son aditivas y tienen down. Revertirlas elimina el detalle nuevo y la navegación guardada, respectivamente; no elimina documentos. Se probaron up/down en SQLite. No ejecutar migrate:fresh en la base del proyecto. Existen migraciones aplicadas históricamente que no están en el checkout; esta tarea no las reconstruye ni las revierte.

Pint: el comando requerido `vendor/bin/pint --dirty --format agent` no puede funcionar dentro del contenedor porque este no monta Git. Se ejecutó `vendor/bin/pint --format agent` con las rutas explícitas de los PHP cambiados, sin formatear el resto del proyecto.

## Resultados

- Backend: **458 pruebas correctas, 5022 aserciones**, suite completa con caché y sesión aisladas (67,23 segundos).
- Recorrido: **15 pruebas correctas, 134 aserciones** en la ejecución específica; también incluidas en la suite completa.
- Frontend: **36 pruebas correctas**. Incluyen el observador que conserva respuestas y errores de los formularios.
- `pnpm lint`: correcto, sin errores.
- `pnpm build`: correcto. Vite avisa de bundles mayores de 500 KB; no bloquea la compilación.
- Pint: correcto sobre los archivos PHP cambiados, con rutas explícitas por la ausencia de Git en el contenedor.
- `git diff --check`: correcto.
- Las dos migraciones se aplicaron correctamente en PostgreSQL local mediante los paths indicados; no se ejecutaron seeders ni se cambiaron migraciones históricas.
- Antes y después de migrar: **11 documentos y 11 archivos**, con los mismos fingerprints de todos los campos anteriores. La única ampliación en documentos es la columna nullable nueva, inicialmente vacía. No se modificaron sus registros existentes.
- Reversibilidad: up/down verificados automáticamente en SQLite; el rollback no se ejecutó sobre los datos locales.

## Verificación visual y decisiones pendientes

No hay navegador automatizable disponible en este entorno. Queda pendiente la comprobación visual real a 400 px, con teclado y lector de pantalla; la presentación móvil y los anuncios accesibles están implementados, pero no se afirma una prueba de navegador realizada.

La universidad debe decidir si desea crear/asignar un rol adicional para registrar el propio legajo o para asistentes; se mantuvieron los permisos existentes. Las secciones 12 y 13 quedan fuera de este recorrido por decisión expresa del usuario.

## Archivos

| Archivo | Cambio |
| --- | --- |
| `backend/app/Http/Controllers/Api/V1/DocumentoUsuarioController.php` | Alinea la validación del recurso genérico con el catálogo compartido; sus rutas de escritura siguen cerradas. |
| `backend/app/Http/Controllers/Api/V1/Legajos/DocumentoLegajoController.php` | Acepta las siete relaciones y valida el detalle de Otro en la carga existente. |
| `backend/app/Http/Controllers/Api/V1/Legajos/RecorridoLegajoController.php` | Expone la consulta y las acciones del recorrido, con verificación, bloqueo de concurrencia y auditoría. |
| `backend/app/Http/Controllers/Api/V1/Legajos/Seccion01Controller.php` | Incluye el detalle de Otro en los metadatos de lectura. |
| `backend/app/Http/Controllers/Api/V1/Legajos/Seccion02Controller.php` | Incluye el detalle de Otro en los metadatos de lectura. Comparte la condición de aplicabilidad de subsecciones. |
| `backend/app/Http/Controllers/Api/V1/Legajos/Seccion03Controller.php` | Reutiliza la condición de aplicabilidad extraída, manteniendo las reglas y el guardado actuales. |
| `backend/app/Http/Controllers/Api/V1/Legajos/Seccion05Controller.php` | Incluye el detalle de Otro en los metadatos de lectura. |
| `backend/app/Http/Controllers/Api/V1/Legajos/Seccion07Controller.php` | Incluye el detalle de Otro en los metadatos de lectura. Comparte la condición de aplicabilidad de subsecciones. |
| `backend/app/Http/Controllers/Api/V1/Legajos/Seccion08Controller.php` | Reutiliza la condición de aplicabilidad extraída, manteniendo las reglas y el guardado actuales. |
| `backend/app/Http/Controllers/Api/V1/Legajos/Seccion09Controller.php` | Incluye el detalle de Otro en los metadatos de lectura. |
| `backend/app/Http/Controllers/Api/V1/Legajos/Seccion11Controller.php` | Incluye el detalle de Otro en los metadatos de lectura. |
| `backend/app/Models/DocumentoUsuario.php` | Permite persistir el nuevo detalle de relación. |
| `backend/app/Models/RecorridoLegajo.php` | Representa la posición y las omisiones por legajo y usuario responsable. |
| `backend/app/Support/AuditoriaRecorrido.php` | Registra las acciones en auditoria_eventos con cadena de hashes. |
| `backend/app/Support/EstadoRecorridoLegajo.php` | Calcula requisitos, integridad, estado por sección y resumen a partir de la evidencia real. |
| `backend/app/Support/ExigenciasDocumentales.php` | Centraliza condiciones de aplicabilidad compartidas con las pantallas actuales. |
| `backend/app/Support/ExigenciasSeccion01.php` | Comparte la lectura de cadenas de subsanación sin cambiar el cálculo anterior de recepción. |
| `backend/app/Support/RelacionTitular.php` | Define los códigos nuevos y la regla compartida del detalle de Otro. |
| `backend/app/Support/VerificacionRegistroDocumento.php` | Verifica el archivo privado, su hash SHA-256, antivirus, estado y foliación sin modificarlo. |
| `backend/config/legajo.php` | Limita el recorrido a las secciones con pantallas aprobadas 01–11; no fija su orden. |
| `backend/database/factories/RecorridoLegajoFactory.php` | Crea datos aislados de progreso para pruebas. |
| `backend/database/migrations/2026_10_07_071417_add_relacion_titular_otro_to_documentos_usuario_table.php` | Añade una columna nullable de 150 caracteres; down elimina únicamente esa columna. |
| `backend/database/migrations/2026_10_07_072316_create_recorrido_legajos_table.php` | Crea la tabla de navegación; down elimina únicamente esa tabla. |
| `backend/routes/api.php` | Añade GET/POST del recorrido con legajo.ver y documento.subir. |
| `backend/tests/Feature/RecorridoLegajoTest.php` | Prueba configuración, avance, omisión, salto, integridad, reanudación, perfil, familiares, permisos y ausencia de cambios a documentos. |
| `backend/tests/Feature/RelacionTitularTest.php` | Prueba siete opciones, validaciones, compatibilidad heredada y reversibilidad. |
| `backend/tests/Feature/ResourceApiTest.php` | Comprueba que el modelo de navegación no tenga endpoints de CRUD genérico. |
| `backend/tests/Feature/Seccion01DocumentosTest.php` | Actualiza las nuevas cargas de cónyuge a su código específico. |
| `docs/registro-guiado-fase-2.md` | Documenta los cambios, comandos, pruebas y decisiones para revisión. |
| `frontend/src/features/legajos/components/documentos/ListaDocumentos.jsx` | Muestra las etiquetas nuevas, la relación anterior y el detalle de Otro. |
| `frontend/src/features/legajos/components/documentos/SubirDocumentoForm.jsx` | Añade el campo condicional de Otro y su límite sin renombrar controles existentes. |
| `frontend/src/features/legajos/components/recorrido/RegistroGuiado.css` | Adapta las tablas a filas verticales en el modo guiado de hasta 520 px. |
| `frontend/src/features/legajos/components/recorrido/RegistroGuiado.jsx` | Añade la barra, progreso, resumen, controles, mensajes y apertura de formularios existentes. |
| `frontend/src/features/legajos/components/recorrido/observadorCargas.js` | Observa las respuestas de carga sin alterarlas, bloquearlas ni repetirlas. |
| `frontend/src/features/legajos/components/recorrido/observadorCargas.test.js` | Verifica que las respuestas y errores originales lleguen intactos al formulario. |
| `frontend/src/features/legajos/components/seccion01/Seccion01Panel.jsx` | Añade un atributo data al botón de carga para que Agregar ahora abra el formulario existente. Propone elegir explícitamente Cónyuge o Conviviente. |
| `frontend/src/features/legajos/components/seccion02/Seccion02Panel.jsx` | Añade un atributo data al botón de carga para que Agregar ahora abra el formulario existente. |
| `frontend/src/features/legajos/components/seccion03/Seccion03Panel.jsx` | Añade un atributo data al botón de carga para que Agregar ahora abra el formulario existente. |
| `frontend/src/features/legajos/components/seccion04/Seccion04Panel.jsx` | Añade un atributo data al botón de carga para que Agregar ahora abra el formulario existente. |
| `frontend/src/features/legajos/components/seccion05/Seccion05Panel.jsx` | Añade un atributo data al botón de carga para que Agregar ahora abra el formulario existente. |
| `frontend/src/features/legajos/components/seccion06/Seccion06Panel.jsx` | Añade un atributo data al botón de carga para que Agregar ahora abra el formulario existente. |
| `frontend/src/features/legajos/components/seccion07/Seccion07Panel.jsx` | Añade un atributo data al botón de carga para que Agregar ahora abra el formulario existente. |
| `frontend/src/features/legajos/components/seccion08/Seccion08Panel.jsx` | Añade un atributo data al botón de carga para que Agregar ahora abra el formulario existente. |
| `frontend/src/features/legajos/components/seccion09/Seccion09Panel.jsx` | Añade un atributo data al botón de carga para que Agregar ahora abra el formulario existente. |
| `frontend/src/features/legajos/components/seccion10/Seccion10Panel.jsx` | Añade un atributo data al botón de carga para que Agregar ahora abra el formulario existente. |
| `frontend/src/features/legajos/components/seccion11/Seccion11Panel.jsx` | Añade un atributo data al botón de carga para que Agregar ahora abra el formulario existente. |
| `frontend/src/features/legajos/config/documentos.js` | Define las siete etiquetas en orden y conserva la lectura de códigos heredados. |
| `frontend/src/features/legajos/config/documentos.test.js` | Verifica orden de opciones y representación de relaciones anteriores y Otro. |
| `frontend/src/features/legajos/config/recorrido.js` | Resuelve categorías con la configuración de pantallas y exige confirmación del servidor para avanzar. |
| `frontend/src/features/legajos/config/recorrido.test.js` | Prueba destinos de las once pantallas, detección de cargas y espera de formularios enlazados. |
| `frontend/src/features/legajos/pages/LegajosPage.jsx` | Integra el recorrido sobre las pantallas existentes y muestra correctamente relaciones en consultas genéricas. |
