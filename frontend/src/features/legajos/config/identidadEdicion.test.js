import assert from 'node:assert/strict'
import test from 'node:test'
import { cambiosInformacionPersonal, partesUbicacion } from './identidadEdicion.js'

const original = {
  tipo_doc_identidad: 'dni', numero_doc_identidad: '01234567', nombres: 'Ana', apellido_paterno: 'Quispe', apellido_materno: '',
  telefono: '987654321', email_institucional: 'ana@empresa.pe', fecha_nacimiento: null, estado_civil: null, domicilio: null,
  region: 'Cusco', provincia: 'La Convención', distrito: 'Santa Ana', tiene_derechohabientes: null,
}
const bloqueados = ['tipo_doc_identidad', 'numero_doc_identidad', 'nombres', 'apellido_paterno', 'ubicacion.region', 'ubicacion.provincia', 'ubicacion.distrito']

test('registered identity fields are omitted even if a draft tries to overwrite them', () => {
  const draft = { ...original, nombres: 'Otro nombre', numero_doc_identidad: '87654321', tipo_doc_identidad: 'pasaporte', telefono: '912345678' }
  assert.deepEqual(cambiosInformacionPersonal(original, draft, original, bloqueados), { telefono: '912345678' })
})

test('missing identity and personal fields can be completed without changing registered names', () => {
  const draft = { ...original, apellido_materno: ' Flores ', fecha_nacimiento: '1990-01-01', domicilio: ' Dirección de prueba ', estado_civil: 'soltero' }
  assert.deepEqual(cambiosInformacionPersonal(original, draft, original, bloqueados), {
    apellido_materno: 'Flores', fecha_nacimiento: '1990-01-01', domicilio: 'Dirección de prueba', estado_civil: 'soltero',
  })
})

test('opening and saving an unchanged form produces no writes, including empty and null values', () => {
  assert.deepEqual(cambiosInformacionPersonal(original, { ...original, fecha_nacimiento: '', domicilio: '', tiene_derechohabientes: null }, original, bloqueados), {})
})

test('registered location cannot be replaced or cleared through the draft', () => {
  assert.deepEqual(cambiosInformacionPersonal(original, original, { region: 'Lima', provincia: 'Lima', distrito: 'Miraflores' }, bloqueados), {})
  assert.deepEqual(cambiosInformacionPersonal(original, original, {}, bloqueados), {})
})

test('an absent location can be completed while existing parents of a partial location remain unchanged', () => {
  const vacia = { ...original, region: null, provincia: null, distrito: null }
  assert.deepEqual(cambiosInformacionPersonal(vacia, vacia, original, ['tipo_doc_identidad']), { ubicacion: partesUbicacion(original) })
  const parcial = { ...original, provincia: null, distrito: null }
  assert.deepEqual(cambiosInformacionPersonal(parcial, parcial, { region: 'Otro', provincia: 'La Convención', distrito: 'Santa Ana' }, ['ubicacion.region']), { ubicacion: partesUbicacion(original) })
})

test('contact information can be edited and flags preserve their three states', () => {
  assert.deepEqual(cambiosInformacionPersonal(original, { ...original, telefono: '', email_institucional: ' otro@empresa.pe ', tiene_derechohabientes: false }, original, bloqueados), {
    telefono: null, email_institucional: 'otro@empresa.pe', tiene_derechohabientes: false,
  })
  assert.deepEqual(cambiosInformacionPersonal({ ...original, tiene_derechohabientes: true }, { ...original, tiene_derechohabientes: null }, original, bloqueados), { tiene_derechohabientes: null })
})
