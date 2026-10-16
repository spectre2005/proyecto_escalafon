import test from 'node:test'
import assert from 'node:assert/strict'
import { suggestEmail, updateUserDraft } from './usuariosEmail.js'

const vacio = { nombres: '', apellido_paterno: '', email_institucional: '' }

test('without a configured domain no email is suggested or forced', () => {
  assert.equal(suggestEmail('Ana María', 'Pérez'), '')
  const draft = updateUserDraft(updateUserDraft(vacio, 'nombres', 'Ana'), 'apellido_paterno', 'Pérez')
  assert.equal(draft.email_institucional, '')
})

test('the suggestion uses the institution domain, never a fixed one', () => {
  assert.equal(suggestEmail('Ana María', 'Pérez', 'institucion.example.test'), 'ana-perez@institucion.example.test')
  const draft = updateUserDraft(updateUserDraft(vacio, 'nombres', 'Ana', 'otra.example.test'), 'apellido_paterno', 'Pérez', 'otra.example.test')
  assert.equal(draft.email_institucional, 'ana-perez@otra.example.test')
})

test('an email typed by hand is not overwritten by the suggestion', () => {
  const propio = { ...vacio, nombres: 'Ana', email_institucional: 'apc@otra.example.test' }
  assert.equal(updateUserDraft(propio, 'apellido_paterno', 'Pérez', 'institucion.example.test').email_institucional, 'apc@otra.example.test')
})
