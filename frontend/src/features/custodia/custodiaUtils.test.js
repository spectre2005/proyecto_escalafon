import assert from 'node:assert/strict'
import test from 'node:test'
import { autorizacionVigente, estadoSolicitud, estadoPrestamo, filtrosSolicitudes, filtrosPrestamos, contieneSensibles } from './custodiaUtils.js'

const hoy = '2026-10-19'
test('authorization includes the final day and blocks revoked, expired, pending or undated requests', () => {
  assert.equal(autorizacionVigente({ estado: 'aprobada', vigente_hasta: hoy }, hoy), true)
  assert.equal(estadoSolicitud({ estado: 'aprobada', vigente_hasta: '2026-10-18' }, hoy), 'vencida')
  assert.equal(estadoSolicitud({ estado: 'aprobada', vigente_hasta: null }, hoy), 'sin_vigencia')
  for (const estado of ['pendiente', 'rechazada', 'revocada']) assert.equal(autorizacionVigente({ estado, vigente_hasta: '2026-10-30' }, hoy), false)
})
test('returned loans are never shown as overdue even when the original deadline has passed', () => {
  assert.equal(estadoPrestamo({ fecha_devolucion_prevista: '2026-10-18' }, hoy), 'atrasado')
  assert.equal(estadoPrestamo({ fecha_devolucion_prevista: hoy }, hoy), 'pendiente')
  assert.equal(estadoPrestamo({ fecha_devolucion_prevista: '2026-10-18', fecha_retorno: hoy }, hoy), 'devuelto')
  assert.equal(estadoPrestamo({ fecha_devolucion_prevista: '2026-10-18', devuelto_en: `${hoy}T10:00:00Z` }, hoy), 'devuelto')
})
test('expired authorization filter uses the effective date instead of inventing a persisted state', () => {
  const params = new URLSearchParams(filtrosSolicitudes({ q: '  Ana Torres ', estado: 'vencida', forma: 'prestamo', page: 2 }))
  assert.equal(params.get('vencidas'), '1')
  assert.equal(params.has('estado'), false)
  assert.equal(params.get('q'), 'Ana Torres')
  assert.equal(params.get('forma_entrega'), 'prestamo')
  assert.equal(params.get('page'), '2')
})
test('returned and all-loan queries do not accidentally keep the pending restriction', () => {
  const devueltos = new URLSearchParams(filtrosPrestamos({ mode: 'devueltos', q: 'INV-001' }))
  assert.equal(devueltos.get('devueltos'), '1')
  assert.equal(devueltos.has('pendientes'), false)
  assert.equal(devueltos.has('atrasados'), false)
  assert.equal(filtrosPrestamos({ mode: 'todos' }), 'page=1')
})
test('sensitive document handling checks both the actual section and type flags', () => {
  assert.equal(contieneSensibles({ seccion_legajo_id: 3, tipo_documento: { es_dato_sensible: false, es_dato_salud: false } }), false)
  assert.equal(contieneSensibles({ seccion_legajo_id: 10, tipo_documento: {} }), true)
  assert.equal(contieneSensibles({ seccion_legajo_id: 12, tipo_documento: { es_dato_sensible: true } }), true)
  assert.equal(contieneSensibles({ seccion_legajo_id: 3, tipo_documento: { es_dato_salud: true } }), true)
})
