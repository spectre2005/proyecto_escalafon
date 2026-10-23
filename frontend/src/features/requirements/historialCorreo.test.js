import test from 'node:test'
import assert from 'node:assert/strict'
import { separarHistorialCorreo } from './historialCorreo.js'

test('separa la respuesta del encabezado de Gmail con fecha y remitente', () => {
  const historial = 'El jue., 22 de octubre de 2026 3:35 p. m., <docente@example.com>\nescribió:\n\n> Documento solicitado\n> Aviso legal'
  assert.deepEqual(separarHistorialCorreo(`Buenas noches, adjunto lo solicitado.\n\n${historial}`), {
    respuesta: 'Buenas noches, adjunto lo solicitado.', historial,
  })
})

test('conserva mensajes sin citas y valores vacíos', () => {
  assert.deepEqual(separarHistorialCorreo('El documento corresponde a mi respuesta.\nGracias.'), {
    respuesta: 'El documento corresponde a mi respuesta.\nGracias.', historial: '',
  })
  assert.deepEqual(separarHistorialCorreo(null), { respuesta: '', historial: '' })
})

test('reconoce citas en inglés, citas sin encabezado y mensajes originales', () => {
  for (const historial of ['On Thu, Oct 22, 2026, sender@example.com wrote:\n> Request', '> Solicitud anterior', '-----Mensaje original-----\nFecha: ayer']) {
    assert.deepEqual(separarHistorialCorreo(`Adjunto PDF.\r\n\r\n${historial}`), {
      respuesta: 'Adjunto PDF.', historial,
    })
  }
})
