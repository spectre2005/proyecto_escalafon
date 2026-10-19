import assert from 'node:assert/strict'
import test from 'node:test'
import { activa, conModulosOcultos, estadoVacio, primeraCategoria, seccionesVisibles } from './funcionalidadesEstado.js'

const secciones = [
  { number: '06', title: 'Compensaciones', categories: ['seccion-06'] },
  { number: '07', title: 'Evaluación', categories: ['s07-desempeno', 's07-progresion', 's07-desplazamiento'] },
  { number: '09', title: 'Relaciones laborales', categories: ['s09-individuales', 's09-colectivas', 's09-afiliacion', 's09-otros'] },
]

test('without configuration nothing is hidden and unknown features count as active', () => {
  assert.deepEqual(seccionesVisibles(secciones, estadoVacio), secciones)
  assert.deepEqual(seccionesVisibles(secciones, undefined).map(seccion => seccion.number), ['06', '07', '09'])
  assert.equal(activa(estadoVacio, 's06.compensaciones'), true)
  assert.equal(activa({ activas: { 's06.compensaciones': false } }, 's06.compensaciones'), false)
})

test('hidden sections and inactive entries disappear, other entries stay', () => {
  const estado = { activas: {}, secciones_ocultas: [6], menu_oculto: ['seccion-06', 's07-progresion', 's09-individuales', 's09-colectivas'] }
  const visibles = seccionesVisibles(secciones, estado)
  assert.deepEqual(visibles.map(seccion => seccion.number), ['07', '09'])
  assert.deepEqual(visibles[0].categories, ['s07-desempeno', 's07-desplazamiento'])
  assert.deepEqual(visibles[1].categories, ['s09-afiliacion', 's09-otros'])
})

const seccion01 = [{ number: '01', title: 'Información personal', categories: ['general', 's01-familia', 's01-discapacidad'] }]

test('a module is visible only if its feature is active and its flag does not hide it', () => {
  const categorias = (estado, ocultos) => seccionesVisibles(seccion01, conModulosOcultos(estado, ocultos))[0].categories
  assert.deepEqual(categorias(estadoVacio, ['s01-discapacidad']), ['general', 's01-familia'])
  assert.deepEqual(categorias({ menu_oculto: ['s01-familia'] }, ['s01-discapacidad']), ['general'])
  assert.deepEqual(categorias({ menu_oculto: ['s01-familia'] }, []), ['general', 's01-discapacidad'])
  assert.deepEqual(categorias(undefined, undefined), ['general', 's01-familia', 's01-discapacidad'])
})

test('a hidden module falls back to the first available entry', () => {
  assert.equal(primeraCategoria(seccionesVisibles(seccion01, conModulosOcultos(estadoVacio, ['s01-familia']))), 'general')
  assert.equal(primeraCategoria(seccionesVisibles(secciones, { menu_oculto: ['seccion-06'], secciones_ocultas: [6] })), 's07-desempeno')
  assert.equal(primeraCategoria([]), 'general')
})
