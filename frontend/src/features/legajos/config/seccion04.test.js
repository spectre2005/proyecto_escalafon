import assert from 'node:assert/strict'
import test from 'node:test'
import { aniosDecimal, duracion, subtotalesSuperanTotal } from './seccion04.js'

test('experience reads as years and months, or days when shorter than a month', () => {
  assert.equal(duracion({ anios: 5, meses: 3, dias: 12 }), '5 años y 3 meses')
  assert.equal(duracion({ anios: 1, meses: 0, dias: 4 }), '1 año')
  assert.equal(duracion({ anios: 0, meses: 1, dias: 0 }), '1 mes')
  assert.equal(duracion({ anios: 0, meses: 0, dias: 12 }), '12 días')
  assert.equal(duracion({ anios: 0, meses: 0, dias: 0 }), '0 días')
  assert.equal(aniosDecimal({ anios_decimal: 5.2 }), '5.20 años')
})

test('the sector note appears only with simultaneous periods', () => {
  const tiempo = (total, publico, privado) => ({ total: { dias_totales: total }, publico: { dias_totales: publico }, privado: { dias_totales: privado } })
  assert.equal(subtotalesSuperanTotal(tiempo(100, 60, 40)), false)
  assert.equal(subtotalesSuperanTotal(tiempo(100, 70, 40)), true)
})
