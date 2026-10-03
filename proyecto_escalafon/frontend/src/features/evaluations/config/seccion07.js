// Sección 07 del legajo: evaluación de desempeño, progresión en la carrera y desplazamiento.
// Cada tipo tiene su pestaña, su etiqueta de año y sus columnas propias.
export const tiposSeccion07 = [
  { key: 'evaluaciones', label: 'Evaluaciones de desempeño', anio: 'Ciclo', vacio: 'No hay evaluaciones de desempeño con estos filtros.' },
  { key: 'progresiones', label: 'Progresiones en la carrera', anio: 'Año', vacio: 'No hay progresiones en la carrera con estos filtros.' },
  { key: 'ratificaciones', label: 'Ratificaciones docentes', anio: 'Año', vacio: 'No hay ratificaciones docentes con estos filtros.' },
  { key: 'desplazamientos', label: 'Desplazamientos', anio: 'Año', vacio: 'No hay desplazamientos con estos filtros.' },
]

export function findTipo(key) {
  return tiposSeccion07.find(tipo => tipo.key === key) || tiposSeccion07[0]
}

export const porPagina = [10, 20, 50]
