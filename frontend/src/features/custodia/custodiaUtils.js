import { todayLima } from '../../shared/dates.js'

export const nombreTitular = legajo => [legajo?.usuario?.nombres, legajo?.usuario?.apellido_paterno, legajo?.usuario?.apellido_materno].filter(Boolean).join(' ')
export const contieneSensibles = documento => [1, 9, 10].includes(Number(documento.seccion_legajo_id)) || Boolean(documento.tipo_documento?.es_dato_sensible || documento.tipo_documento?.es_dato_salud)

export function autorizacionVigente(solicitud, hoy = todayLima()) {
  return solicitud.estado === 'aprobada' && Boolean(solicitud.vigente_hasta) && solicitud.vigente_hasta.slice(0, 10) >= hoy
}

export function estadoSolicitud(solicitud, hoy = todayLima()) {
  if (solicitud.estado === 'aprobada' && !autorizacionVigente(solicitud, hoy)) return solicitud.vigente_hasta ? 'vencida' : 'sin_vigencia'
  return solicitud.estado
}

export function estadoPrestamo(prestamo, hoy = todayLima()) {
  if (prestamo.fecha_retorno || prestamo.devuelto_en) return 'devuelto'
  return prestamo.fecha_devolucion_prevista && prestamo.fecha_devolucion_prevista.slice(0, 10) < hoy ? 'atrasado' : 'pendiente'
}

export function filtrosSolicitudes({ q = '', estado = '', forma = '', page = 1 }) {
  const params = new URLSearchParams({ page })
  if (q.trim()) params.set('q', q.trim())
  if (estado === 'vencida') params.set('vencidas', '1')
  else if (estado) params.set('estado', estado)
  if (forma) params.set('forma_entrega', forma)
  return params.toString()
}

export function filtrosPrestamos({ mode = 'pendientes', q = '', page = 1 }) {
  const params = new URLSearchParams({ page })
  if (q.trim()) params.set('q', q.trim())
  if (mode !== 'todos') params.set(mode, '1')
  return params.toString()
}

export const formas = { consulta: 'Consulta documental', copia: 'Entrega de copia', prestamo: 'Préstamo de ejemplar' }
