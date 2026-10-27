import test from 'node:test'
import assert from 'node:assert/strict'
import { OPCIONES_ZIP, opcionesVisibles, urlZip } from './expedienteImprimible.js'

const claves = lista => lista.map(opcion => opcion.clave)

test('sin marcar nada se descargan solo las secciones 01 a 04', () => {
  assert.equal(urlZip('u1'), '/expedientes/u1/expediente-imprimible/zip')
  assert.equal(urlZip('u1', []), '/expedientes/u1/expediente-imprimible/zip')
})

test('solo se envían los grupos marcados, en un orden estable y sin claves desconocidas', () => {
  assert.equal(urlZip('u1', ['seccion_05', 'familiares', 'otra']), '/expedientes/u1/expediente-imprimible/zip?incluir%5B%5D=familiares&incluir%5B%5D=seccion_05')
})

test('se ocultan las funcionalidades desactivadas y las secciones ocultas de la institución', () => {
  const estado = { activas: { 's01.familiares': false, 's02.periodos_prueba': true }, secciones_ocultas: [6, 11] }
  const visibles = claves(opcionesVisibles(estado, true))
  assert.ok(!visibles.includes('familiares'))
  assert.ok(!visibles.includes('seccion_06'))
  assert.ok(!visibles.includes('seccion_11'))
  assert.ok(visibles.includes('periodo_prueba'))
  assert.ok(visibles.includes('seccion_05'))
})

test('sin datos sensibles no se ofrecen familiares, discapacidad ni las secciones 09 y 10', () => {
  const visibles = claves(opcionesVisibles({ activas: {}, secciones_ocultas: [] }, false))
  for (const clave of ['familiares', 'discapacidad', 'seccion_09', 'seccion_10']) assert.ok(!visibles.includes(clave), clave)
  assert.equal(claves(opcionesVisibles({ activas: {}, secciones_ocultas: [] }, true)).length, OPCIONES_ZIP.length)
})
