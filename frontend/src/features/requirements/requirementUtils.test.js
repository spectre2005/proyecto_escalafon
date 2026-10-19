import assert from 'node:assert/strict'
import test from 'node:test'
import { isOverdue, isClosed, availableActions, extensionMin, filterTypes } from './requirementUtils.js'

test('overdue uses the extension and excludes completed or cancelled requirements', () => {
  const r = { estado: 'abierto', fecha_limite: '2026-10-18' }
  assert.equal(isOverdue(r, '2026-10-19'), true)
  assert.equal(isOverdue({ ...r, prorroga_hasta: '2026-10-19' }, '2026-10-19'), false)
  assert.equal(isOverdue({ ...r, estado: 'cumplido' }, '2026-10-19'), false)
  assert.equal(isOverdue({ ...r, cerrado_en: '2026-10-18' }, '2026-10-19'), false)
  assert.equal(isClosed({ estado: 'cancelado' }), true)
})
test('workflow offers review actions only after a response and never edits a closed requirement', () => {
  assert.ok(availableActions({ estado: 'abierto' }).includes('editar'))
  assert.ok(!availableActions({ estado: 'abierto', documento_id: 'doc' }).includes('editar'))
  assert.ok(availableActions({ estado: 'en_revision', documento_id: 'doc' }).includes('cumplir'))
  assert.ok(!availableActions({ estado: 'en_revision', documento_id: 'doc' }).includes('responder'))
  assert.ok(availableActions({ estado: 'observado', documento_id: 'doc' }).includes('responder'))
  assert.deepEqual(availableActions({ estado: 'cumplido' }), [])
})
test('extension date must follow the effective deadline, including month and year changes', () => {
  assert.equal(extensionMin({ fecha_limite: '2026-10-31' }, '2026-10-19'), '2026-11-01')
  assert.equal(extensionMin({ fecha_limite: '2026-10-18' }, '2026-10-19'), '2026-10-19')
  assert.equal(extensionMin({ fecha_limite: '2026-10-18', prorroga_hasta: '2026-12-31' }, '2026-10-19'), '2027-01-01')
})
test('type search handles accents and section filters without confusing similar documents', () => {
  const types = [{ id: 'titulo', nombre: 'Título profesional', seccion_legajo_id: 3 }, { id: 'cert', nombre: 'Certificado de estudios básicos', seccion_legajo_id: 3 }, { id: 'otro', nombre: 'Título de ejemplo', seccion_legajo_id: 12 }]
  assert.deepEqual(filterTypes(types, 'TITULO', '3').map(t => t.id), ['titulo'])
  assert.deepEqual(filterTypes(types, 'basicos estudios').map(t => t.id), ['cert'])
  assert.equal(filterTypes(types, '', '12').length, 1)
})
