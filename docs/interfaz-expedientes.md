# Ventanas de legajos e incorporación

Las operaciones de los expedientes reutilizan `frontend/src/components/ui/Modal.jsx`, extraído del patrón de SST: ventana amplia, fondo atenuado, encabezado fijo y contenido desplazable. El diálogo nativo mantiene el foco dentro de la ventana; Escape cierra únicamente la ventana activa y devuelve el foco al control de origen. El cierre se bloquea durante el guardado.

- Los formularios compartidos de las secciones 01 a 11, las confirmaciones de anulación y la edición de información personal utilizan esta ventana.
- Los detalles de registros de las categorías genéricas también se abren con este patrón.
- Formación, experiencia, compensaciones y reconocimientos/sanciones presentan acciones de adjuntar y consultar sin desplegar todo el contenido en la ficha.
- Los listados compartidos de documentos abren la consulta en una ventana; los PDF utilizan el mismo contenedor. SST y bienestar reutilizan ahora ese componente.
- Incorporación abre el detalle desde el tablero y agrupa información personal y laboral, resolución, documentos, tareas, historial y gestión. Los contadores muestran información del expediente; no cambian sus reglas de admisión.
- Adjuntar un documento en Incorporación requiere seleccionarlo y confirmar el guardado. La interfaz comprueba extensión, tipo y tamaño (20 MB), sin sustituir la validación del servidor.

## Verificación

En Docker:

```sh
docker compose exec frontend pnpm lint
docker compose exec frontend pnpm build
docker compose exec frontend pnpm test
docker compose -p escalafon_ci -f docker-compose.ci.yml run --rm e2e
```

`frontend/e2e/expedientes.spec.js` prueba apertura y cierre con retorno de foco en formación y SST, y detalle de incorporación en pantalla móvil con adjunto anidado y rechazo de archivos inválidos. Usa API simulada; no carga documentos ni modifica personas reales.

## Archivos de la intervención

- Componente nuevo: `frontend/src/components/ui/Modal.jsx`.
- Legajos: `GeneralDataEditor.jsx`, `documentos/DocumentoDesplegable.jsx` (nuevo), `documentos/ListaDocumentos.jsx`, `documentos/VisorPdf.jsx`, `seccion01/Campo.jsx`, `seccion01/RegistrosPanel.jsx`, paneles de las secciones 03/04/06/08/10 y `pages/LegajosPage.jsx`.
- Incorporación: `frontend/src/features/incorporation/IncorporacionPage.jsx`.
- Pruebas nuevas: `frontend/e2e/expedientes.html` y `frontend/e2e/expedientes.spec.js`.

Resultados locales: lint y compilación correctos, 28 pruebas unitarias y 9 recorridos de navegador aprobados. La compilación mantiene el aviso preexistente sobre paquetes de más de 500 kB.
