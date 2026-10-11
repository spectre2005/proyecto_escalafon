import assert from 'node:assert/strict'
import test from 'node:test'
import { activa, estadoVacio, seccionesVisibles } from './funcionalidadesEstado.js'

const secciones = [
  { number: '06', title: 'Compensaciones', categories: ['seccion-06', 'compensaciones-beneficios'] },
  { number: '07', title: 'Evaluación', categories: ['s07-desempeno', 's07-progresion', 's07-desplazamiento'], sinDocumentos: true },
  { number: '09', title: 'Relaciones laborales', categories: ['s09-individuales', 's09-colectivas', 's09-afiliacion', 's09-otros'], sinDocumentos: true },
]

test('without configuration nothing is hidden and unknown features count as active', () => {
  assert.deepEqual(seccionesVisibles(secciones, estadoVacio), secciones.map(seccion => ({ ...seccion, sinDocumentos: !!seccion.sinDocumentos })))
  assert.deepEqual(seccionesVisibles(secciones, undefined).map(seccion => seccion.number), ['06', '07', '09'])
  assert.equal(activa(estadoVacio, 's06.compensaciones'), true)
  assert.equal(activa({ activas: { 's06.compensaciones': false } }, 's06.compensaciones'), false)
})

test('hidden sections and inactive entries disappear, other entries stay', () => {
  const estado = { activas: {}, secciones_ocultas: [6], menu_oculto: ['seccion-06', 'compensaciones-beneficios', 'documentos-06', 's07-progresion', 's09-individuales', 's09-colectivas'] }
  const visibles = seccionesVisibles(secciones, estado)
  assert.deepEqual(visibles.map(seccion => seccion.number), ['07', '09'])
  assert.deepEqual(visibles[0].categories, ['s07-desempeno', 's07-desplazamiento'])
  assert.deepEqual(visibles[1].categories, ['s09-afiliacion', 's09-otros'])
})

test('the section documents entry is hidden with its section', () => {
  const [seccion] = seccionesVisibles([{ number: '03', categories: ['seccion-03'] }], { menu_oculto: ['documentos-03'] })
  assert.equal(seccion.sinDocumentos, true)
})
