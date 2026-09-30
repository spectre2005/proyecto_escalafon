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
