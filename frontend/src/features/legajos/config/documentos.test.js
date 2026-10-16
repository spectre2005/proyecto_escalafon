import assert from 'node:assert/strict'
import test from 'node:test'
import { folioFinalPorPaginas, modalidadRecordada, recordarModalidad, textoSubirTipo } from './documentos.js'

function almacen() {
  const datos = new Map()
  return { getItem: clave => datos.get(clave) ?? null, setItem: (clave, valor) => datos.set(clave, String(valor)) }
}

test('the last folio is only proposed from valid page counts', () => {
  assert.equal(folioFinalPorPaginas(12, 3), 14)
  assert.equal(folioFinalPorPaginas('5', 1), 5)
  for (const [inicio, paginas] of [[null, 3], [12, 0], [12, null], [0, 2], [12, 2.5]]) assert.equal(folioFinalPorPaginas(inicio, paginas), null)
})

test('the presentation mode is remembered only with known values', () => {
  const local = almacen()
  assert.equal(modalidadRecordada(local), '')
  recordarModalidad('copia_simple', local)
  assert.equal(modalidadRecordada(local), 'copia_simple')
  recordarModalidad('inventada', local)
  assert.equal(modalidadRecordada(local), 'copia_simple')
  local.setItem('documentos.modalidad', 'otra')
  assert.equal(modalidadRecordada(local), '')
})

test('without storage nothing breaks and nothing is proposed', () => {
  const roto = { getItem() { throw new Error('bloqueado') }, setItem() { throw new Error('bloqueado') } }
  assert.equal(modalidadRecordada(roto), '')
  assert.doesNotThrow(() => recordarModalidad('original', roto))
  assert.equal(modalidadRecordada(undefined), '')
})

test('a single-document type with a current document is fixed from that document, not from another button', () => {
  const vigente = { id: 'd-1', subsanado: false }
  assert.equal(textoSubirTipo({ lista: [] }), 'Subir documento')
  assert.equal(textoSubirTipo({ permite_multiples: true, lista: [vigente] }), 'Subir documento')
  assert.equal(textoSubirTipo({ permite_multiples: false, lista: [vigente] }), null)
  assert.equal(textoSubirTipo({ permite_multiples: false, lista: [{ ...vigente, subsanado: true }] }), 'Subir documento')
  assert.equal(textoSubirTipo({ codigo: 'S01_B_DOCUMENTO_IDENTIDAD', permite_multiples: false, lista: [vigente] }), 'Actualizar copia de DNI')
})
