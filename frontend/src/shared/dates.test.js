import test from 'node:test'
import assert from 'node:assert/strict'
import { todayLima, formatDate } from './dates.js'

test('los plazos usan Lima aunque UTC ya esté en el día siguiente', () => {
  assert.equal(todayLima(new Date('2026-10-18T02:00:00Z')), '2026-10-17')
  assert.equal(todayLima(new Date('2026-10-18T05:00:00Z')), '2026-10-18')
})

test('las fechas de calendario no cambian de día al mostrarlas', () => {
  assert.equal(formatDate('2026-10-17T00:00:00.000Z'), '17/10/2026')
  assert.equal(formatDate(null), 'Sin fecha')
})
