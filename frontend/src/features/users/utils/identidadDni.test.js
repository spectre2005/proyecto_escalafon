import assert from 'node:assert/strict'
import test from 'node:test'
import { cambiarDniRegistro, completarIdentidadRegistro, ubicacionDelRegistro } from './identidadDni.js'

const borrador = {
  numero_doc_identidad: '01234567', nombres: 'Rosa', apellido_paterno: 'Alta', apellido_materno: 'Anterior',
  email_institucional: 'rosa-alta@empresa.pe', telefono: '987654321', tipo_personal: 'docente', regimen_normativo_id: 'ley-30220',
}
const persona = { dni: '87654321', nombres: 'ANA MARÍA', apellido_paterno: 'QUISPE', apellido_materno: 'FLORES' }

test('changing DNI clears the previous identity and updates the suggested email with the new names', () => {
  const cambiado = cambiarDniRegistro(borrador, persona.dni, 'empresa.pe')
  assert.deepEqual([cambiado.nombres, cambiado.apellido_paterno, cambiado.apellido_materno, cambiado.email_institucional], ['', '', '', ''])
  const completado = completarIdentidadRegistro(cambiado, persona, 'empresa.pe')
  assert.deepEqual([completado.nombres, completado.apellido_paterno, completado.apellido_materno, completado.email_institucional], ['ANA MARÍA', 'QUISPE', 'FLORES', 'ana-quispe@empresa.pe'])
  assert.equal(completado.telefono, borrador.telefono)
  assert.equal(completado.tipo_personal, borrador.tipo_personal)
  assert.equal(completado.regimen_normativo_id, borrador.regimen_normativo_id)
  assert.equal(borrador.nombres, 'Rosa')
})

test('a late response for the previous DNI cannot replace the current draft', () => {
  const cambiado = cambiarDniRegistro(borrador, persona.dni, 'empresa.pe')
  const tardio = completarIdentidadRegistro(cambiado, { ...persona, dni: borrador.numero_doc_identidad }, 'empresa.pe')
  assert.equal(tardio, cambiado)
  assert.equal(tardio.nombres, '')
})

test('a hand-written email survives a DNI change and the automatic lookup', () => {
  const cambiado = cambiarDniRegistro({ ...borrador, email_institucional: 'correo-elegido@empresa.pe' }, persona.dni, 'empresa.pe')
  const completado = completarIdentidadRegistro(cambiado, persona, 'empresa.pe')
  assert.equal(completado.email_institucional, 'correo-elegido@empresa.pe')
})

test('without an institutional domain no email is invented and a missing second surname stays empty', () => {
  const actual = { ...borrador, numero_doc_identidad: persona.dni, email_institucional: '' }
  const completado = completarIdentidadRegistro(actual, { ...persona, apellido_materno: null }, '')
  assert.equal(completado.email_institucional, '')
  assert.equal(completado.apellido_materno, '')
})

test('leading zeros in DNI are preserved and entering the same document keeps manual corrections', () => {
  const actual = cambiarDniRegistro(borrador, '00123456', 'empresa.pe')
  assert.equal(actual.numero_doc_identidad, '00123456')
  assert.equal(cambiarDniRegistro(borrador, borrador.numero_doc_identidad, 'empresa.pe'), borrador)
})

const consultaUbicacion = { dni: persona.dni, ubicacion: { id: 'ubigeo-local', region: 'Cusco', provincia: 'La Convención', distrito: 'Santa Ana' } }

test('the matching DNI selects all three canonical location names with the catalog UUID', () => {
  assert.deepEqual(ubicacionDelRegistro(persona.dni, null, consultaUbicacion), {
    value: { region: 'Cusco', provincia: 'La Convención', distrito: 'Santa Ana' }, id: 'ubigeo-local',
  })
})

test('manual location and an explicitly cleared selection take precedence over a late lookup', () => {
  const manual = { dni: persona.dni, value: { region: 'Lima', provincia: 'Lima', distrito: 'Miraflores' }, id: 'otro-ubigeo' }
  assert.deepEqual(ubicacionDelRegistro(persona.dni, manual, consultaUbicacion), { value: manual.value, id: manual.id })
  const vacia = { dni: persona.dni, value: { region: '', provincia: '', distrito: '' }, id: '' }
  assert.deepEqual(ubicacionDelRegistro(persona.dni, vacia, consultaUbicacion), { value: vacia.value, id: '' })
})

test('changing DNI never carries over a manual location or a response for the previous person', () => {
  const manual = { dni: persona.dni, value: { region: 'Lima', provincia: 'Lima', distrito: 'Miraflores' }, id: 'otro-ubigeo' }
  assert.deepEqual(ubicacionDelRegistro('00123456', manual, consultaUbicacion), { value: { region: '', provincia: '', distrito: '' }, id: '' })
})
