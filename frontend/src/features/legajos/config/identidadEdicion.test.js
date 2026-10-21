import assert from 'node:assert/strict'
import test from 'node:test'
import { aplicarSeleccionIdentidad, cambiarDocumentoEdicion, claveDocumento, filasComparacionIdentidad, ubicacionDeEdicion } from './identidadEdicion.js'

const original = {
  tipo_doc_identidad: 'dni', numero_doc_identidad: '01234567', nombres: 'Ana', apellido_paterno: 'Anterior', apellido_materno: 'Guardado',
  email_institucional: 'correo-elegido@empresa.pe', telefono: '987654321', fecha_nacimiento: '1990-01-01', tipo_personal: 'docente',
  region: 'Lima', provincia: 'Lima', distrito: 'Miraflores',
}
const ubicacion = { region: 'Cusco', provincia: 'La Convención', distrito: 'Santa Ana' }
const persona = { dni: '01234567', nombres: 'ANA MARÍA', apellido_paterno: 'QUISPE', apellido_materno: 'FLORES', ubicacion: { id: 'id-local', ...ubicacion } }

test('consulting only creates comparison rows and leaves saved data and location untouched', () => {
  const antes = structuredClone(original)
  const filas = filasComparacionIdentidad(original, original, original, persona)
  assert.equal(filas.length, 4)
  assert.ok(filas.every(fila => fila.diferente))
  assert.deepEqual(original, antes)
  assert.deepEqual(ubicacionDeEdicion(original, original, null), { region: 'Lima', provincia: 'Lima', distrito: 'Miraflores' })
  assert.deepEqual(aplicarSeleccionIdentidad(original, original, persona, []), { draft: original, ubicacion: null })
})

test('only selected names are applied, retaining other names, manual contact and classification', () => {
  const actual = { ...original, telefono: '912345678' }
  const resultado = aplicarSeleccionIdentidad(actual, original, persona, ['nombres', 'apellido_paterno', 'telefono'])
  assert.deepEqual(resultado, { draft: { ...actual, nombres: 'ANA MARÍA', apellido_paterno: 'QUISPE' }, ubicacion: null })
  assert.equal(actual.nombres, 'Ana')
})

test('comparison distinguishes the stored value from an unsaved manual change', () => {
  const actual = { ...original, nombres: 'Ana editada' }
  const filas = filasComparacionIdentidad(original, actual, ubicacion, persona)
  assert.deepEqual(filas[0], { key: 'nombres', label: 'Nombres', guardado: 'Ana', actual: 'Ana editada', consultado: 'ANA MARÍA', disponible: true, diferente: true })
  assert.equal(filas[3].diferente, false)
})

test('a stale response cannot affect another DNI or a non-DNI document', () => {
  for (const actual of [{ ...original, numero_doc_identidad: '87654321' }, { ...original, tipo_doc_identidad: 'pasaporte' }]) {
    assert.deepEqual(filasComparacionIdentidad(original, actual, original, persona), [])
    assert.equal(aplicarSeleccionIdentidad(actual, original, persona, ['nombres', 'ubicacion']).draft, actual)
    assert.equal(aplicarSeleccionIdentidad(actual, original, persona, ['nombres', 'ubicacion']).ubicacion, null)
  }
})

test('a DNI correction preserves existing and unsaved data, including leading zeros', () => {
  const actual = { ...original, nombres: 'Nombre editado' }
  const siguiente = cambiarDocumentoEdicion(actual, 'numero_doc_identidad', '00112233')
  assert.deepEqual(siguiente, { ...actual, numero_doc_identidad: '00112233' })
  assert.equal(cambiarDocumentoEdicion(original, 'numero_doc_identidad', original.numero_doc_identidad), original)
})

test('location is applied as a complete group only when selected', () => {
  const resultado = aplicarSeleccionIdentidad(original, original, persona, ['ubicacion'])
  assert.equal(resultado.draft, original)
  assert.deepEqual(resultado.ubicacion, ubicacion)
  const manual = { documento: claveDocumento(original), value: resultado.ubicacion }
  assert.deepEqual(ubicacionDeEdicion(original, original, manual), ubicacion)
  assert.equal(aplicarSeleccionIdentidad(original, original, persona, ['region', 'provincia']).ubicacion, null)
})

test('missing names and incomplete locations never erase existing data, even if selected', () => {
  const incompleta = { ...persona, apellido_materno: ' ', ubicacion: { region: 'Cusco', provincia: 'La Convención' } }
  const filas = filasComparacionIdentidad(original, original, original, incompleta)
  assert.equal(filas[2].disponible, false)
  assert.equal(filas[3].disponible, false)
  assert.deepEqual(aplicarSeleccionIdentidad(original, original, incompleta, ['apellido_materno', 'ubicacion']), { draft: original, ubicacion: null })
})

test('matching values do not create changes and manual clearing of location is respected', () => {
  const igual = { ...persona, nombres: ' Ana ' }
  assert.equal(filasComparacionIdentidad(original, original, original, igual)[0].diferente, false)
  assert.equal(aplicarSeleccionIdentidad(original, original, igual, ['nombres']).draft, original)
  const vacia = { region: '', provincia: '', distrito: '' }
  assert.deepEqual(ubicacionDeEdicion(original, original, { documento: claveDocumento(original), value: vacia }), vacia)
})
