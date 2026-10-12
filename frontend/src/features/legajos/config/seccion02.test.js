import assert from 'node:assert/strict'
import test from 'node:test'
import { pruebaEnNoConDatos, pruebaNoAplica } from './seccion02.js'

test('2.4 does not apply only with the flag in No and nothing registered', () => {
  assert.equal(pruebaNoAplica({ tiene_periodo_prueba: false, prueba_con_datos: false }), true)
  assert.equal(pruebaNoAplica({ tiene_periodo_prueba: false, prueba_con_datos: true }), false)
  assert.equal(pruebaNoAplica({ tiene_periodo_prueba: null, prueba_con_datos: false }), false)
  assert.equal(pruebaNoAplica({ tiene_periodo_prueba: true, prueba_con_datos: false }), false)
  assert.equal(pruebaNoAplica(undefined), false)
})

test('with the flag in No and registered data, 2.4 stays visible with a notice', () => {
  assert.equal(pruebaEnNoConDatos({ tiene_periodo_prueba: false, prueba_con_datos: true }), true)
  assert.equal(pruebaEnNoConDatos({ tiene_periodo_prueba: false, prueba_con_datos: false }), false)
  assert.equal(pruebaEnNoConDatos({ tiene_periodo_prueba: true, prueba_con_datos: true }), false)
})
