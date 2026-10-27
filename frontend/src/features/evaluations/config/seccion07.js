// Sección 07 del legajo: evaluación de desempeño, progresión en la carrera y desplazamiento.
// Cada tipo tiene su pestaña, su etiqueta de año, sus columnas propias y la funcionalidad configurable que la muestra.
export const tiposSeccion07 = [
  { key: 'evaluaciones', label: 'Evaluaciones de desempeño', anio: 'Ciclo', vacio: 'No hay evaluaciones de desempeño con estos filtros.', funcionalidad: 's07.evaluaciones' },
  { key: 'progresiones', label: 'Progresiones en la carrera', anio: 'Año', vacio: 'No hay progresiones en la carrera con estos filtros.', funcionalidad: 's07.progresion' },
  { key: 'ratificaciones', label: 'Ratificaciones docentes', anio: 'Año', vacio: 'No hay ratificaciones docentes con estos filtros.', funcionalidad: 's07.progresion' },
  { key: 'desplazamientos', label: 'Desplazamientos', anio: 'Año', vacio: 'No hay desplazamientos con estos filtros.', funcionalidad: 's07.desplazamientos' },
]

// Pestañas de las funcionalidades activas (estado de GET /funcionalidades; sin estado, todas).
export function tiposVisibles(estado) {
  return tiposSeccion07.filter(tipo => estado?.activas?.[tipo.funcionalidad] !== false)
}

export function findTipo(key, tipos = tiposSeccion07) {
  return tipos.find(tipo => tipo.key === key) || tipos[0]
}

export const porPagina = [10, 20, 50]

/**
 * Filtros de la pestaña Evaluaciones traducidos a los del reporte que se exporta (/reportes/evaluaciones): sin año se
 * exportan todos los ciclos, como en la tabla («anio» vacío; sin él, el reporte tomaría el año actual).
 */
export function filtrosExportacion(params) {
  const filtros = new URLSearchParams({ anio: params.get('anio') || '' })
  if (params.get('q')) filtros.set('q', params.get('q'))
  if (params.get('unidad_organica_id')) filtros.set('unidad_id', params.get('unidad_organica_id'))
  if (['1', 'true'].includes(params.get('plan_mejora'))) filtros.set('estado', 'con_plan')
  return filtros
}
