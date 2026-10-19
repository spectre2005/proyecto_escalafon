import { todayLima } from '../../shared/dates.js'

export const personName = person => person ? [person.nombres, person.apellido_paterno, person.apellido_materno].filter(Boolean).join(' ') : 'Persona no disponible'
export const stateLabels = { abierto: 'Abierto', pendiente: 'Pendiente', en_revision: 'En revisión', observado: 'Observado', cumplido: 'Cumplido', cancelado: 'Cancelado', vencido: 'Vencido (histórico)', prorrogado: 'Prorrogado (histórico)' }
export const isClosed = record => Boolean(record.cerrado_en || ['cumplido', 'cancelado'].includes(record.estado))
export const effectiveDeadline = record => (record.prorroga_hasta || record.fecha_limite)?.slice(0, 10) || ''
export const isOverdue = (record, hoy = todayLima()) => !isClosed(record) && Boolean(effectiveDeadline(record)) && effectiveDeadline(record) < hoy

export function availableActions(record) {
  if (isClosed(record)) return []
  const actions = record.estado === 'en_revision' ? ['cumplir', 'observar'] : ['responder']
  if (!record.documento_id && ['abierto', 'pendiente', 'vencido', 'prorrogado'].includes(record.estado)) actions.push('editar')
  return [...actions, 'prorrogar', 'cancelar']
}
export function extensionMin(record, hoy = todayLima()) {
  const limite = effectiveDeadline(record)
  if (!limite) return hoy
  const next = new Date(`${limite}T00:00:00Z`)
  next.setUTCDate(next.getUTCDate() + 1)
  const fecha = next.toISOString().slice(0, 10)
  return fecha > hoy ? fecha : hoy
}
export function filterTypes(types, query = '', seccion = '') {
  const normalize = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
  const words = normalize(query).trim().split(/\s+/).filter(Boolean)
  return types.filter(type => (!seccion || String(type.seccion_legajo_id) === String(seccion)) && words.every(word => normalize(`${type.nombre} ${type.codigo || ''}`).includes(word)))
}
