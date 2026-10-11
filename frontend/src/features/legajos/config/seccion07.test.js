import assert from 'node:assert/strict'
import test from 'node:test'
import { columnasEvaluacion } from './seccion07.js'

const columnas = [['Calificación', () => 1], ['Puntaje', () => 2], ['Plan de mejora', () => 3]]

test('score column is hidden only when the score option is disabled', () => {
  assert.deepEqual(columnasEvaluacion(columnas, false).map(([titulo]) => titulo), ['Calificación', 'Plan de mejora'])
  assert.equal(columnasEvaluacion(columnas, true), columnas)
  assert.equal(columnasEvaluacion(columnas, undefined), columnas)
})
