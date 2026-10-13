import assert from 'node:assert/strict'
import test from 'node:test'
import { AVISO_SUSPENSION, accionesDisponibles, cambios, etiquetaEstado, proponerSlug, proponerUbicacion } from './estado.js'
import { esEscritura, mensajeError } from './api.js'

test('state actions follow what the backend allows', () => {
  assert.deepEqual(accionesDisponibles({ estado: 'registrada', aprovisionada: false, motivo_no_activable: 'Aún no está aprovisionada.', motivo_no_suspendible: 'No opera.' }),
    { aprovisionar: true, activar: false, suspender: false, textoActivar: 'Activar' })
  assert.deepEqual(accionesDisponibles({ estado: 'registrada', aprovisionada: true, motivo_no_activable: null, motivo_no_suspendible: 'No opera.' }),
    { aprovisionar: false, activar: true, suspender: false, textoActivar: 'Activar' })
  assert.deepEqual(accionesDisponibles({ estado: 'activa', aprovisionada: true, motivo_no_activable: 'Ya está activa.', motivo_no_suspendible: null }),
    { aprovisionar: false, activar: false, suspender: true, textoActivar: 'Activar' })
  assert.deepEqual(accionesDisponibles({ estado: 'suspendida', aprovisionada: true, motivo_no_activable: null, motivo_no_suspendible: 'Ya está suspendida.' }),
    { aprovisionar: false, activar: true, suspender: false, textoActivar: 'Reactivar' })
  assert.equal(accionesDisponibles(null).activar, false)
  assert.equal(etiquetaEstado('suspendida'), 'Suspendida')
  // Sin identificar la institución por subdominio, suspender no bloquea el acceso: se avisa junto al botón.
  assert.equal(AVISO_SUSPENSION, 'La suspensión queda registrada, pero aún no bloquea el acceso de la institución.')
})

test('identifier and location are proposed from the name', () => {
  assert.equal(proponerSlug('  Institución Ñandú del Sur '), 'institucion-nandu-del-sur')
  assert.equal(proponerSlug('a'.repeat(39) + ' b'), 'a'.repeat(39))
  assert.deepEqual(proponerUbicacion('nandu-sur'), { base_datos: 'escalafon_nandu_sur', almacenamiento: 'instituciones/nandu-sur' })
  assert.deepEqual(proponerUbicacion(''), { base_datos: '', almacenamiento: '' })
})

test('only changed fields are sent, and location only while editable', () => {
  const original = { nombre: 'Uno', slug: 'uno', base_datos: 'escalafon_uno', almacenamiento: 'instituciones/uno', ubicacion_editable: true }
  assert.deepEqual(cambios(original, { ...original, nombre: ' Uno II ', slug: 'uno-ii' }), { nombre: 'Uno II', slug: 'uno-ii' })
  assert.deepEqual(cambios({ ...original, ubicacion_editable: false }, { ...original, slug: 'otro' }), {})
})

test('api errors become readable messages', () => {
  assert.equal(mensajeError({ response: { status: 422, data: { errors: { slug: ['Ya existe.'], nombre: ['Muy corto.'] } } } }), 'Ya existe. Muy corto.')
  assert.equal(mensajeError({ response: { status: 409, data: { message: 'Aún no está aprovisionada.' } } }), 'Aún no está aprovisionada.')
  assert.match(mensajeError({ response: { status: 419, data: {} } }), /sesión terminó/)
  assert.match(mensajeError({}), /conectar/)
})

test('the platform CSRF token is only sent on writes', () => {
  assert.deepEqual(['get', 'GET', 'head', 'options', undefined].map(esEscritura), [false, false, false, false, false])
  assert.deepEqual(['post', 'patch', 'PUT', 'delete'].map(esEscritura), [true, true, true, true])
})
