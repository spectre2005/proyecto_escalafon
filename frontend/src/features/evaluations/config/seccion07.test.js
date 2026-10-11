import assert from 'node:assert/strict'
import test from 'node:test'
import { findTipo, tiposVisibles } from './seccion07.js'

test('tabs follow the active section 07 features', () => {
  assert.deepEqual(tiposVisibles(undefined).map(tipo => tipo.key), ['evaluaciones', 'progresiones', 'ratificaciones', 'desplazamientos'])
  const visibles = tiposVisibles({ activas: { 's07.progresion': false } })
  assert.deepEqual(visibles.map(tipo => tipo.key), ['evaluaciones', 'desplazamientos'])
  // Una pestaña desactivada pedida por URL cae en la primera visible.
  assert.equal(findTipo('progresiones', visibles).key, 'evaluaciones')
  assert.equal(findTipo('desplazamientos', visibles).key, 'desplazamientos')
})
