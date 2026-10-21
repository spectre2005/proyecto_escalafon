import assert from 'node:assert/strict'
import test from 'node:test'
import { fechaEnLima, fechaMaxima, limiteInicial, vencimientoDelDia } from './plazoPassword.js'

test('the calendar uses the Lima date even when UTC has already changed day', () => {
  const ahora = Date.parse('2026-10-21T02:30:00Z')
  assert.equal(fechaEnLima(ahora), '2026-10-20')
  assert.equal(limiteInicial(ahora), '2026-10-22T23:59:59-05:00')
  assert.equal(fechaMaxima(ahora), '2026-10-27')
})

test('the selected calendar day includes its last second in Lima', () => {
  assert.equal(new Date(vencimientoDelDia('2026-10-22')).toISOString(), '2026-10-23T04:59:59.000Z')
  assert.equal(vencimientoDelDia(''), '')
})
