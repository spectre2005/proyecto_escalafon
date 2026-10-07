import { legajoSections, subseccionesSeccion10 } from './legajoSections.js'
import { gruposSeccion01 } from './seccion01.js'
import { subseccionesSeccion02 } from './seccion02.js'
import { subseccionesSeccion07 } from './seccion07.js'
import { categoriasSeccion05 } from './seccion05.js'
import { categoriasSeccion09 } from './seccion09.js'

export const estadosRecorrido = {
  completa: 'Completa', pendiente: 'Pendiente', omitida: 'Omitida por ahora', no_aplica: 'No aplica',
  restringida: 'Acceso restringido', sin_configurar: 'Catálogo pendiente',
}

// Resuelve destinos con la configuración de las pantallas actuales; la secuencia viene del servidor.
export function categoriaRecorrido(seccion, item) {
  const grupo = legajoSections.find(grupo => Number(grupo.number) === seccion.id)
  if (!grupo) return null
  const subsecciones = { ...subseccionesSeccion02, ...subseccionesSeccion07, ...subseccionesSeccion10 }
  const destinoSubseccion = Object.entries(subsecciones).find(([, datos]) => datos.codigo === item?.subseccion)?.[0]
  if (destinoSubseccion) return destinoSubseccion
  if (seccion.id === 1 && item) return Object.entries(gruposSeccion01).find(([, datos]) => datos.codigos.includes(item.codigo))?.[0] || grupo.categories[0]
  if (seccion.id === 5 && item) return Object.entries(categoriasSeccion05).find(([, datos]) => datos.bloque === item.bloque)?.[0] || grupo.categories[0]
  if (seccion.id === 9 && item) return Object.entries(categoriasSeccion09).find(([, datos]) => datos.bloque === item.bloque)?.[0] || grupo.categories[0]
  if (seccion.id === 8 && item?.codigo.startsWith('SAN_')) return grupo.categories.find(categoria => categoria === 'sanciones')
  if (seccion.id === 11 && item) return grupo.categories.find(categoria => categoria === 's11-documentos')
  return grupo.categories[0]
}

export function esCargaDocumento(config, usuario) {
  return config?.method?.toLowerCase() === 'post'
    && new RegExp(`^/expedientes/${usuario}/(?:seccion-\\d{2}/)?documentos/?$`).test(config.url || '')
}

export function puedeAvanzarAutomaticamente(seccion, hayFormulario) {
  return !!seccion?.puede_continuar && !seccion.solo_opcionales && !hayFormulario
}
