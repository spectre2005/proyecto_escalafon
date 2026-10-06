import test from 'node:test'
import assert from 'node:assert/strict'
import { validatePdf } from './pdfValidation.js'

test('acepta PDF hasta el límite y MIME vacío', () => {
  assert.equal(validatePdf({ name: 'documento.PDF', type: 'application/pdf', size: 2 * 1024 * 1024 }), '')
  assert.equal(validatePdf({ name: 'documento.pdf', type: '', size: 10 }), '')
  assert.equal(validatePdf(null), '')
})
test('rechaza extensión o tipo incorrectos', () => {
  assert.match(validatePdf({ name: 'imagen.png', type: 'image/png', size: 10 }), /PDF/)
  assert.match(validatePdf({ name: 'falso.pdf', type: 'text/plain', size: 10 }), /PDF/)
})
test('informa peso real y máximo configurable', () => {
  assert.match(validatePdf({ name: 'grande.pdf', type: 'application/pdf', size: 3 * 1024 * 1024 }), /3.00 MB.*2 MB/)
  assert.equal(validatePdf({ name: 'grande.pdf', type: 'application/pdf', size: 3 * 1024 * 1024 }, 4), '')
})
