import test from 'node:test'
import assert from 'node:assert/strict'
import { OPCIONES_ZIP, opcionesVisibles, PREDETERMINADAS, seleccionInicial, urlZip } from './expedienteImprimible.js'

const claves = lista => lista.map(opcion => opcion.clave)

test('sin lista de grupos, la URL no envía incluir (el servidor usa las secciones 01 a 04)', () => {
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

test('las secciones 01 a 04 vienen marcadas al abrir, pero solo las que el usuario puede ver', () => {
  const todas = opcionesVisibles({ activas: {}, secciones_ocultas: [] }, true)
  assert.deepEqual(seleccionInicial(todas), PREDETERMINADAS)
  // Sin datos sensibles no se ofrece la sección 01 ni se marca.
  assert.deepEqual(seleccionInicial(opcionesVisibles({ activas: {}, secciones_ocultas: [] }, false)), ['incorporacion', 'formacion', 'experiencia'])
})

test('seleccionar todo envía todos los grupos visibles', () => {
  const todas = claves(opcionesVisibles({ activas: {}, secciones_ocultas: [] }, true))
  const url = new URL(urlZip('u1', todas), 'http://x')
  assert.deepEqual(url.searchParams.getAll('incluir[]'), OPCIONES_ZIP.map(opcion => opcion.clave))
})

test('un legajo concreto (también pasivo) va en la URL', () => {
  assert.equal(urlZip('u1', ['seccion_05'], 'l-9'), '/expedientes/u1/expediente-imprimible/zip?legajo=l-9&incluir%5B%5D=seccion_05')
})

test('un grupo de varias funcionalidades se ofrece si alguna está activa', () => {
  const estado = { activas: { 's02.inducciones': false }, secciones_ocultas: [] }
  assert.ok(claves(opcionesVisibles(estado, true)).includes('incorporacion'))
  const apagado = { activas: { 's02.seleccion': false, 's02.vinculos': false, 's02.inducciones': false }, secciones_ocultas: [] }
  assert.ok(!claves(opcionesVisibles(apagado, true)).includes('incorporacion'))
})
