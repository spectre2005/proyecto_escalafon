import assert from 'node:assert/strict'
import test from 'node:test'
import { filterUsers } from './usuariosFiltering.js'

const users = [
  { nombres: 'Persona', apellido_paterno: 'Ficticia', apellido_materno: 'Uno', numero_doc_identidad: '99000001', email_institucional: 'demo01@example.test', tipo_personal: 'docente', activo: true },
  { nombres: 'Persona', apellido_paterno: 'Ficticia', apellido_materno: 'Dos', numero_doc_identidad: '99000002', email_institucional: 'demo02@example.test', tipo_personal: 'administrativo', activo: false },
]

test('filters by active or inactive status', () => {
  assert.deepEqual(filterUsers(users, { estado: 'activo' }).map((user) => user.numero_doc_identidad), ['99000001'])
  assert.deepEqual(filterUsers(users, { estado: 'inactivo' }).map((user) => user.numero_doc_identidad), ['99000002'])
})

test('combines status, personnel type, and search filters', () => {
  assert.deepEqual(
    filterUsers(users, { estado: 'inactivo', tipoPersonal: 'administrativo', query: 'DEMO02' })
      .map((user) => user.numero_doc_identidad),
    ['99000002'],
  )
  assert.deepEqual(filterUsers(users, { estado: 'activo', tipoPersonal: 'administrativo' }), [])
})

 test('filters contact coverage and combines it with status', () => {
  const records = [...users, { ...users[0], numero_doc_identidad: '99000003', email_institucional: '  ', activo: false }]
  assert.equal(filterUsers(records, { correo: 'con' }).length, 2)
  assert.deepEqual(filterUsers(records, { correo: 'sin', estado: 'inactivo' }).map(user => user.numero_doc_identidad), ['99000003'])
  assert.equal(filterUsers(records, { correo: 'sin', estado: 'activo' }).length, 0)
})

test('labor situation is independent from system access', () => {
  const records = [
    { ...users[0], numero_doc_identidad: '99000011', activo: false, situacion: 'vinculo_vigente' },
    { ...users[0], numero_doc_identidad: '99000012', activo: true, situacion: 'sin_legajo' },
    { ...users[1], numero_doc_identidad: '99000013', activo: false, situacion: 'sin_vinculo_vigente' },
  ]
  assert.deepEqual(filterUsers(records, { situacion: 'vinculo_vigente' }).map(user => user.numero_doc_identidad), ['99000011'])
  // Sin acceso al sistema no significa sin vínculo.
  assert.deepEqual(filterUsers(records, { estado: 'inactivo', situacion: 'vinculo_vigente' }).map(user => user.numero_doc_identidad), ['99000011'])
  assert.deepEqual(filterUsers(records, { estado: 'activo', situacion: 'vinculo_vigente' }), [])
})
