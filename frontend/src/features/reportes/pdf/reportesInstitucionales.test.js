import test from 'node:test'
import assert from 'node:assert/strict'
import { createInstitutionalReport, createInstitutionalWorkbook } from './reportesInstitucionales.js'

test('PDF preserves long fields across pages and repeats institutional identity', () => {
  const texto = `${'Antecedente extenso '.repeat(800)}FIN-DEL-DOCUMENTO`
  const pdf = createInstitutionalReport({ titulo: 'Control de documentos', columnas: { documento: 'Documento', folios: 'Folios' }, data: [{ documento: texto, folios: '001–002' }], generado_en: '2026-10-17T12:00:00Z' }, { name: 'Entidad de prueba' })
  assert.ok(pdf.getNumberOfPages() > 1)
  assert.ok(pdf.output().includes('FIN-DEL-DOCUMENTO'))
  for (let n = 1; n <= pdf.getNumberOfPages(); n++) assert.ok(pdf.internal.pages[n].join('\n').includes('Entidad de prueba'))
})

test('Excel keeps identity leading zeroes and formula-like content as plain text', async () => {
  const buffer = await createInstitutionalWorkbook({ titulo: 'Matriz', columnas: { dni: 'DNI', nombre: 'Nombre' }, data: [{ dni: '00123456', nombre: '=HYPERLINK("ejemplo")' }] })
  const { default: ExcelJS } = await import('exceljs')
  const libro = new ExcelJS.Workbook(); await libro.xlsx.load(buffer)
  assert.equal(libro.worksheets[0].getCell('A2').value, '00123456')
  assert.equal(libro.worksheets[0].getCell('B2').value, '=HYPERLINK("ejemplo")')
  assert.equal(libro.worksheets[0].getCell('B2').type, ExcelJS.ValueType.String)
})
