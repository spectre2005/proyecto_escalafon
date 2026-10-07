import assert from 'node:assert/strict'
import test from 'node:test'
import ExcelJS from 'exceljs'
import { createUsersTemplate, parseUsersExcel, resolveUsersLocations } from './usuariosExcel.js'
import { validateUser } from './usuariosCsv.js'

test('Excel template round trip preserves identifiers and validates registration data', async () => {
  const book = new ExcelJS.Workbook()
  await book.xlsx.load(await createUsersTemplate())
  const sheet = book.getWorksheet('Personal')
  assert.equal(sheet.getCell('A2').numFmt, '@')
  assert.equal(sheet.getCell('G2').dataValidation.type, 'list')
  assert.ok(book.getWorksheet('Instrucciones'))
  sheet.addRow(['00123456', 'Ana', 'Ejemplo', '', 'ana@example.test', '012345678', 'docente', '30220', 'Cusco', 'La Convención', 'Santa Ana'])
  const records = await parseUsersExcel(await book.xlsx.writeBuffer())
  const users = resolveUsersLocations(records, [{ id: 'location-id', region: 'Cusco', provincia: 'La Convención', distrito: 'Santa Ana' }])
  assert.equal(users.length, 1)
  assert.equal(users[0].numero_doc_identidad, '00123456')
  assert.equal(users[0].telefono, '012345678')
  assert.equal(users[0].ubigeo_id, 'location-id')
  assert.deepEqual(validateUser(users[0]), [])
  assert.equal('region' in users[0], false)
})

test('rejects empty templates, formulas, and altered headers', async () => {
  const buffer = await createUsersTemplate()
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
  const [user] = resolveUsersLocations([{ tipo_personal: 'ADMINISTRATIVO', regimen_laboral: 'cas' }], [])
  assert.equal(user.tipo_personal, 'administrativo')
  assert.equal(user.regimen_laboral, 'CAS')
})
