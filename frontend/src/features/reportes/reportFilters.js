export function reportParams({ formato, search = '', legajo = null, estado = '', desde = '', hasta = '', seccion = '' }) {
  const params = new URLSearchParams()
  if (legajo) params.set('legajo_id', legajo.id)
  else {
    if (search.trim()) params.set('q', search.trim())
    if (estado) params.set('estado', estado)
  }
  if (formato === 'incorporaciones') {
    if (desde) params.set('desde', desde)
    if (hasta) params.set('hasta', hasta)
    if (seccion) params.set('seccion', seccion)
  }
  return params.toString()
}
