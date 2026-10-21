/**
 * Pestañas del detalle de una institución. La activa va en el parámetro de URL «seccion» (como EvaluacionesPage): sin
 * él, o con un valor desconocido, se abre la predeterminada. Los paneles se ocultan, nunca se desmontan.
 */
export const PESTANAS = [
  { clave: 'servicio', titulo: 'Servicio y pagos' },
  { clave: 'administradores', titulo: 'Administradores' },
  { clave: 'datos', titulo: 'Datos y contrato' },
  { clave: 'tecnico', titulo: 'Uso y respaldos' },
  { clave: 'auditoria', titulo: 'Auditoría' },
]

export const PREDETERMINADA = 'servicio'

export const PARAMETRO = 'seccion'

/** La pestaña que corresponde a un valor del parámetro (null o desconocido: la predeterminada). */
export const pestanaDe = valor => (PESTANAS.some(p => p.clave === valor) ? valor : PREDETERMINADA)

export const tituloPestana = clave => PESTANAS.find(p => p.clave === clave)?.titulo || ''

/** id estables de cada pestaña y su panel. */
export const idPestana = clave => `institucion-pestana-${clave}`
export const idPanel = clave => `institucion-panel-${clave}`

/** Los parámetros con «seccion» cambiado; los demás se conservan. */
export function conSeccion(params, clave) {
  const siguiente = new URLSearchParams(params)
  siguiente.set(PARAMETRO, clave)
  return siguiente
}
