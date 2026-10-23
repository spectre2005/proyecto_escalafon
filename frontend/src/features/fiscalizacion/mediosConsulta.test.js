import test from 'node:test'
import assert from 'node:assert/strict'
import { cambiarMedioConsulta, detalleMedio, prepararConsulta } from './mediosConsulta.js'

test('envía los datos específicos del correo sin mezclar otros medios', () => {
  const datos = prepararConsulta({ medio: 'correo', documento_id: 'titulo', remitente: ' rrhh@entidad.gob.pe ', destinatario: 'grados@universidad.edu.pe', asunto: 'Verificación de título', nombre_registro: 'Dato de otro medio', canal_envio: 'otro' })
  assert.deepEqual(datos.datos_medio, { remitente: 'rrhh@entidad.gob.pe', destinatario: 'grados@universidad.edu.pe', asunto: 'Verificación de título' })
  assert.equal(datos.documento_id, 'titulo')
  assert.equal(datos.remitente, undefined)
  assert.equal(datos.nombre_registro, undefined)
})

test('cambiar el medio limpia los campos específicos para evitar reutilizar destinatarios incorrectos', () => {
  const nuevo = cambiarMedioConsulta({ medio: 'correo', destinatario: 'grados@universidad.edu.pe', asunto: 'Consulta', documento_id: 'titulo' }, 'oficio')
  assert.equal(nuevo.destinatario, '')
  assert.equal(nuevo.asunto, '')
  assert.equal(nuevo.documento_id, 'titulo')
  assert.equal(nuevo.medio, 'oficio')
})

test('los datos del registro y del oficio se conservan para el detalle e informe', () => {
  const registro = prepararConsulta({ medio: 'registro_oficial', nombre_registro: 'Registro de títulos', url_registro: 'https://ejemplo.gob.pe', criterio_busqueda: 'Documento autorizado', resultado_consulta: 'Un registro encontrado' })
  assert.equal(detalleMedio(registro).length, 4)
  const oficio = prepararConsulta({ medio: 'oficio', destinatario: 'Rectorado', canal_envio: 'mesa_partes_virtual', numero_oficio: 'OF-001' })
  assert.equal(detalleMedio(oficio)[1].valor, 'Mesa de partes virtual')
  assert.equal(oficio.numero_oficio, 'OF-001')
  assert.deepEqual(detalleMedio({ medio: 'correo' }), [])
})
