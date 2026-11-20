import { legajoSections } from '../legajos/config/legajoSections.js'

// Interpreta /solicitudes-legajo/opciones?usuario_id=…: el backend decide con AplicacionDocumental (las mismas reglas
// del legajo) y marca cada tipo con no_aplica o por_definir. Aquí no se evalúa ninguna regla.

export function clasificarTipos(tipos = []) {
  return { seleccionables: tipos.filter(t => !t.no_aplica), noAplican: tipos.filter(t => t.no_aplica) }
}

// Al cambiar de persona, quita de la selección los tipos que ya no se pueden pedir (o que esa persona no tiene).
export function depurarSeleccion(seleccion, tipos = []) {
  const validos = new Set(clasificarTipos(tipos).seleccionables.map(t => t.id))
  return { seleccion: seleccion.filter(id => validos.has(id)), quitados: seleccion.filter(id => !validos.has(id)) }
}

// Lista agrupada por sección del legajo (01 a 11), en su orden, con los tipos por nombre dentro de cada una.
const titulos = Object.fromEntries(legajoSections.map(s => [Number(s.number), `${s.number}. ${s.title}`]))

export function agruparPorSeccion(tipos = []) {
  const grupos = new Map()
  for (const tipo of [...tipos].sort((a, b) => (a.seccion ?? 99) - (b.seccion ?? 99) || a.nombre.localeCompare(b.nombre, 'es'))) {
    const clave = tipo.seccion ?? 0
    if (!grupos.has(clave)) grupos.set(clave, { seccion: clave, titulo: titulos[clave] || 'Otros documentos', tipos: [] })
    grupos.get(clave).tipos.push(tipo)
  }
  return [...grupos.values()]
}
