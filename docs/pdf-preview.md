# Formularios con vista previa de PDF

`PdfPreviewField` muestra un PDF local o el archivo actual obtenido con la sesión de Axios, sin subir la selección hasta guardar. Recibe `name`, `id`, `label`, `help`, `maxMB` (2 por defecto), `currentUrl`, `currentName`, `required`, `error` y `onChange(file, validationMessage)`. Mantén los atributos que ya existan; si un input no tenía `name` o `id`, no los agregues en la adaptación. Usa `FormSplit` dentro de `Formulario`: los datos se pasan como hijos y el componente PDF en la propiedad `pdf`, por ejemplo `<FormSplit pdf={<PdfPreviewField label="Adjuntar PDF (opcional)" help="Máximo 2 MB." onChange={(file, message) => { setPdf(file); setPdfError(message) }} />}><Campo label="Fecha"><input type="date" /></Campo></FormSplit>`. La validación de tipo/tamaño impide enviar archivos inválidos; un fallo al renderizar no impide guardar un PDF válido. Quitar vacía la selección y el panel; en edición el archivo almacenado se conserva porque no se envía otro PDF. El worker se empaqueta localmente con Vite, sin CDN.

## Archivos creados

- `frontend/src/components/pdf/PdfPreviewField.jsx`: selección, PDF actual autenticado, renderizado, navegación, errores y eliminación de selección.
- `frontend/src/components/pdf/FormSplit.jsx`: columnas de datos/PDF; se apilan a 900 px y los campos a 520 px.
- `frontend/src/components/pdf/pdfValidation.js`: valida extensión, MIME y tamaño; informa peso real y máximo.
- `frontend/src/components/pdf/pdfValidation.test.js`: pruebas automáticas de tipo y tamaño.
- `docs/pdf-preview.md`: uso, archivos y verificación con Docker.

## Archivos modificados

- `frontend/package.json`: agrega `pdfjs-dist`.
- `frontend/pnpm-lock.yaml`: fija la dependencia y sus paquetes transitivos.
- `frontend/src/features/legajos/components/seccion01/DeclaracionesPanel.jsx`: diseño compartido y vista previa del PDF actual durante edición.
- `frontend/src/features/legajos/components/seccion01/CertificadosPanel.jsx`: diseño compartido y vista previa del certificado actual durante edición.
- `frontend/src/features/legajos/components/documentos/SubirDocumentoForm.jsx`: diseño compartido para carga documental, conservando la subsanación.
- `frontend/src/features/legajos/components/seccion03/Documento03Form.jsx`: diseño compartido para documentos académicos y sus campos condicionales.

## Docker

Desde la raíz del proyecto:

```sh
docker compose up -d --build
docker compose exec frontend pnpm install --frozen-lockfile
docker compose exec frontend pnpm test
docker compose exec frontend pnpm exec eslint src/components/pdf src/features/legajos/components/seccion01/DeclaracionesPanel.jsx src/features/legajos/components/seccion01/CertificadosPanel.jsx src/features/legajos/components/documentos/SubirDocumentoForm.jsx src/features/legajos/components/seccion03/Documento03Form.jsx
docker compose exec frontend pnpm build
```

Abre `http://localhost:5173`, ingresa con tu sesión y abre un legajo. Prueba Declaraciones y Certificados en Sección 01; Subir documento en Sección 02; Agregar documento en Sección 03. Selecciona PDF de una y varias páginas; verifica Anterior/Siguiente y Quitar. Prueba un archivo de más de 2 MB, uno que no sea PDF, uno protegido con contraseña y uno dañado. Solo los dos primeros deben impedir guardar por validación; los errores de vista previa no bloquean el envío. Edita una declaración y un certificado con adjuntos: comprueba Archivo actual, reemplazo y conservación sin selección nueva. Comprueba 1280, 900, 520 y 375 px y navegación por teclado.

## Alcance

Se adaptaron los cuatro formularios de PDF del inventario. Fotografía y CSV conservan su comportamiento porque no guardan PDF. Los visores de documentos ya almacenados no son formularios de carga. No se modificaron backend, rutas, almacenamiento, contratos de envío ni atributos existentes. No se hicieron commit ni push. Se trabajó en la misma rama por instrucción del usuario. El proyecto no tiene un tema oscuro implementado.

## Verificación realizada

Pasaron 12 pruebas de Node, ESLint en los archivos afectados, la compilación de Vite y `git diff --check`. Una prueba temporal en Chromium, con endpoints de API simulados, verificó PDF de una y varias páginas, límite de tamaño, tipo incorrecto, contraseña, PDF dañado, vista del archivo actual, reemplazo, Quitar y conservación sin archivo nuevo. Comparó los cuerpos multipart completos de declaración y certificado con la versión anterior de la rama: coincidieron normalizando únicamente el delimitador generado por el navegador. También comprobó que seleccionar no hace POST, que un error de vista previa permite guardar, resolución con DPR 2, columnas y ausencia de desbordamiento horizontal a 1280, 900, 520 y 375 px. No se realizaron escrituras de prueba en la base de datos. La compilación conserva el aviso de tamaño del paquete principal; PDF.js se carga bajo demanda en un paquete separado.
