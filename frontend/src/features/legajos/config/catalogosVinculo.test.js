import { test } from 'node:test'
import assert from 'node:assert/strict'
import { elegirUnidad, esNuevo, normalizar, puestosDelTipo, textoUnidad } from './catalogosVinculo.js'

const puestos = [{ id: '1', nombre: 'Analista', tipo_personal: 'administrativo' }, { id: '2', nombre: 'Docente contratado', tipo_personal: 'docente' }]
const unidades = [{ id: 'u1', nombre: 'Oficina de Tecnología', activo: true }, { id: 'u2', nombre: 'Decanato', activo: false }]

test('normaliza sin mayúsculas, tildes ni espacios repetidos', () => {
  assert.equal(normalizar('  Oficina   de tecnología '), 'OFICINA DE TECNOLOGIA')
  assert.equal(normalizar(null), '')
})

test('la lista de puestos se filtra por tipo de personal y empieza vacía', () => {
  assert.deepEqual(puestosDelTipo(puestos, 'administrativo'), ['Analista'])
  assert.deepEqual(puestosDelTipo(puestos, ''), [])
  assert.deepEqual(puestosDelTipo(undefined, 'docente'), [])
})

test('un puesto es nuevo solo si no coincide con la lista', () => {
  assert.equal(esNuevo('ANÁLISTA', ['Analista']), false)
  assert.equal(esNuevo('Asesor', ['Analista']), true)
  assert.equal(esNuevo('  ', ['Analista']), false)
  assert.equal(esNuevo('Asesor', []), true)
})

test('la unidad escrita se elige por id si existe activa; si no, se envía el texto', () => {
  assert.deepEqual(elegirUnidad('OFICINA DE TECNOLOGIA', unidades), { unidad_organica_id: 'u1', unidad_organica_nombre: '' })
  assert.deepEqual(elegirUnidad('Unidad nueva', unidades), { unidad_organica_id: '', unidad_organica_nombre: 'Unidad nueva' })
  // Una desactivada no se elige por id: el backend decide (la acepta solo si ya era la del vínculo).
  assert.deepEqual(elegirUnidad('DECANATO', unidades), { unidad_organica_id: '', unidad_organica_nombre: 'DECANATO' })
  assert.deepEqual(elegirUnidad('', unidades), { unidad_organica_id: '', unidad_organica_nombre: '' })
})

test('el campo de unidad muestra la elegida o lo escrito', () => {
  assert.equal(textoUnidad({ unidad_organica_id: 'u2', unidad_organica_nombre: '' }, unidades), 'Decanato')
  assert.equal(textoUnidad({ unidad_organica_id: '', unidad_organica_nombre: 'NUEVA' }, unidades), 'NUEVA')
  assert.equal(textoUnidad({ unidad_organica_id: '', unidad_organica_nombre: '' }, unidades), '')
})
