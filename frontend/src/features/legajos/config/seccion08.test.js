import assert from 'node:assert/strict'
import test from 'node:test'
import { estados08, resultadoRecurso08, tipoSancion08 } from './seccion08.js'

test('Section 08 displays only the requested document and optional item states', () => {
  assert.deepEqual(Object.keys(estados08), ['aceptado', 'pendiente', 'observado', 'opcional', 'no_aplica'])
  assert.equal(estados08.pendiente[0], 'En revisión')
  assert.equal(estados08.opcional[0], 'Opcional')
  assert.equal(estados08.no_aplica[0], 'No aplica')
})

test('sanction and appeal labels cover the values accepted by the API', () => {
  assert.deepEqual(Object.keys(tipoSancion08), ['AMONESTACION_ESCRITA', 'SUSPENSION', 'DESTITUCION'])
  assert.deepEqual(Object.keys(resultadoRecurso08), ['FUNDADO', 'INFUNDADO', 'IMPROCEDENTE'])
})
