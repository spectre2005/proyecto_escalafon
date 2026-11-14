import assert from 'node:assert/strict'
import test from 'node:test'
import { suggestEmail, updateUserDraft } from './usuariosEmail.js'

const dominio = 'institucion.example.test'
const vacio = { nombres: '', apellido_paterno: '', email_institucional: '' }

test('la sugerencia quita acentos, convierte ñ en n y pasa a minúsculas', () => {
  assert.equal(suggestEmail('JOSÉ', 'ÑAÑEZ', dominio), `jose-nanez@${dominio}`)
  assert.equal(suggestEmail('Inés', 'Güemes', dominio), `ines-guemes@${dominio}`)
})

test('usa solo el primer nombre y todo el apellido paterno con guiones', () => {
  assert.equal(suggestEmail('  Ana   María ', ' De la Cruz ', dominio), `ana-de-la-cruz@${dominio}`)
  assert.equal(suggestEmail('Ana', "O'Neill", dominio), `ana-o-neill@${dominio}`)
  assert.equal(suggestEmail('Ana', 'Pérez-Ruiz', dominio), `ana-perez-ruiz@${dominio}`)
})

test('sin nombre o apellido utilizable no sugiere correo', () => {
  assert.equal(suggestEmail('', 'Pérez', dominio), '')
  assert.equal(suggestEmail('Ana', '', dominio), '')
  assert.equal(suggestEmail('   ', '   ', dominio), '')
  assert.equal(suggestEmail('***', 'Pérez', dominio), '')
})

test('cambiar otro campo no toca el correo', () => {
  const draft = { ...vacio, nombres: 'Ana', apellido_paterno: 'Pérez', email_institucional: `ana-perez@${dominio}` }
  const siguiente = updateUserDraft(draft, 'telefono', '987654321', dominio)
  assert.equal(siguiente.telefono, '987654321')
  assert.equal(siguiente.email_institucional, `ana-perez@${dominio}`)
})

test('la sugerencia anterior se actualiza al cambiar el nombre o el apellido', () => {
  const draft = { ...vacio, nombres: 'Ana', apellido_paterno: 'Pérez', email_institucional: `ana-perez@${dominio}` }
  assert.equal(updateUserDraft(draft, 'nombres', 'Luisa', dominio).email_institucional, `luisa-perez@${dominio}`)
  assert.equal(updateUserDraft(draft, 'apellido_paterno', 'Ñañez', dominio).email_institucional, `ana-nanez@${dominio}`)
  // Al borrar el nombre, la sugerencia también se vacía.
  assert.equal(updateUserDraft(draft, 'nombres', '', dominio).email_institucional, '')
})

test('updateUserDraft no modifica el borrador original', () => {
  const draft = { ...vacio, nombres: 'Ana' }
  const siguiente = updateUserDraft(draft, 'apellido_paterno', 'Pérez', dominio)
  assert.notEqual(siguiente, draft)
  assert.equal(draft.apellido_paterno, '')
  assert.equal(draft.email_institucional, '')
})
