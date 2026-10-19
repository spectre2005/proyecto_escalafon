import assert from 'node:assert/strict'
import test from 'node:test'
import { resumenAdministradores, resumenAuditoria, resumenRespaldo, resumenServicio } from './resumen.js'

const aprovisionada = { aprovisionada: true }

test('service: the date that matters for each state, and an honest empty state', () => {
  assert.deepEqual(resumenServicio({ estado: 'vigente', vence_el: '2026-10-31', dias_restantes: 13 }), { texto: 'Vence el 31/10/2026', detalle: 'Quedan 13 días' })
  assert.deepEqual(resumenServicio({ estado: 'vigente', vence_el: '2026-10-31', dias_restantes: 1 }).detalle, 'Quedan 1 día')
  assert.deepEqual(resumenServicio({ estado: 'vigente', vence_el: '2026-10-19', dias_restantes: 0 }).detalle, 'Vence hoy')
  assert.deepEqual(resumenServicio({ estado: 'en_gracia', vence_el: '2026-10-10', gracia_hasta: '2026-10-20' }), { texto: 'Venció el 10/10/2026', detalle: 'Días de gracia hasta el 20/10/2026' })
  assert.deepEqual(resumenServicio({ estado: 'vencido', vence_el: '2024-12-31' }), { texto: 'Venció el 31/12/2024', detalle: 'Sin acceso' })
  assert.deepEqual(resumenServicio({ estado: 'vencido', vence_el: null, proximo_desde: '2026-11-01' }), { texto: 'Sin periodo vigente', detalle: 'El siguiente empieza el 01/11/2026' })
  assert.deepEqual(resumenServicio({ estado: 'sin_periodos' }), { texto: 'Sin pagos registrados', detalle: 'El acceso no se controla por tiempo' })
  assert.deepEqual(resumenServicio(null), { texto: 'Sin datos del servicio', detalle: null })
})

test('backups: last one with its result and verification, or why there is none', () => {
  assert.equal(resumenRespaldo({ aprovisionada: false }).texto, 'Sin aprovisionar')
  assert.deepEqual(resumenRespaldo({ ...aprovisionada, respaldo: { ultimo: null } }), { texto: 'Sin respaldos', detalle: null })
  assert.deepEqual(resumenRespaldo({ ...aprovisionada }), { texto: 'Sin respaldos', detalle: null })
  const correcto = resumenRespaldo({ ...aprovisionada, respaldo: { ultimo: { estado: 'correcto', created_at: '2026-10-18T07:00:00Z', verificacion: 'correcta' } } })
  assert.match(correcto.texto, /^Correcto · /)
  assert.equal(correcto.detalle, 'Restauración probada')
  assert.equal(resumenRespaldo({ ...aprovisionada, respaldo: { ultimo: { estado: 'fallido', created_at: '2026-10-18T07:00:00Z', verificacion: null } } }).detalle, 'Restauración sin probar')
  assert.match(resumenRespaldo({ ...aprovisionada, respaldo: { ultimo: { estado: 'fallido', created_at: '2026-10-18T07:00:00Z' } } }).texto, /^Fallido · /)
})

test('administrators: counts only from the list already loaded, never a guess', () => {
  assert.equal(resumenAdministradores({ aprovisionada: false }, { loading: true }).texto, 'Sin aprovisionar')
  assert.equal(resumenAdministradores(aprovisionada, { loading: true }).texto, 'Cargando…')
  assert.equal(resumenAdministradores(aprovisionada, undefined).texto, 'Cargando…')
  assert.deepEqual(resumenAdministradores(aprovisionada, { error: true }), { texto: 'No disponible', detalle: 'No se pudo leer la lista' })
  assert.deepEqual(resumenAdministradores(aprovisionada, { lista: [] }), { texto: 'Sin administradores', detalle: null })
  assert.deepEqual(resumenAdministradores(aprovisionada, { lista: [{ activo: true }, { activo: false }, { activo: true }] }), { texto: '2 activos', detalle: 'de 3 en total' })
  assert.deepEqual(resumenAdministradores(aprovisionada, { lista: [{ activo: true }] }), { texto: '1 activo', detalle: 'de 1 en total' })
  assert.deepEqual(resumenAdministradores(aprovisionada, { lista: [{ activo: false }] }), { texto: '0 activos', detalle: 'de 1 en total' })
})

test('audit: the most recent operation, or the same empty text as its section', () => {
  assert.deepEqual(resumenAuditoria([]), { texto: 'Sin operaciones registradas', detalle: null })
  assert.deepEqual(resumenAuditoria(undefined), { texto: 'Sin operaciones registradas', detalle: null })
  const r = resumenAuditoria([{ accion: 'institucion.suspendida', created_at: '2026-10-18T07:00:00Z', administrador: 'Ana' }, { accion: 'institucion.activada' }])
  assert.equal(r.texto, 'Suspendió la institución')
  assert.match(r.detalle, / · Ana$/)
  assert.match(resumenAuditoria([{ accion: 'x.desconocida', created_at: '2026-10-18T07:00:00Z', administrador: null }]).detalle, / · Sistema$/)
})
