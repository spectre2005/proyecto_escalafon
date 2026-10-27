// Elección del reporte: los grupos por propósito y su orden los decide el backend (ConsultaReportes::PROPOSITOS); aquí
// no se reordenan ni se vuelven a agrupar.

export const CONSULTAS_SECCION = 'Consultas por sección del legajo'

// Al entrar sin elegir: la dotación, que resume al personal; si el perfil no la tiene, el primero disponible.
export const POR_DEFECTO = ['dotacion', 'personal']

/** Grupos en el orden en que llegan los reportes. */
export function gruposDe(reportes) {
  return [...new Set(reportes.map(reporte => reporte.grupo))]
}

/** El reporte pedido en la URL, o el de inicio. */
export function reporteElegido(reportes, tipo) {
  return reportes.find(reporte => reporte.id === tipo)
    || POR_DEFECTO.map(id => reportes.find(reporte => reporte.id === id)).find(Boolean)
    || reportes[0]
}

/** En las consultas por sección, el número de sección delante: «03 · Colegiatura…». */
export function etiquetaReporte(reporte) {
  return reporte.grupo === CONSULTAS_SECCION && reporte.seccion_origen ? `${String(reporte.seccion_origen).padStart(2, '0')} · ${reporte.titulo}` : reporte.titulo
}

// Filtros: todos se aplican al cambiarlos (los de texto, al dejar de escribir). Mismos valores por defecto que el
// backend: 30 días en Vencimientos y el año actual en Evaluaciones.
export const FILTROS = ['q', 'tipo_personal', 'unidad_id', 'estado', 'seccion', 'desde', 'hasta', 'dias', 'anio', 'regimen_id', 'nivel', 'modalidad', 'contenido']

/** Valores que muestran los filtros: los aplicados en la URL o los de por defecto. */
export function valoresIniciales(params, reporteId, anioActual = new Date().getFullYear()) {
  return Object.fromEntries(FILTROS.map(clave => [clave, params.get(clave)
    || (clave === 'dias' ? '30' : clave === 'anio' && reporteId === 'evaluaciones' ? String(anioActual) : '')]))
}

/** Aplica un filtro: lo escribe (o lo quita si queda vacío), vuelve a la primera página y conserva el resto. */
export function conFiltro(params, reporteId, clave, valor) {
  const siguiente = new URLSearchParams(params)
  siguiente.set('tipo', reporteId)
  const texto = String(valor ?? '').trim()
  if (texto) siguiente.set(clave, texto)
  else siguiente.delete(clave)
  siguiente.delete('page')
  return siguiente
}

/** «Limpiar filtros»: solo el reporte y el tamaño de página elegido. */
export function paramsLimpios(params, reporteId) {
  const siguiente = new URLSearchParams({ tipo: reporteId })
  if (params.get('por_pagina')) siguiente.set('por_pagina', params.get('por_pagina'))
  return siguiente
}

/** En las consultas por sección, «Estado» es en realidad si el registro tiene un archivo asociado. */
export function tituloFiltroEstado(reporte) {
  return reporte.seccion_origen ? 'Sustento documental' : 'Estado'
}
