// Elección del reporte: los grupos por propósito y su orden los decide el backend (ConsultaReportes::PROPOSITOS); aquí
// no se reordenan ni se vuelven a agrupar.

export const CONSULTAS_SECCION = 'Consultas por sección del legajo'

// Al entrar sin elegir: la dotación, que resume al personal; si el perfil no la tiene, el primero disponible.
export const POR_DEFECTO = ['dotacion', 'personal']

/** Grupos en el orden en que llegan los reportes. */
export function gruposDe(reportes) {
  return [...new Set(reportes.map(reporte => reporte.grupo))]
}

// Pestañas: nombre que ve el usuario de cada grupo del backend. Es el único lugar donde se renombran; un grupo nuevo que
// no esté aquí sale con su propio nombre. «Directiva» (anexos 06 a 09) es otra página, no un grupo de reportes.
export const FAMILIAS = [
  { id: 'personal', grupo: 'Personal y dotación', etiqueta: 'Personal y dotación' },
  { id: 'contratos', grupo: 'Contratos y plazos', etiqueta: 'Contratos y plazos' },
  { id: 'control', grupo: 'Control documental', etiqueta: 'Control del legajo' },
  { id: 'formacion', grupo: 'Formación y desarrollo', etiqueta: 'Formación' },
  { id: 'asistencia', grupo: 'Vacaciones y licencias', etiqueta: 'Asistencia' },
]
export const DIRECTIVA = { id: 'directiva', etiqueta: 'Directiva' }

/** La pestaña de un grupo del backend; las consultas por sección no tienen pestaña («Más reportes»). */
export function familiaDe(grupo) {
  if (!grupo || grupo === CONSULTAS_SECCION) return null
  return FAMILIAS.find(familia => familia.grupo === grupo) || { id: grupo, grupo, etiqueta: grupo }
}

/** Pestañas con al menos un reporte disponible, en el orden del backend. */
export function familiasDe(reportes) {
  return gruposDe(reportes).map(familiaDe).filter(Boolean)
}

/** El reporte pedido en la URL; si no hay, el primero de la pestaña elegida o el de inicio. */
export function reporteElegido(reportes, tipo, familia = null) {
  const grupo = familiasDe(reportes).find(item => item.id === familia)?.grupo
  return reportes.find(reporte => reporte.id === tipo)
    || (grupo && reportes.find(reporte => reporte.grupo === grupo))
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
