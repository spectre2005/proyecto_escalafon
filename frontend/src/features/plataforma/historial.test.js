import assert from 'node:assert/strict'
import test from 'node:test'
import { LIMITES, MAXIMOS_BACKEND, acotar, textoConteo } from './historial.js'

const lista = n => Array.from({ length: n }, (_, i) => ({ id: `r${i + 1}` }))
const ids = registros => registros.map(r => r.id)

test('the chosen limits and the known backend maximums', () => {
  assert.deepEqual(LIMITES, { auditoria: 10, respaldos: 3, periodos: 5 })
  assert.deepEqual(MAXIMOS_BACKEND, { auditoria: 30, respaldos: 10 })
})

test('no records: nothing to show and no «Ver todos»', () => {
  assert.deepEqual(acotar([], 10, false), { visibles: [], total: 0, hayMas: false })
  assert.deepEqual(acotar([], 10, true), { visibles: [], total: 0, hayMas: false })
})

test('fewer or as many records as the limit: all of them, without «Ver todos»', () => {
  for (const [n, limite] of [[2, 3], [3, 3], [5, 5], [9, 10], [10, 10]]) {
    const registros = lista(n)
    for (const expandido of [false, true]) {
      const r = acotar(registros, limite, expandido)
      assert.equal(r.hayMas, false)
      assert.equal(r.total, n)
      assert.deepEqual(ids(r.visibles), ids(registros))
    }
  }
})

test('more records than the limit: compact view, expand to all received, collapse back', () => {
  const registros = lista(30)
  const compacta = acotar(registros, 10, false)
  assert.equal(compacta.hayMas, true)
  assert.equal(compacta.total, 30)
  assert.deepEqual(ids(compacta.visibles), ids(registros.slice(0, 10)), 'los 10 primeros (los más recientes), en su orden')
  const completa = acotar(registros, 10, true)
  assert.equal(completa.hayMas, true)
  assert.deepEqual(ids(completa.visibles), ids(registros), 'todos los recibidos, sin duplicados ni omisiones')
  assert.equal(new Set(ids(completa.visibles)).size, 30)
  assert.deepEqual(acotar(registros, 10, false), compacta, 'Mostrar menos vuelve a la misma vista compacta')
})

test('trimming never changes the received list', () => {
  const registros = lista(7)
  const copia = structuredClone(registros)
  acotar(registros, 5, false)
  acotar(registros, 5, true)
  assert.deepEqual(registros, copia)
})

test('a list that grows (onCambio reloads) keeps the newest records in the compact view', () => {
  const antes = lista(10)
  const despues = [{ id: 'nuevo' }, ...antes]
  assert.equal(acotar(antes, 10, false).hayMas, false)
  const r = acotar(despues, 10, false)
  assert.equal(r.hayMas, true)
  assert.deepEqual(ids(r.visibles), ['nuevo', ...ids(antes.slice(0, 9))])
})

test('the count speaks of «the most recent» only when the list reached the backend maximum', () => {
  assert.equal(textoConteo({ visibles: 10, total: 30, nombre: 'eventos', maximo: 30 }), 'Se muestran 10 de los 30 eventos más recientes.')
  assert.equal(textoConteo({ visibles: 30, total: 30, nombre: 'eventos', maximo: 30 }), 'Se muestran los 30 eventos más recientes.')
  assert.equal(textoConteo({ visibles: 10, total: 18, nombre: 'eventos', maximo: 30 }), 'Se muestran 10 de 18 eventos.')
  assert.equal(textoConteo({ visibles: 18, total: 18, nombre: 'eventos', maximo: 30 }), 'Se muestran los 18 eventos.')
  assert.equal(textoConteo({ visibles: 3, total: 10, nombre: 'respaldos', maximo: 10 }), 'Se muestran 3 de los 10 respaldos más recientes.')
  assert.equal(textoConteo({ visibles: 5, total: 40, nombre: 'periodos' }), 'Se muestran 5 de 40 periodos.')
  assert.equal(textoConteo({ visibles: 40, total: 40, nombre: 'periodos' }), 'Se muestran los 40 periodos.')
})
