import assert from 'node:assert/strict'
import test from 'node:test'
import { indiceNoAplica, mostrarTipo, motivoNoAplica, ofrecerTipo } from './aplicacion.js'

const indice = indiceNoAplica([{ id: 'u-1', codigo: 'S11_A_TERMINO_DESIGNACION', motivo: 'No corresponde a su régimen laboral.' }])

test('types are found by id or by code', () => {
  assert.equal(motivoNoAplica(indice, { id: 'u-1' }), 'No corresponde a su régimen laboral.')
  assert.equal(motivoNoAplica(indice, { codigo: 'S11_A_TERMINO_DESIGNACION' }), 'No corresponde a su régimen laboral.')
  assert.equal(motivoNoAplica(indice, { id: 'u-2', codigo: 'S11_B_TERMINO_CARRERA' }), null)
})

test('a type that does not apply is hidden unless it already has documents, and is never offered for upload', () => {
  assert.equal(mostrarTipo(indice, { id: 'u-1', documentos: 0 }), false)
  assert.equal(mostrarTipo(indice, { id: 'u-1', documentos: 2 }), true)
  assert.equal(mostrarTipo(indice, { id: 'u-1', cantidad: 1 }), true)
  assert.equal(ofrecerTipo(indice, { id: 'u-1', documentos: 2 }), false)
  assert.equal(mostrarTipo(indice, { id: 'u-2' }), true)
  assert.equal(ofrecerTipo(indice, { id: 'u-2' }), true)
})

test('without an answer from the backend everything is offered', () => {
  const vacio = indiceNoAplica(undefined)
  assert.equal(ofrecerTipo(vacio, { id: 'u-1' }), true)
  assert.equal(mostrarTipo(undefined, { id: 'u-1' }), true)
})
