import test from 'node:test'
import { setImmediate as siguienteTurno } from 'node:timers/promises'
import assert from 'node:assert/strict'
import { observarCargas } from './observadorCargas.js'

function cliente() {
  const callbacks = {}
  const api = { interceptors: { response: {
    use(success, failure) { callbacks.success = success; callbacks.failure = failure; return 42 },
    eject(id) { callbacks.eliminado = id },
  } } }
  return { api, callbacks }
}

test('entrega la misma respuesta al formulario aunque la verificación aún esté pendiente', async () => {
  const { api, callbacks } = cliente()
  const documentos = []
  let terminar
  const espera = new Promise(resolve => { terminar = resolve })
  const liberar = observarCargas(api, 'persona', id => { documentos.push(id); return espera }, assert.fail)
  const response = { status: 201, config: { method: 'post', url: '/expedientes/persona/documentos' }, data: { data: { id: 'documento', formulario_datos: 'familiares', enlazado: false } } }
  assert.equal(callbacks.success(response), response)
  assert.deepEqual(documentos, [])
  await siguienteTurno()
  assert.deepEqual(documentos, ['documento'])
  assert.equal(response.data.data.formulario_datos, 'familiares')
  terminar()
  liberar()
  assert.equal(callbacks.eliminado, 42)
})

test('propaga el mismo error de guardado y nunca confirma una carga fallida', async () => {
  const { api, callbacks } = cliente()
  const errores = []
  observarCargas(api, 'persona', assert.fail, error => errores.push(error))
  const error = { config: { method: 'post', url: '/expedientes/persona/seccion-10/documentos' }, response: { status: 422 } }
  await assert.rejects(callbacks.failure(error), failure => failure === error)
  assert.deepEqual(errores, [error])
})

test('al salir del modo guiado descarta notificaciones pendientes sin tocar la carga', async () => {
  const { api, callbacks } = cliente()
  const liberar = observarCargas(api, 'persona', assert.fail, assert.fail)
  const response = { status: 201, config: { method: 'post', url: '/expedientes/persona/seccion-03/documentos' }, data: { data: { id: 'documento' } } }
  assert.equal(callbacks.success(response), response)
  liberar()
  await siguienteTurno()
})
