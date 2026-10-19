import { VERIFICACION, etiquetaAccion, fecha, fechaCorta } from './estado.js'

/**
 * Textos de la franja de resumen del detalle de una institución. Solo usan datos que la página ya tiene: nunca piden
 * nada al backend. Cada uno devuelve { texto, detalle } (detalle puede ser null) y, si falta el dato, lo dice.
 */

/** Servicio (institucion.servicio): la fecha que importa según su estado. */
export function resumenServicio(servicio) {
  if (!servicio) return { texto: 'Sin datos del servicio', detalle: null }
  if (servicio.estado === 'vigente') return { texto: `Vence el ${fechaCorta(servicio.vence_el)}`, detalle: servicio.dias_restantes === 0 ? 'Vence hoy' : `Quedan ${servicio.dias_restantes} día${servicio.dias_restantes === 1 ? '' : 's'}` }
  if (servicio.estado === 'en_gracia') return { texto: `Venció el ${fechaCorta(servicio.vence_el)}`, detalle: `Días de gracia hasta el ${fechaCorta(servicio.gracia_hasta)}` }
  if (servicio.estado === 'vencido') return servicio.vence_el ? { texto: `Venció el ${fechaCorta(servicio.vence_el)}`, detalle: 'Sin acceso' } : { texto: 'Sin periodo vigente', detalle: servicio.proximo_desde ? `El siguiente empieza el ${fechaCorta(servicio.proximo_desde)}` : 'Sin acceso' }
  return { texto: 'Sin pagos registrados', detalle: 'El acceso no se controla por tiempo' }
}

/** Respaldos (institucion.respaldo.ultimo: el más reciente, correcto o no). */
export function resumenRespaldo(institucion) {
  if (!institucion.aprovisionada) return { texto: 'Sin aprovisionar', detalle: 'Se respalda una vez aprovisionada' }
  const ultimo = institucion.respaldo?.ultimo
  if (!ultimo) return { texto: 'Sin respaldos', detalle: null }
  return {
    texto: `${ultimo.estado === 'correcto' ? 'Correcto' : 'Fallido'} · ${fecha(ultimo.created_at)}`,
    detalle: ultimo.verificacion ? VERIFICACION[ultimo.verificacion]?.etiqueta || ultimo.verificacion : 'Restauración sin probar',
  }
}

/** Administradores (lo que AdministradoresInstitucion ya cargó): { loading } | { error } | { lista }. */
export function resumenAdministradores(institucion, estado) {
  if (!institucion.aprovisionada) return { texto: 'Sin aprovisionar', detalle: 'El primero se crea al aprovisionar' }
  if (!estado || estado.loading) return { texto: 'Cargando…', detalle: null }
  if (estado.error || !Array.isArray(estado.lista)) return { texto: 'No disponible', detalle: 'No se pudo leer la lista' }
  const activos = estado.lista.filter(admin => admin.activo).length
  if (estado.lista.length === 0) return { texto: 'Sin administradores', detalle: null }
  return { texto: `${activos} activo${activos === 1 ? '' : 's'}`, detalle: `de ${estado.lista.length} en total` }
}

/** Auditoría (institucion.auditoria: la más reciente primero). */
export function resumenAuditoria(auditoria) {
  const evento = auditoria?.[0]
  if (!evento) return { texto: 'Sin operaciones registradas', detalle: null }
  return { texto: etiquetaAccion(evento.accion), detalle: `${fecha(evento.created_at)} · ${evento.administrador || 'Sistema'}` }
}
