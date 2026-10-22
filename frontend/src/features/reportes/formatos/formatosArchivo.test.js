import test from 'node:test'
import assert from 'node:assert/strict'
import { createInstitutionalReport, createInstitutionalWorkbook } from './formatosArchivo.js'

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

test('the cover follows Annex 09: confidential personnel file with the person and the position', () => {
  const pdf = createInstitutionalReport({ formato: 'caratula', titulo: 'Carátula del legajo (Anexo 09)', columnas: { numero_legajo: 'N.º de legajo', persona: 'Apellidos y nombres', puesto: 'Nombre del puesto', cese: 'Fecha de cese' },
    data: [{ numero_legajo: 'LEG-001', persona: 'TORRES QUISPE, ANA', puesto: 'ESPECIALISTA', cese: null }], generado_en: '21/10/2026 10:00' }, { name: 'Entidad de prueba' })
  const texto = pdf.output()
  // jsPDF escapa los paréntesis del texto dentro del PDF.
  for (const esperado of ['LEGAJO DE PERSONAL', '\\(CONFIDENCIAL\\)', 'TORRES QUISPE, ANA', 'NOMBRE DEL PUESTO:', 'ESPECIALISTA', 'Generado: 21/10/2026 10:00']) assert.ok(texto.includes(esperado), esperado)
})
