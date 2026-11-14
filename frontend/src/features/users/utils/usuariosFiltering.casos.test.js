import assert from 'node:assert/strict'
import test from 'node:test'
import { filterUsers } from './usuariosFiltering.js'

const usuarios = [
  { nombres: 'JOSÉ', apellido_paterno: 'ÑAÑEZ', apellido_materno: 'MUÑOZ', numero_doc_identidad: '99000001', email_institucional: 'jose@example.test', tipo_personal: 'docente', activo: true, situacion: 'vinculo_vigente' },
  { nombres: 'ANA MARÍA', apellido_paterno: 'DE LA CRUZ', apellido_materno: '', numero_doc_identidad: '99000002', email_institucional: '', tipo_personal: 'administrativo', activo: false, situacion: 'sin_legajo' },
  { nombres: 'LUIS', apellido_paterno: 'EJEMPLO', apellido_materno: 'PRUEBA', numero_doc_identidad: '00123456', email_institucional: null, tipo_personal: 'docente', activo: true },
]
const dnis = lista => lista.map(user => user.numero_doc_identidad)

test('sin filtros devuelve todos los usuarios', () => {
  assert.equal(filterUsers(usuarios).length, 3)
  assert.equal(filterUsers(usuarios, {}).length, 3)
})

test('una lista vacía da un resultado vacío', () => {
  assert.deepEqual(filterUsers([], { query: 'ana', estado: 'activo' }), [])
})

test('la búsqueda no distingue mayúsculas y respeta acentos y ñ', () => {
  assert.deepEqual(dnis(filterUsers(usuarios, { query: 'ñañez' })), ['99000001'])
  assert.deepEqual(dnis(filterUsers(usuarios, { query: 'José' })), ['99000001'])
  assert.deepEqual(dnis(filterUsers(usuarios, { query: 'maría' })), ['99000002'])
})

test('la búsqueda abarca nombres, apellidos, DNI y correo, incluso varios campos seguidos', () => {
  assert.deepEqual(dnis(filterUsers(usuarios, { query: 'de la cruz' })), ['99000002'])
  assert.deepEqual(dnis(filterUsers(usuarios, { query: 'luis ejemplo' })), ['00123456'])
  assert.deepEqual(dnis(filterUsers(usuarios, { query: '0012' })), ['00123456'])
  assert.deepEqual(dnis(filterUsers(usuarios, { query: '@example.test' })), ['99000001'])
  assert.deepEqual(filterUsers(usuarios, { query: 'inexistente' }), [])
})

test('correo nulo o vacío cuenta como "sin correo"', () => {
  assert.deepEqual(dnis(filterUsers(usuarios, { correo: 'sin' })), ['99000002', '00123456'])
  assert.deepEqual(dnis(filterUsers(usuarios, { correo: 'con' })), ['99000001'])
})

test('filtra por tipo de personal y situación laboral; sin situación no coincide con ninguna', () => {
  assert.deepEqual(dnis(filterUsers(usuarios, { tipoPersonal: 'docente' })), ['99000001', '00123456'])
  assert.deepEqual(dnis(filterUsers(usuarios, { situacion: 'sin_legajo' })), ['99000002'])
  assert.deepEqual(filterUsers(usuarios, { situacion: 'sin_vinculo_vigente' }), [])
})

test('no modifica la lista original', () => {
  const copia = structuredClone(usuarios)
  filterUsers(usuarios, { query: 'ana', estado: 'inactivo' })
  assert.deepEqual(usuarios, copia)
})
