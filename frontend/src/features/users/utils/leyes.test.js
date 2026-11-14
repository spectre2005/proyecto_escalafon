import assert from 'node:assert/strict'
import test from 'node:test'
import { aliasLey, codigoVisible, conLey, errorLey, leyDelRegistro, leyesDelTipo, leyInicial, leyPorCodigo, nombreLey, SIN_DEFINIR } from './leyes.js'

const catalogo = [
  { id: 'ley-30220', codigo: '30220', nombre: 'Ley N° 30220', aplica_a: 'docente', activo: true },
  { id: 'ley-1057', codigo: '1057', nombre: 'D.L. N° 1057 (CAS)', aplica_a: 'administrativo', activo: true },
  { id: 'ley-276', codigo: '276', nombre: 'D.L. N° 276', aplica_a: 'administrativo', activo: true },
  { id: 'ley-728', codigo: '728', nombre: 'D.L. N° 728', aplica_a: 'administrativo', activo: false },
  { id: 'ley-abc', codigo: 'abc', nombre: 'Código con letras', aplica_a: 'docente', activo: false },
]

test('leyesDelTipo devuelve solo leyes activas del tipo de personal', () => {
  assert.deepEqual(leyesDelTipo(catalogo, 'administrativo').map(ley => ley.id), ['ley-1057', 'ley-276'])
  assert.deepEqual(leyesDelTipo(catalogo, 'docente').map(ley => ley.id), ['ley-30220'])
  assert.deepEqual(leyesDelTipo(catalogo, 'otro'), [])
  assert.deepEqual(leyesDelTipo(null, 'docente'), [])
  assert.deepEqual(leyesDelTipo([], 'docente'), [])
})

test('codigoVisible muestra CAS en lugar de 1057 y el código en los demás casos', () => {
  assert.equal(aliasLey.CAS, '1057')
  assert.equal(codigoVisible({ codigo: '1057' }), 'CAS')
  assert.equal(codigoVisible({ codigo: '276' }), '276')
})

test('leyPorCodigo acepta alias, minúsculas y espacios', () => {
  assert.equal(leyPorCodigo(catalogo, 'CAS').id, 'ley-1057')
  assert.equal(leyPorCodigo(catalogo, ' cas ').id, 'ley-1057')
  assert.equal(leyPorCodigo(catalogo, '1057').id, 'ley-1057')
  assert.equal(leyPorCodigo(catalogo, 'ABC').id, 'ley-abc')
  assert.equal(leyPorCodigo(catalogo, 276).id, 'ley-276')
})

test('leyPorCodigo devuelve null sin código, sin catálogo o con código desconocido', () => {
  assert.equal(leyPorCodigo(catalogo, ''), null)
  assert.equal(leyPorCodigo(catalogo, '   '), null)
  assert.equal(leyPorCodigo(catalogo, null), null)
  assert.equal(leyPorCodigo(catalogo, '9999'), null)
  assert.equal(leyPorCodigo(null, 'CAS'), null)
})

test('leyInicial preselecciona solo cuando hay una única ley posible', () => {
  assert.equal(leyInicial(catalogo, 'docente'), 'ley-30220')
  assert.equal(leyInicial(catalogo, 'administrativo'), '')
  assert.equal(leyInicial(catalogo, ''), '')
  assert.equal(leyInicial(null, 'docente'), '')
})

test('leyDelRegistro prioriza el id del formulario sobre el código de importación', () => {
  assert.equal(leyDelRegistro(catalogo, { regimen_normativo_id: 'ley-276', ley: 'CAS' }).id, 'ley-276')
  assert.equal(leyDelRegistro(catalogo, { ley: 'CAS' }).id, 'ley-1057')
  assert.equal(leyDelRegistro(catalogo, { regimen_normativo_id: 'no-existe' }), null)
  assert.equal(leyDelRegistro(catalogo, {}), null)
  assert.equal(leyDelRegistro(null, { regimen_normativo_id: 'ley-276' }), null)
})

test('errorLey sin catálogo cargado avisa que se está cargando', () => {
  assert.equal(errorLey(undefined, { tipo_personal: 'docente', ley: '30220' }), 'Cargando el catálogo de leyes…')
})

test('errorLey sin ley lista los códigos válidos, o ninguno si el tipo no tiene leyes', () => {
  assert.equal(errorLey(catalogo, { tipo_personal: 'docente' }), 'Indica la ley (régimen normativo): 30220.')
  assert.equal(errorLey(catalogo, { tipo_personal: 'otro', ley: '' }), 'Indica la ley (régimen normativo).')
  assert.equal(errorLey([], { tipo_personal: 'docente', ley: '30220' }), 'Indica la ley (régimen normativo).')
})

test('errorLey informa ley inactiva y tipo de personal que no corresponde', () => {
  assert.equal(errorLey(catalogo, { tipo_personal: 'administrativo', ley: '728' }), 'La ley D.L. N° 728 está inactiva en el catálogo.')
  assert.equal(errorLey(catalogo, { tipo_personal: 'docente', regimen_normativo_id: 'ley-276' }), 'La ley D.L. N° 276 corresponde al personal administrativo, no al docente.')
  assert.equal(errorLey(catalogo, { tipo_personal: 'docente', ley: '30220' }), null)
})

test('nombreLey usa el nombre de la ley o "Sin definir" cuando falta', () => {
  assert.equal(SIN_DEFINIR, 'Sin definir')
  assert.equal(nombreLey({ ley: { nombre: 'Ley N° 30220' } }), 'Ley N° 30220')
  assert.equal(nombreLey({ ley: null }), 'Sin definir')
  assert.equal(nombreLey({}), 'Sin definir')
  assert.equal(nombreLey({ ley: { nombre: '' } }), 'Sin definir')
})

test('conLey reemplaza el código por regimen_normativo_id sin modificar el registro original', () => {
  const registro = { nombres: 'ANA', tipo_personal: 'administrativo', ley: 'cas' }
  const enviado = conLey(catalogo, registro)
  assert.deepEqual(enviado, { nombres: 'ANA', tipo_personal: 'administrativo', regimen_normativo_id: 'ley-1057' })
  assert.equal(registro.ley, 'cas')
  assert.equal(conLey(catalogo, { ley: 'desconocida' }).regimen_normativo_id, null)
  assert.equal(conLey(catalogo, { regimen_normativo_id: 'ley-30220' }).regimen_normativo_id, 'ley-30220')
})
