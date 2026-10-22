// Parámetros de la consulta de un formato de la directiva. Un legajo elegido manda sobre la búsqueda y el estado; las
// fechas solo aplican al control de incorporaciones y la sección, a ese control y a la referencia del legajo pasivo.
export function parametrosFormato({ formato, search = '', legajo = null, estado = '', desde = '', hasta = '', seccion = '' }) {
  const params = new URLSearchParams()
  if (legajo) params.set('legajo_id', legajo.id)
  else {
    if (search.trim()) params.set('q', search.trim())
    if (estado) params.set('estado', estado)
  }
  if (formato === 'incorporaciones') {
    if (desde) params.set('desde', desde)
    if (hasta) params.set('hasta', hasta)
  }
  if (['incorporaciones', 'referencias'].includes(formato) && seccion) params.set('seccion', seccion)
  return params.toString()
}
