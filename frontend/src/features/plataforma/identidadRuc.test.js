import assert from 'node:assert/strict'
import test from 'node:test'
import { cambiarRucInstitucion, completarInstitucionRuc, rucValido } from './identidadRuc.js'

const empresa = { ruc: '20100000009', razon_social: 'UNIVERSIDAD DE PRUEBA' }

test('RUC lookup starts only for eleven digits with a valid prefix and checksum', () => {
  for (const ruc of ['20100000009', '10000000006', '20600000005']) assert.equal(rucValido(ruc), true)
  for (const ruc of ['', '123', '20100000008', '30100000003', '2010000000A', '201000000091', '201 0000009', 20100000009, null]) assert.equal(rucValido(ruc), false)
})

test('a new institution receives its legal name, display name and a proposed identifier', () => {
  const actual = { ruc: empresa.ruc, razon_social: '', nombre: '', slug: '', base_datos: '', almacenamiento: '' }
  const nuevo = completarInstitucionRuc(actual, empresa, { completarNombre: true })
  assert.equal(nuevo.razon_social, empresa.razon_social)
  assert.equal(nuevo.nombre, empresa.razon_social)
  assert.equal(nuevo.slug, 'universidad-de-prueba')
  assert.equal(actual.nombre, '')
})

test('an abbreviated name and custom technical settings survive automatic lookup', () => {
  const actual = { ruc: empresa.ruc, razon_social: '', nombre: 'UNIP', slug: 'uni-elegida', base_datos: 'escalafon_elegida', almacenamiento: 'instituciones/elegida' }
  assert.deepEqual(completarInstitucionRuc(actual, empresa, { completarNombre: true }), { ...actual, razon_social: empresa.razon_social })
  const conSlug = completarInstitucionRuc({ ...actual, nombre: '' }, empresa, { completarNombre: true })
  assert.equal(conSlug.nombre, empresa.razon_social)
  assert.equal(conSlug.slug, actual.slug)
})

test('a late response cannot fill another RUC or reopen a cancelled edit', () => {
  const actual = { ruc: '20600000005', razon_social: '', nombre: '' }
  assert.equal(completarInstitucionRuc(actual, empresa, { completarNombre: true }), actual)
  assert.equal(completarInstitucionRuc(null, empresa), null)
})

test('editing client data fills only the legal name and keeps contact and contract data', () => {
  const actual = { ruc: empresa.ruc, razon_social: '', contacto_email: 'orh@example.test', contrato_numero: 'C-001', contrato_inicio: '2026-10-21' }
  assert.deepEqual(completarInstitucionRuc(actual, empresa), { ...actual, razon_social: empresa.razon_social })
  assert.equal('nombre' in completarInstitucionRuc(actual, empresa), false)
  assert.equal('slug' in completarInstitucionRuc(actual, empresa), false)
})

test('changing RUC clears the old taxpayer data and generated names without erasing custom fields', () => {
  const actual = { ruc: empresa.ruc, razon_social: empresa.razon_social, nombre: empresa.razon_social, slug: 'universidad-de-prueba', contacto_nombre: 'ORH' }
  const otro = cambiarRucInstitucion(actual, '20600000005', { completarNombre: true })
  assert.deepEqual(otro, { ...actual, ruc: '20600000005', razon_social: '', nombre: '', slug: '' })
  const personalizado = { ...actual, nombre: 'Nombre elegido', slug: 'identificador-elegido' }
  assert.deepEqual(cambiarRucInstitucion(personalizado, '', { completarNombre: true }), { ...personalizado, ruc: '', razon_social: '' })
  assert.equal(cambiarRucInstitucion(actual, empresa.ruc), actual)
})

test('long legal names stay complete while the display name and identifier respect their field limits', () => {
  const razon_social = `UNIVERSIDAD ${'A'.repeat(188)}`
  assert.equal(razon_social.length, 200)
  const nuevo = completarInstitucionRuc({ ruc: empresa.ruc, razon_social: '', nombre: '', slug: '' }, { ...empresa, razon_social }, { completarNombre: true })
  assert.equal(nuevo.razon_social, razon_social)
  assert.equal(nuevo.nombre.length, 150)
  assert.equal(nuevo.slug.length, 40)
})
