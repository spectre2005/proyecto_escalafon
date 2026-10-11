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
