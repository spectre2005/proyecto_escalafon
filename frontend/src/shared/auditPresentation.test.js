import test from 'node:test'
import assert from 'node:assert/strict'
import { auditChanges, auditValue, auditLabel } from './auditPresentation.js'

test('identifica altas, bajas y cambios sin incluir valores iguales', () => {
  assert.deepEqual(auditChanges({ estado: 'abierto', anterior: 1, igual: 2 }, { estado: 'cerrado', nuevo: 3, igual: 2 }).map(row => row.key), ['estado', 'anterior', 'nuevo'])
  assert.equal(auditChanges(null, { activo: false }).length, 1)
  assert.equal(auditChanges({ valor: null }, {}).length, 1)
})

test('compara objetos por contenido conservando el orden de las listas', () => {
  assert.deepEqual(auditChanges({ dato: { a: 1, b: 2 } }, { dato: { b: 2, a: 1 } }), [])
  assert.equal(auditChanges({ lista: [1, 2] }, { lista: [2, 1] }).length, 1)
})

test('presenta booleanos y ausencias sin perder ceros ni valores protegidos', () => {
  assert.equal(auditValue(false), 'No')
  assert.equal(auditValue(0), '0')
  assert.equal(auditValue(undefined), 'Sin registrar')
  assert.equal(auditValue(null), 'Vacío')
  assert.equal(auditValue('[REDACTADO]'), '[REDACTADO]')
  assert.equal(auditLabel('vinculo.perfil_corregido'), 'Vinculo · perfil corregido')
})
