import test from 'node:test'
import assert from 'node:assert/strict'
import { datosAplicables, motivosDescarte } from './escanerCampos.js'

test('solo aplica campos permitidos y conserva la edición realizada durante la lectura', () => {
  const recibidos = { emisor: { valor: 'Universidad', evidencia: 'Universidad' }, fecha_emision: { valor: '2026-10-01', evidencia: '1 de octubre de 2026' }, folio_inicio: { valor: '99', evidencia: '99' }, anios: { valor: '5', evidencia: '5 años' } }
  assert.deepEqual(datosAplicables(recibidos, { emisor: '', fecha_emision: '' }, { emisor: 'Edición manual', fecha_emision: '' }, ['emisor', 'fecha_emision', 'folio_inicio']), { fecha_emision: '2026-10-01' })
})

test('ignora valores vacíos, sin evidencia y campos de otra sección', () => {
  assert.deepEqual(datosAplicables({ emisor: { valor: '' }, cargo: { valor: 'Director', evidencia: 'Director' }, nombre: { valor: 'Resolución' } }, {}, {}, ['emisor', 'nombre']), {})
})

test('explica por qué se descartó un dato y omite los campos de otra sección', () => {
  assert.deepEqual(motivosDescarte({ fecha_emision: 'fecha_no_coincide', folio_inicio: 'cita_no_encontrada', cargo: 'desconocido' }, ['fecha_emision', 'cargo']), [
    { campo: 'fecha_emision', motivo: 'la fecha no coincide con la cita' },
    { campo: 'cargo', motivo: 'no se pudo comprobar' },
  ])
})
