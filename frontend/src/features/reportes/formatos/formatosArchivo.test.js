import test from 'node:test'
import assert from 'node:assert/strict'
import { NORMA_FORMATOS, createInstitutionalReport, createInstitutionalWorkbook } from './formatosArchivo.js'

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

test('the cover follows Annex 09: framed confidential file with the person, position and organic unit, without emission data', () => {
  const columnas = { numero_legajo: 'N.º de legajo', persona: 'Apellidos y nombres', puesto: 'Nombre del puesto', ingreso: 'Fecha de ingreso', cese: 'Fecha de cese',
    documento_identidad: 'N.º de DNI', unidad: 'Órgano/Unidad' }
  const pdf = createInstitutionalReport({ formato: 'caratula', titulo: 'Carátula del legajo (Anexo 09)', columnas,
    data: [{ numero_legajo: 'LEG-001', persona: 'TORRES QUISPE, ANA', puesto: 'ESPECIALISTA', ingreso: '01/03/2020', cese: null, documento_identidad: '00123456', unidad: 'UNIDAD DE PRUEBA' }],
    generado_en: '21/10/2026 10:00' }, { name: 'Entidad de prueba', area: 'Oficina de personal' })
  assert.equal(pdf.getNumberOfPages(), 1)
  const texto = pdf.output()
  // jsPDF escapa los paréntesis del texto dentro del PDF.
  for (const esperado of ['Entidad de prueba', 'LEGAJO DE PERSONAL', '\\(CONFIDENCIAL\\)', 'TORRES QUISPE, ANA', 'NOMBRE DEL PUESTO', 'ESPECIALISTA', 'GANO/UNIDAD', 'UNIDAD DE PRUEBA', '00123456'])
    assert.ok(texto.includes(esperado), esperado)
  // Una portada no lleva fecha de emisión, conteo de registros ni paginación; una fecha de cese vacía queda en blanco.
  for (const ausente of ['21/10/2026', 'registro(s)', '1 / 1', 'Página', '—']) assert.ok(!texto.includes(ausente), ausente)
})

test('tabular annexes use the institutional presentation and repeat the table header on every page', () => {
  const columnas = { numero_legajo: 'N.º de legajo', persona: 'Apellidos y nombres', documento: 'Documento incorporado', folios: 'Folios' }
  const data = Array.from({ length: 60 }, (_, i) => ({ numero_legajo: `LEG-${i}`, persona: `PERSONA FICTICIA ${i}`, documento: 'RESOLUCION', folios: '1-2' }))
  const pdf = createInstitutionalReport({ formato: 'incorporaciones', titulo: 'Control de incorporaciones (Anexo 06)', columnas, data, generado_en: '21/10/2026 10:00' },
    { name: 'Entidad de prueba', area: 'Oficina de personal', ruc: '12345678901', reportFooter: 'Uso interno' })
  assert.ok(pdf.getNumberOfPages() > 1)
  assert.equal(pdf.internal.pageSize.getWidth().toFixed(0), '297')
  for (let n = 1; n <= pdf.getNumberOfPages(); n++) {
    const texto = pdf.internal.pages[n].join('\n')
    for (const esperado of ['Entidad de prueba', 'Oficina de personal', 'CONTROL DE INCORPORACIONES', NORMA_FORMATOS, 'Apellidos y nombres', 'Uso interno', '12345678901', `Página ${n} de`])
      assert.ok(texto.includes(esperado), `${esperado} en la página ${n}`)
  }
  const todo = pdf.output()
  assert.ok(todo.includes('Fecha de emisi') && todo.includes('21/10/2026 10:00'))
  assert.ok(todo.includes('PERSONA FICTICIA 59'))
  // Correlativo de filas.
  assert.ok(todo.includes('(60) Tj'))
})

test('an annex with many columns goes to A3 and widens the person column', () => {
  const columnas = Object.fromEntries(Array.from({ length: 14 }, (_, i) => [i === 0 ? 'persona' : `campo_${i}`, `Campo ${i}`]))
  const pdf = createInstitutionalReport({ formato: 'referencias', titulo: 'Referencias (Anexo 07)', columnas, data: [{ persona: 'A' }] }, { name: 'Entidad de prueba' })
  assert.equal(pdf.internal.pageSize.getWidth().toFixed(0), '420')
  const anchos = [...pdf.internal.pages[1].join('\n').matchAll(/[\d.]+ [\d.]+ ([\d.]+) -?[\d.]+ re/g)].map(m => Number(m[1]))
  assert.ok(Math.max(...anchos.slice(0, 15)) > Math.min(...anchos.slice(0, 15)) * 3)
})
