import assert from 'node:assert/strict'
import test from 'node:test'
import { AVISO_SUSPENSION, AVISO_SUSPENSION_BLOQUEA, COPIA_EXTERNA, VERIFICACION, ACCION_SUPERADMIN, ESTADO_TECNICO, cifra, resumenTecnico, textoEspacio, accionesDisponibles, accionesSuperadmin, cambiosCliente, importe, textoVencimiento, etiquetaAccion, tamano, cambios, etiquetaEstado, fechaCorta, proponerPeriodo, proponerSlug, proponerUbicacion, textoServicio } from './estado.js'
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
})

test('the suspension notices describe what really happens', () => {
  // Con subdominio, suspender bloquea el acceso, también con la sesión abierta.
  assert.match(AVISO_SUSPENSION_BLOQUEA, /pierden el acceso de inmediato/)
  assert.match(AVISO_SUSPENSION_BLOQUEA, /sesión abierta/)
  // Con una sola institución (modo unica) no bloquea, y el aviso dice por qué; ya no lo presenta como algo que falta.
  assert.match(AVISO_SUSPENSION, /PLATAFORMA_MODO=unica/)
  assert.match(AVISO_SUSPENSION, /subdominio/)
  assert.doesNotMatch(AVISO_SUSPENSION, /aún no bloquea/)
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

test('backup sizes and states are readable', () => {
  assert.equal(tamano(0), '0 B')
  assert.equal(tamano(512), '512 B')
  assert.equal(tamano(1536), '1,5 KB')
  assert.equal(tamano(217655), '212,6 KB')
  assert.equal(tamano(5 * 1024 ** 3), '5 GB')
  assert.equal(tamano(null), '—')
  // La copia externa siempre es cifrada; si no hay, se dice.
  assert.match(COPIA_EXTERNA.correcta.etiqueta, /cifrada/)
  assert.match(COPIA_EXTERNA.no_configurada.etiqueta, /Sin copia externa/)
  assert.match(VERIFICACION.correcta.etiqueta, /Restauración probada/)
  assert.equal(etiquetaAccion('respaldo.fallido'), 'Falló un respaldo')
})

test('only the changed client fields are sent, and emptying one removes it', () => {
  const original = { razon_social: 'Universidad Uno', ruc: '20100000009', contacto_email: null, contrato_inicio: '2026-03-01' }
  assert.deepEqual(cambiosCliente(original, { ...original, razon_social: ' Universidad Uno ' }), {})
  assert.deepEqual(cambiosCliente(original, { ...original, contacto_email: ' orh@uno.test ', ruc: '' }), { contacto_email: 'orh@uno.test', ruc: null })
  assert.deepEqual(cambiosCliente(original, { ...original, contrato_inicio: '2026-04-01' }), { contrato_inicio: '2026-04-01' })
})

test('income amounts are shown with two decimals and thousands', () => {
  assert.equal(importe('0.00'), 'S/ 0.00')
  assert.equal(importe('185.5'), 'S/ 185.50')
  assert.equal(importe('1234567.8'), 'S/ 1,234,567.80')
  assert.equal(importe(null), 'S/ 0.00')
})

test('each due date is described from its group', () => {
  assert.equal(textoVencimiento('por_vencer', { dias_restantes: 0, vence_el: '2026-12-31' }), 'Vence hoy (31/12/2026)')
  assert.equal(textoVencimiento('por_vencer', { dias_restantes: 1, vence_el: '2027-01-01' }), 'Vence en 1 día, el 01/01/2027')
  assert.match(textoVencimiento('en_gracia', { vence_el: '2026-12-28', gracia_hasta: '2027-01-02', dias_gracia_restantes: 2 }), /le quedan 2 días de gracia \(hasta el 02\/01\/2027\)/)
  assert.match(textoVencimiento('en_gracia', { vence_el: '2026-12-28', gracia_hasta: '2026-12-31', dias_gracia_restantes: 0 }), /último día de gracia/)
  assert.equal(textoVencimiento('vencidas', { vence_el: '2026-12-01', dias_vencida: 30 }), 'Venció el 01/12/2026, hace 30 días: sin acceso')
  assert.match(textoVencimiento('vencidas', { vence_el: null, proximo_desde: '2027-02-01' }), /empieza el 01\/02\/2027/)
  assert.match(textoVencimiento('vencidas', { vence_el: null, proximo_desde: null }), /Sin un periodo pagado vigente/)
})

test('superadministrator actions never apply to the own account', () => {
  assert.deepEqual(accionesSuperadmin({ activo: true, es_actual: true }), [])
  assert.deepEqual(accionesSuperadmin({ activo: true, es_actual: false }), ['restablecer', 'desactivar'])
  assert.deepEqual(accionesSuperadmin({ activo: false, es_actual: false }), ['reactivar'])
  assert.deepEqual(accionesSuperadmin(null), [])
  // Lo que se avisa antes de confirmar: sesiones cerradas y contraseña temporal nueva al reactivar.
  assert.match(ACCION_SUPERADMIN.restablecer.pregunta, /cerrarán sus sesiones/)
  assert.match(ACCION_SUPERADMIN.desactivar.pregunta, /sesiones abiertas/)
  assert.match(ACCION_SUPERADMIN.reactivar.pregunta, /contraseña temporal nueva/)
  assert.equal(etiquetaAccion('administrador.desactivado'), 'Desactivó a un superadministrador')
  assert.equal(etiquetaAccion('administrador.clave_cambiada'), 'Cambió su propia contraseña')
})

test('technical state is never shown as correct without a check', () => {
  assert.deepEqual(resumenTecnico({ comprobaciones: [{ estado: 'correcto' }, { estado: 'no_disponible' }] }), { estado: 'correcto', texto: 'Sin problemas detectados' })
  assert.deepEqual(resumenTecnico({ comprobaciones: [{ estado: 'correcto' }, { estado: 'aviso' }, { estado: 'aviso' }] }), { estado: 'aviso', texto: '2 avisos' })
  assert.deepEqual(resumenTecnico({ comprobaciones: [{ estado: 'error' }, { estado: 'no_verificado' }, { estado: 'aviso' }] }), { estado: 'error', texto: '1 error y 1 aviso' })
  // Sin nada comprobado (sin aprovisionar o solo «no verificado»): no se dice «correcto».
  assert.equal(resumenTecnico({ comprobaciones: [{ estado: 'no_disponible' }] }).estado, 'no_disponible')
  assert.equal(resumenTecnico({ comprobaciones: [{ estado: 'no_verificado' }] }).estado, 'no_disponible')
  assert.equal(resumenTecnico(null).estado, 'no_disponible')
  assert.equal(ESTADO_TECNICO.no_verificado.etiqueta, 'No se pudo comprobar')
  // Una métrica no disponible se muestra como «—», no como 0.
  assert.equal(cifra(null), '—')
  assert.equal(cifra(undefined), '—')
  assert.equal(cifra(0), '0')
})

test('an incomplete storage measure says so in the figure itself', () => {
  // Enlaces a carpetas de fuera (como UNIQ en local): 0 B no se presenta como una medición completa.
  const uniq = textoEspacio({ bytes: 0, archivos: 0, parcial: false, enlaces_externos: 3 })
  assert.equal(uniq.texto, '0 B (incompleto)')
  assert.equal(uniq.completo, false)
  assert.match(uniq.nota, /no cuenta 3 enlaces a carpetas de fuera/)
  assert.match(textoEspacio({ bytes: 2048, archivos: 9, parcial: true, enlaces_externos: 0 }).nota, /recuento parcial/)
  assert.deepEqual(textoEspacio({ bytes: 1536, archivos: 2, parcial: false, enlaces_externos: 0 }), { texto: '1,5 KB', completo: true, nota: '2 archivos' })
  assert.deepEqual(textoEspacio(null), { texto: '—', completo: false, nota: 'No se pudo medir.' })
})
