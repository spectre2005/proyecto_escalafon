import assert from 'node:assert/strict'
import test from 'node:test'
import { resumenFormacion } from './seccion03.js'

const vacios = { formacion: [], colegiaturas: [], capacitaciones: [] }

test('the training summary lists what current documents certify, newest first, with total hours', () => {
  const items = [
    { id: 'b', documentos: [
      { id: 'd1', estado: 'aceptado', registros: { ...vacios, formacion: [{ id: 'f1', nivel: 'bachiller', fecha_obtencion: '2015-03-01' }] } },
      { id: 'd2', estado: 'aceptado', registros: { ...vacios, formacion: [{ id: 'f2', nivel: 'titulo_profesional', fecha_obtencion: '2017-08-01' }] } },
    ] },
    { id: 'f', documentos: [
      { id: 'd3', estado: 'aceptado', registros: { ...vacios, capacitaciones: [{ id: 'c1', horas: 40, fecha_fin: '2020-01-01' }, { id: 'c2', horas: null, fecha_fin: null }] } },
    ] },
  ]
  const resumen = resumenFormacion(items)
  assert.deepEqual(resumen.formacion.map(fila => [fila.id, fila.item, fila.documento]), [['f2', 'b', 'd2'], ['f1', 'b', 'd1']])
  assert.deepEqual(resumen.capacitaciones.map(fila => fila.id), ['c1', 'c2'])
  assert.equal(resumen.horas, 40)
  assert.deepEqual(resumen.colegiaturas, [])
})

test('subsaned or annulled documents and annulled records stay out of the summary', () => {
  const items = [{ id: 'c', documentos: [
    { id: 'viejo', estado: 'aceptado', subsanado: true, registros: { ...vacios, colegiaturas: [{ id: 'x' }] } },
    { id: 'anulado', estado: 'anulado', registros: { ...vacios, colegiaturas: [{ id: 'y' }] } },
    { id: 'vigente', estado: 'aceptado', registros: { ...vacios, colegiaturas: [{ id: 'z' }, { id: 'w', anulado: true }] } },
    { id: 'sin-registros', estado: 'aceptado' },
  ] }]
  assert.deepEqual(resumenFormacion(items).colegiaturas.map(fila => fila.id), ['z'])
  assert.deepEqual(resumenFormacion(undefined), { ...vacios, horas: 0 })
})
