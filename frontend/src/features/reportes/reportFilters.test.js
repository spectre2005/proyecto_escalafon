import assert from 'node:assert/strict'
import test from 'node:test'
import { reportParams } from './reportFilters.js'

test('a selected legajo overrides name text and a conflicting general state filter', () => {
  const params = new URLSearchParams(reportParams({ formato: 'caratula', search: 'Otra persona', estado: 'activo', legajo: { id: 'pasivo-elegido' } }))
  assert.equal(params.get('legajo_id'), 'pasivo-elegido')
  assert.equal(params.has('q'), false)
  assert.equal(params.has('estado'), false)
})

test('general results preserve search and state for the query and full export', () => {
  const params = new URLSearchParams(reportParams({ formato: 'matriz', search: '  Ana Torres  ', estado: 'pasivo' }))
  assert.equal(params.get('q'), 'Ana Torres')
  assert.equal(params.get('estado'), 'pasivo')
  assert.equal(params.has('legajo_id'), false)
})

test('incorporation filters are not carried into other report formats', () => {
  const filters = { desde: '2026-10-01', hasta: '2026-10-19', seccion: '3' }
  assert.equal(reportParams({ formato: 'matriz', ...filters }), '')
  const params = new URLSearchParams(reportParams({ formato: 'incorporaciones', ...filters }))
  assert.equal(params.get('desde'), filters.desde)
  assert.equal(params.get('hasta'), filters.hasta)
  assert.equal(params.get('seccion'), filters.seccion)
})
