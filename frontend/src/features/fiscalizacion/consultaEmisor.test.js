import test from 'node:test'
import assert from 'node:assert/strict'
import { datosConsultaIniciales, propuestasConsulta } from './consultaEmisor.js'

test('propone un único título y su emisor sin inventar envío o referencia', () => {
  const datos = datosConsultaIniciales([{ documento_id: 'titulo', datos_snapshot: { emisor: 'Universidad' } }], [{ id: 'titulo', nombre: 'Título' }, { id: 'oficio', nombre: 'Constancia de consulta enviada' }])
  assert.equal(datos.documento_id, 'titulo')
  assert.equal(datos.entidad_consultada, 'Universidad')
  assert.equal(datos.evidencia_documento_id, 'oficio')
  assert.equal(datos.numero_oficio, '')
  assert.equal(datos.fecha_envio, '')
  assert.equal(datos.medio, '')
})

test('no elige arbitrariamente entre varios títulos o documentos ajenos a una constancia', () => {
  const datos = datosConsultaIniciales([{ documento_id: 'uno' }, { documento_id: 'dos' }], [{ id: 'otro', nombre: 'Partida de nacimiento' }])
  assert.equal(datos.documento_id, '')
  assert.equal(datos.evidencia_documento_id, '')
  assert.equal(datos.entidad_consultada, '')
})

test('solo aplica campos reconocidos y fechas dentro del periodo del proceso', () => {
  assert.deepEqual(propuestasConsulta({ medio: 'correo', fecha_envio: '2026-10-22', documento_id: 'otro' }, '2026-10-23', '2026-10-24'), { medio: 'correo' })
  assert.deepEqual(propuestasConsulta({ fecha_envio: '2026-10-25' }, '2026-10-23', '2026-10-24'), {})
  assert.deepEqual(propuestasConsulta({ fecha_envio: '2026-10-23' }, '2026-10-23', '2026-10-24'), { fecha_envio: '2026-10-23' })
})
