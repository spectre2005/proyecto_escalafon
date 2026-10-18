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
