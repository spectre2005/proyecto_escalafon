import assert from 'node:assert/strict'
import test from 'node:test'
import { esBandera, textoBandera, valorBandera } from './banderas.js'

test('three states are shown without reading unanswered as no', () => {
  assert.equal(textoBandera(true), 'Sí')
  assert.equal(textoBandera(false), 'No')
  assert.equal(textoBandera(null), 'Sin responder')
  assert.equal(textoBandera(undefined), 'Sin responder')
})

test('only true and false are answers; anything else is unanswered', () => {
  assert.equal(valorBandera(true), true)
  assert.equal(valorBandera(false), false)
  assert.equal(valorBandera(null), null)
  assert.equal(valorBandera(undefined), null)
  assert.equal(valorBandera(''), null)
  assert.equal(valorBandera(0), null)
})

test('flags are recognised by key', () => {
  assert.equal(esBandera('con_discapacidad'), true)
  assert.equal(esBandera('tiene_periodo_prueba'), true)
  assert.equal(esBandera('nombre_puesto'), false)
})
