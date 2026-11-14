import assert from 'node:assert/strict'
import test from 'node:test'
import { excelFields, parseUsersFile, resolveUsersLocations } from './usuariosExcel.js'
import { columns } from './usuariosCsv.js'

const ubicaciones = [
  { id: 'ubigeo-1', region: 'Cusco', provincia: 'La Convención', distrito: 'Santa Ana' },
  { id: 'ubigeo-2', region: 'Áncash', provincia: 'Huaraz', distrito: 'Independencia' },
]

test('las columnas de la plantilla incluyen todas las del CSV y no se repiten', () => {
  const claves = excelFields.map(([key]) => key)
  assert.equal(new Set(claves).size, claves.length)
  for (const columna of columns) assert.ok(claves.includes(columna), columna)
  assert.deepEqual(claves.slice(-2), ['fecha_nacimiento', 'domicilio'])
})

test('resolveUsersLocations rellena columnas faltantes y pasa nombres a mayúsculas', () => {
  const [user] = resolveUsersLocations([{ nombres: 'josé', apellido_paterno: 'ñañez', tipo_personal: 'Docente', ley: '30220' }], [])
  assert.equal(user.nombres, 'JOSÉ')
  assert.equal(user.apellido_paterno, 'ÑAÑEZ')
  assert.equal(user.apellido_materno, '')
  assert.equal(user.email_institucional, '')
  assert.equal(user.tipo_personal, 'docente')
  assert.deepEqual(Object.keys(user).sort(), [...columns].sort())
})

test('resolveUsersLocations compara la ubicación sin acentos, mayúsculas ni espacios', () => {
  const [user] = resolveUsersLocations([{ region: ' ANCASH ', provincia: 'huaraz', distrito: 'INDEPENDENCIA' }], ubicaciones)
  assert.equal(user.ubigeo_id, 'ubigeo-2')
  assert.equal('region' in user, false)
})

test('resolveUsersLocations informa el número de registro con ubicación incompleta o inexistente', () => {
  const ok = { region: 'Cusco', provincia: 'La Convención', distrito: 'Santa Ana' }
  assert.throws(() => resolveUsersLocations([ok, { region: 'Cusco', provincia: 'La Convención' }], ubicaciones), /Registro 2: completa región, provincia y distrito juntos/)
  assert.throws(() => resolveUsersLocations([{ ...ok, distrito: 'Otro' }], ubicaciones), /Registro 1: la ubicación no existe/)
})

test('resolveUsersLocations con lista vacía devuelve lista vacía', () => {
  assert.deepEqual(resolveUsersLocations([], ubicaciones), [])
})

test('parseUsersFile rechaza extensiones distintas de .xlsx o .csv', async () => {
  for (const name of ['personal.xls', 'personal.txt', 'personal', 'personal.csv.pdf']) {
    await assert.rejects(parseUsersFile({ name }), /Selecciona un archivo Excel \(\.xlsx\) o CSV \(\.csv\)/, name)
  }
})

test('parseUsersFile lee un CSV sin importar mayúsculas en la extensión', async () => {
  const texto = `${columns.join(',')}\n99000001,Ana,Ejemplo,,ana@example.test,docente,30220,`
  const [registro] = await parseUsersFile({ name: 'PERSONAL.CSV', text: async () => texto })
  assert.equal(registro.numero_doc_identidad, '99000001')
})
