import assert from 'node:assert/strict'
import test from 'node:test'
import { AVISO_SUSPENSION, accionesDisponibles, cambios, etiquetaEstado, fechaCorta, proponerPeriodo, proponerSlug, proponerUbicacion, textoServicio } from './estado.js'
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

test('a new payment starts the day after the last valid period and lasts a year', () => {
  const periodos = [{ hasta: '2026-12-31', anulado: false }, { hasta: '2027-12-31', anulado: true }]
  assert.deepEqual(proponerPeriodo(periodos, '2026-06-15'), { desde: '2027-01-01', hasta: '2027-12-31' })
  // Sin periodos, o si el último ya terminó: desde hoy.
  assert.deepEqual(proponerPeriodo([], '2026-06-15'), { desde: '2026-06-15', hasta: '2027-06-14' })
  assert.deepEqual(proponerPeriodo([{ hasta: '2026-03-31', anulado: false }], '2026-06-15'), { desde: '2026-06-15', hasta: '2027-06-14' })
  assert.deepEqual(proponerPeriodo([], '2028-02-29'), { desde: '2028-02-29', hasta: '2029-02-28' })
})

test('the service state is described with local dates', () => {
  assert.equal(fechaCorta('2026-12-31'), '31/12/2026')
  assert.equal(fechaCorta(null), '—')
  assert.equal(textoServicio({ estado: 'vigente', vence_el: '2026-12-31', dias_restantes: 1 }), 'Al día hasta el 31/12/2026 (1 día)')
  assert.equal(textoServicio({ estado: 'vigente', vence_el: '2026-12-31', dias_restantes: 0 }), 'Al día hasta el 31/12/2026 (vence hoy)')
  assert.match(textoServicio({ estado: 'en_gracia', vence_el: '2026-06-10', gracia_hasta: '2026-06-17' }), /funciona hasta el 17\/06\/2026/)
  assert.match(textoServicio({ estado: 'vencido', vence_el: null, proximo_desde: '2026-07-01' }), /empieza el 01\/07\/2026/)
  assert.match(textoServicio({ estado: 'sin_periodos' }), /no se controla/)
})
