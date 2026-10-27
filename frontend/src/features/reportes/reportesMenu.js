// Elección del reporte: los grupos por propósito y su orden los decide el backend (ConsultaReportes::PROPOSITOS); aquí
// no se reordenan ni se vuelven a agrupar.

export const CONSULTAS_SECCION = 'Consultas por sección del legajo'
// Nombre que ve el usuario para esas consultas, plegadas aparte de las pestañas.
export const MAS_REPORTES = 'Más reportes'

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

// Filtros: se editan juntos en el panel «Filtros» y se aplican con «Aplicar»; quitar una etiqueta o «Limpiar» se aplica
// al instante. Mismos valores por defecto que el backend: 30 días en Vencimientos y el año actual en Evaluaciones.
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

/** «Aplicar»: los campos del formulario (solo los que se muestran para este reporte) reemplazan a los aplicados. */
export function conFiltros(params, reporteId, campos) {
  let siguiente = conFiltro(params, reporteId, 'page', '')
  for (const [clave, valor] of campos) if (FILTROS.includes(clave)) siguiente = conFiltro(siguiente, reporteId, clave, valor)
  return siguiente
}

const fecha = valor => valor.split('-').reverse().join('/')

/** Etiquetas de los filtros aplicados en la URL, con el nombre de cada opción (unidad, régimen, estado…). */
export function filtrosActivos(params, reporte, opciones) {
  const nombre = (lista, valor) => lista?.find(item => String(item.id) === valor)
  const texto = {
    q: valor => `Persona: ${valor}`,
    tipo_personal: valor => ({ docente: 'Docentes', administrativo: 'Administrativos' })[valor] || valor,
    unidad_id: valor => `Unidad: ${nombre(opciones.unidades, valor)?.nombre || valor}`,
    estado: valor => `${tituloFiltroEstado(reporte)}: ${reporte.estados?.[valor] || valor}`,
    seccion: valor => `Sección ${String(valor).padStart(2, '0')}`,
    desde: valor => `Desde ${fecha(valor)}`,
    hasta: valor => `Hasta ${fecha(valor)}`,
    dias: valor => `Próximos ${valor} días`,
    anio: valor => `${reporte.id === 'evaluaciones' ? 'Ciclo' : 'Año'} ${valor}`,
    regimen_id: valor => `Régimen: ${nombre(opciones.regimenes, valor)?.codigo || valor}`,
    nivel: valor => nombre(opciones.niveles, valor)?.nombre || valor,
    modalidad: valor => nombre(opciones.modalidades, valor)?.nombre || valor,
    contenido: valor => `Texto: ${valor}`,
  }
  return FILTROS.filter(clave => params.get(clave)).map(clave => ({ clave, etiqueta: texto[clave](params.get(clave)) }))
}

/** «Limpiar filtros»: solo el reporte y el tamaño de página elegido. */
export function paramsLimpios(params, reporteId) {
  const siguiente = new URLSearchParams({ tipo: reporteId })
  if (params.get('por_pagina')) siguiente.set('por_pagina', params.get('por_pagina'))
  return siguiente
}

/** Nombre real del «estado» de cada reporte: el sustento en las consultas por sección, la dedicación en la plana docente. */
export function tituloFiltroEstado(reporte) {
  return reporte.seccion_origen ? 'Sustento documental' : reporte.id === 'plana_docente' ? 'Dedicación' : 'Estado'
}
