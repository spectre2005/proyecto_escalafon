import assert from 'node:assert/strict'
import test from 'node:test'
import { cambiosDeVista, repartir, vistaDe, vistaInicial } from './vistasDocumentales.js'

test('only required, observed or undetermined documents look like pending work', () => {
  assert.equal(vistaDe({ exigido: true, cantidad: 0 }), 'pendientes')
  assert.equal(vistaDe({ exigido: null, cantidad: 0 }), 'pendientes')
  assert.equal(vistaDe({ exigido: false, cantidad: 0 }), 'opcionales')
  assert.equal(vistaDe({ cantidad: 0 }), 'opcionales')
  assert.equal(vistaDe({ exigido: true, cantidad: 1 }), 'conDocumentos')
  assert.equal(vistaDe({ exigido: false, cantidad: 2, observado: true }), 'pendientes')
})

test('what does not apply goes apart, even with old documents', () => {
  assert.equal(vistaDe({ aplica: false, exigido: true, cantidad: 0 }), 'noCorresponde')
  assert.equal(vistaDe({ aplica: false, cantidad: 3 }), 'noCorresponde')
})

test('every element lands in exactly one view and the first useful view opens', () => {
  const elementos = [{ id: 'a', v: 'opcionales' }, { id: 'b', v: 'conDocumentos' }, { id: 'c', v: 'opcionales' }]
  const grupos = repartir(elementos, e => e.v)
  assert.deepEqual(Object.values(grupos).flat().map(e => e.id).sort(), ['a', 'b', 'c'])
  assert.equal(vistaInicial(grupos), 'conDocumentos')
  assert.equal(vistaInicial(repartir([{ v: 'pendientes' }, { v: 'conDocumentos' }], e => e.v)), 'pendientes')
  assert.equal(vistaInicial(repartir([], e => e.v)), 'pendientes')
})

test('after an upload the move between views is detected', () => {
  assert.deepEqual(cambiosDeVista(null, { a: 'pendientes' }), [])
  assert.deepEqual(cambiosDeVista({ a: 'pendientes', b: 'opcionales' }, { a: 'conDocumentos', b: 'opcionales', c: 'pendientes' }), [{ id: 'a', de: 'pendientes', a: 'conDocumentos' }])
})

test('items of the sections with their own form use the same views', async () => {
  const { vistaDeItem } = await import('./vistasDocumentales.js')
  assert.equal(vistaDeItem({ estado: 'falta', exigido: true, documentos: [] }), 'pendientes')
  assert.equal(vistaDeItem({ estado: 'por_determinar', exigido: null, documentos: [] }), 'pendientes')
  assert.equal(vistaDeItem({ estado: 'observado', exigido: false, documentos: [{ estado: 'observado' }] }), 'pendientes')
  assert.equal(vistaDeItem({ estado: 'pendiente', exigido: true, documentos: [{ estado: 'pendiente' }] }), 'conDocumentos')
  assert.equal(vistaDeItem({ estado: 'sin_documentos', cantidad: 0 }), 'opcionales')
  assert.equal(vistaDeItem({ estado: 'aceptado', cantidad: 2 }), 'conDocumentos')
  // Solo anulados: no hay documento cargado.
  assert.equal(vistaDeItem({ estado: 'falta', exigido: true, documentos: [{ anulado: true }] }), 'pendientes')
  assert.equal(vistaDeItem({ estado: 'no_aplica', exigido: true, documentos: [] }), 'noCorresponde')
  assert.equal(vistaDeItem({ estado: 'falta', exigido: true, documentos: [] }, false), 'noCorresponde')
})

test('an item that leaves the current view stays visible until the view changes', async () => {
  const { visiblesEnVista } = await import('./vistasDocumentales.js')
  const elementos = [{ id: 'a', v: 'opcionales' }, { id: 'b', v: 'conDocumentos' }, { id: 'c', v: 'opcionales' }]
  const clasificar = e => e.v
  assert.deepEqual(visiblesEnVista(elementos, clasificar, 'opcionales').map(e => e.id), ['a', 'c'])
  // «b» acaba de pasar a Con documentos mientras se miraban los opcionales: sigue en su sitio.
  assert.deepEqual(visiblesEnVista(elementos, clasificar, 'opcionales', ['b']).map(e => e.id), ['a', 'b', 'c'])
})
