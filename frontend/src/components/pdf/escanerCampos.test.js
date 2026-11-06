import test from 'node:test'
import assert from 'node:assert/strict'
import { datosAplicables, motivosDescarte, sugerenciasAplicables, valorLegible } from './escanerCampos.js'

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

test('una sugerencia no reemplaza lo escrito ni se ofrece fuera del formulario', () => {
  const sugerencias = { fecha_emision: { valor: '2024-08-12', evidencia: '12.VIII.2024' }, emisor: { valor: 'Oficina', evidencia: 'Oficina' }, cargo: { valor: 'Docente', evidencia: 'Docente' }, nombre: { valor: ' ', evidencia: 'x' } }
  assert.deepEqual(Object.keys(sugerenciasAplicables(sugerencias, { fecha_emision: '', emisor: 'Ya escrito', nombre: '' }, ['fecha_emision', 'emisor', 'nombre'])), ['fecha_emision'])
})

test('lo sugerido no se repite como descartado y las fechas se leen como en un documento', () => {
  assert.deepEqual(motivosDescarte({ fecha_emision: 'fecha_no_coincide', cargo: 'es_el_titular' }, ['fecha_emision', 'cargo'], { fecha_emision: {} }), [{ campo: 'cargo', motivo: 'es el nombre de la persona' }])
  assert.equal(valorLegible('2024-08-12'), '12/08/2024')
  assert.equal(valorLegible('Docente'), 'Docente')
})
