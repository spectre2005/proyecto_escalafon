import assert from 'node:assert/strict'
import test from 'node:test'
import { parametrosFormato } from './filtros.js'

test('a selected legajo overrides name text and a conflicting general state filter', () => {
  const params = new URLSearchParams(parametrosFormato({ formato: 'caratula', search: 'Otra persona', estado: 'activo', legajo: { id: 'pasivo-elegido' } }))
  assert.equal(params.get('legajo_id'), 'pasivo-elegido')
  assert.equal(params.has('q'), false)
  assert.equal(params.has('estado'), false)
})

test('general results preserve search and state for the query and full export', () => {
  const params = new URLSearchParams(parametrosFormato({ formato: 'matriz', search: '  Ana Torres  ', estado: 'pasivo' }))
  assert.equal(params.get('q'), 'Ana Torres')
  assert.equal(params.get('estado'), 'pasivo')
  assert.equal(params.has('legajo_id'), false)
})

test('dates only apply to incorporations and the section also to passive references', () => {
  const filtros = { desde: '2026-10-01', hasta: '2026-10-19', seccion: '3' }
  assert.equal(parametrosFormato({ formato: 'matriz', ...filtros }), '')
  const incorporaciones = new URLSearchParams(parametrosFormato({ formato: 'incorporaciones', ...filtros }))
  assert.deepEqual([incorporaciones.get('desde'), incorporaciones.get('hasta'), incorporaciones.get('seccion')], ['2026-10-01', '2026-10-19', '3'])
  const referencias = new URLSearchParams(parametrosFormato({ formato: 'referencias', ...filtros }))
  assert.deepEqual([referencias.has('desde'), referencias.get('seccion')], [false, '3'])
})
