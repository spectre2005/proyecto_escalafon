import assert from 'node:assert/strict'
import test from 'node:test'
import ExcelJS from 'exceljs'
import { createUsersTemplate, parseUsersExcel, resolveUsersLocations } from './usuariosExcel.js'
import { parseUsersCsv, validateUser } from './usuariosCsv.js'
import { conLey, errorLey } from './leyes.js'

const catalogo = [
  { id: 'ley-30220', codigo: '30220', nombre: 'Ley N° 30220 - Ley Universitaria', aplica_a: 'docente', activo: true },
  { id: 'ley-1057', codigo: '1057', nombre: 'Decreto Legislativo N° 1057 (CAS)', aplica_a: 'administrativo', activo: true },
  { id: 'ley-276', codigo: '276', nombre: 'Decreto Legislativo N° 276', aplica_a: 'administrativo', activo: true },
  { id: 'ley-inactiva', codigo: '999', nombre: 'Ley inactiva', aplica_a: 'administrativo', activo: false },
]

test('Excel template round trip preserves identifiers and validates registration data', async () => {
  const book = new ExcelJS.Workbook()
  await book.xlsx.load(await createUsersTemplate(catalogo))
  const sheet = book.getWorksheet('Personal')
  assert.equal(sheet.getCell('A2').numFmt, '@')
  assert.equal(sheet.getCell('G2').dataValidation.type, 'list')
  assert.deepEqual(sheet.getCell('H2').dataValidation.formulae, ['IF(G2="docente","30220","CAS,276")'])
  assert.ok(book.getWorksheet('Instrucciones'))
  sheet.addRow(['00123456', 'Ana', 'Ejemplo', '', 'ana@example.test', '012345678', 'docente', '30220', 'Cusco', 'La Convención', 'Santa Ana'])
  const records = await parseUsersExcel(await book.xlsx.writeBuffer())
  const users = resolveUsersLocations(records, [{ id: 'location-id', region: 'Cusco', provincia: 'La Convención', distrito: 'Santa Ana' }])
  assert.equal(users.length, 1)
  assert.equal(users[0].numero_doc_identidad, '00123456')
  assert.equal(users[0].telefono, '012345678')
  assert.equal(users[0].ubigeo_id, 'location-id')
  assert.deepEqual(validateUser(users[0], catalogo), [])
  assert.equal(conLey(catalogo, users[0]).regimen_normativo_id, 'ley-30220')
  assert.equal('ley' in conLey(catalogo, users[0]), false)
  assert.equal('region' in users[0], false)
})

test('rejects empty templates, formulas, and altered headers', async () => {
  const buffer = await createUsersTemplate(catalogo)
  await assert.rejects(parseUsersExcel(buffer), /no contiene usuarios/)
  const book = new ExcelJS.Workbook()
  await book.xlsx.load(buffer)
  const sheet = book.getWorksheet('Personal')
  sheet.getCell('A2').value = { formula: '1+1', result: 2 }
  await assert.rejects(parseUsersExcel(await book.xlsx.writeBuffer()), /sin fórmulas/)
  sheet.getCell('A1').value = 'Otro encabezado'
  await assert.rejects(parseUsersExcel(await book.xlsx.writeBuffer()), /encabezados/)
})

test('requires complete catalog locations and normalizes personnel options', () => {
  assert.throws(() => resolveUsersLocations([{ region: 'Cusco' }], []), /juntos/)
  assert.throws(() => resolveUsersLocations([{ region: 'Cusco', provincia: 'X', distrito: 'X' }], []), /no existe/)
  const [user] = resolveUsersLocations([{ tipo_personal: 'ADMINISTRATIVO', ley: 'cas' }], [])
  assert.equal(user.tipo_personal, 'administrativo')
  assert.equal(user.ley, 'CAS')
  assert.equal(conLey(catalogo, user).regimen_normativo_id, 'ley-1057')
})

test('the law comes from the catalog and must match the personnel type', () => {
  assert.equal(errorLey(catalogo, { tipo_personal: 'administrativo', ley: '1057' }), null)
  assert.equal(errorLey(catalogo, { tipo_personal: 'administrativo', regimen_normativo_id: 'ley-276' }), null)
  assert.match(errorLey(catalogo, { tipo_personal: 'docente', ley: 'CAS' }), /corresponde al personal administrativo/)
  assert.match(errorLey(catalogo, { tipo_personal: 'administrativo', ley: '999' }), /inactiva/)
  assert.equal(errorLey(catalogo, { tipo_personal: 'administrativo', ley: '' }), 'Indica la ley (régimen normativo): CAS, 276.')
  assert.match(errorLey(null, { tipo_personal: 'docente', ley: '30220' }), /Cargando/)
})

test('older CSV files with the regimen_laboral column still import the law', () => {
  const [registro] = parseUsersCsv('numero_doc_identidad,nombres,apellido_paterno,apellido_materno,email_institucional,tipo_personal,regimen_laboral,telefono\n99000001,Ana,Ejemplo,,ana@example.test,administrativo,CAS,')
  assert.equal(registro.ley, 'CAS')
})
