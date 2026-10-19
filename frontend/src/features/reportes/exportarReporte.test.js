import test from 'node:test'
import assert from 'node:assert/strict'
import ExcelJS from 'exceljs'
import { crearExcelReporte, crearPdfReporte, describirFiltros } from './exportarReporte.js'

const institucion = { name: 'Institución de prueba', reportFooter: 'Uso institucional' }
const reporte = {
  columnas: [{ key: 'nombre', label: 'Persona', type: 'text' }, { key: 'documento', label: 'DNI', type: 'text' }, { key: 'cantidad', label: 'Cantidad', type: 'number' }],
  data: Array.from({ length: 75 }, (_, i) => ({ nombre: `Persona ${i + 1}`, documento: '00123456', cantidad: i })),
  meta: { generado_en: '2026-10-18T03:00:00Z', generado_por: 'Responsable', titulo: 'Personal', total: 75, alcance: 'Valores declarados en el registro institucional.' },
}

test('Excel conserva todos los resultados, ceros iniciales y valores numéricos', async () => {
  const libro = new ExcelJS.Workbook()
  await libro.xlsx.load(await crearExcelReporte(reporte, institucion, 'Sin filtros'))
  const hoja = libro.getWorksheet('Reporte')
  assert.equal(hoja.rowCount, 81)
  assert.equal(hoja.getCell('B7').value, '00123456')
  assert.equal(hoja.getCell('C81').value, 74)
  assert.match(hoja.getCell('A3').value, /17\/10\/2026/)
  assert.equal(hoja.views[0].ySplit, 6)
  assert.equal(libro.getWorksheet('Resumen y alcance').getCell('B4').value, reporte.meta.alcance)
})

test('PDF pagina todos los resultados e incluye la última persona', async () => {
  const pdf = await crearPdfReporte(reporte, institucion, 'Sin filtros')
  assert.ok(pdf.getNumberOfPages() > 1)
  const contenido = pdf.output()
  assert.ok(contenido.startsWith('%PDF-'))
  assert.ok(contenido.includes('Persona 75'))
  assert.ok(contenido.includes('Valores declarados'))
})

test('Los filtros exportados usan etiquetas comprensibles', () => {
  const texto = describirFiltros({ unidad_id: 'abc', estado: 'faltante', desde: '2026-10-01', dias: 30 }, {
    unidades: [{ id: 'abc', nombre: 'Recursos Humanos' }], secciones: [], reporte: { estados: { faltante: 'Falta documento' } },
  })
  assert.equal(texto, 'Unidad: Recursos Humanos · Estado: Falta documento · Desde: 01/10/2026 · Horizonte: 30 días')
})

test('all table report pages use the configured institutional presentation', async () => {
  const logo = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWPkL8tjYGBgYmBgYGBgAAALLwD3dKaQQgAAAABJRU5ErkJggg=='
  const pdf = await crearPdfReporte(reporte, { ...institucion, area: 'Equipo de escalafon', reportTitle: 'Reporte oficial', reportColor: '#123456', logo,
    address: 'Direccion de prueba', ruc: '12345678901', email: 'equipo@example.test' }, 'Sin filtros')
  assert.ok(pdf.output().includes('/Subtype /Image'))
  assert.ok(pdf.getNumberOfPages() > 1)
  for (let page = 1; page <= pdf.getNumberOfPages(); page++) {
    const text = pdf.internal.pages[page].join('\n')
    for (const expected of ['REPORTE OFICIAL', 'Equipo de escalafon', 'Personal', 'Uso institucional', 'Direccion de prueba', '12345678901', 'equipo@example.test']) assert.ok(text.includes(expected), `${expected} on page ${page}`)
  }
})

test('table reports respect hidden logo, emission date and page numbers', async () => {
  const pdf = await crearPdfReporte(reporte, { ...institucion, showLogo: false, showEmissionDate: false, showPageNumbers: false }, 'Sin filtros')
  const output = pdf.output()
  assert.ok(!output.includes('/Subtype /Image'))
  assert.ok(!output.includes('Fecha de'))
  assert.ok(!output.includes(' de 1'))
  assert.ok(output.includes('Persona 75'))
})


test('long cells continue on branded pages without dropping the last text', async () => {
  const pdf = await crearPdfReporte({ ...reporte, columnas: [{ key: 'detalle', label: 'Detalle', type: 'text' }],
    data: [{ detalle: `${'Detalle de prueba. '.repeat(6000)}FIN_LARGO` }], meta: { ...reporte.meta, total: 1 } }, institucion, 'Sin filtros')
  assert.ok(pdf.getNumberOfPages() > 3)
  assert.ok(pdf.output().includes('FIN_LARGO'))
  for (let page = 1; page <= pdf.getNumberOfPages(); page++) assert.ok(pdf.internal.pages[page].join('\n').includes('Uso institucional'))
})
