// Piezas puras de la organización común de las secciones (components/documentos/EspacioSeccion.jsx).
import { puedeEnlazar } from './acreditacion.js'
import { folios } from './documentos.js'
import { legajoSections } from './legajoSections.js'

export const idBloque = { registros: 'bloque-registros', documentos: 'bloque-documentos', sinAsociar: 'bloque-sin-asociar' }
export const idTipo = id => `tipo-${id}`

/** «07 · Evaluación de desempeño…»: número y nombre de la sección, del mismo catálogo que el menú. */
export function tituloSeccion(numero) {
  const seccion = legajoSections.find(item => item.number === numero)
  return seccion ? `${numero} · ${seccion.title}` : `Sección ${numero}`
}

// Lleva la vista a un bloque o a un tipo documental (y abre el <details> que lo contenga, si estaba plegado).
export function irA(id) {
  const fila = document.getElementById(`tabla-${id}`)
  const abrir = fila?.querySelector('[data-abrir-detalle]')
  if (abrir?.getAttribute('aria-expanded') === 'false') abrir.click()
  const destino = fila || document.getElementById(id)
  destino?.closest('details')?.setAttribute('open', '')
  destino?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

/** Documento (vigente o el último) con ese id en la lista de tipos: { tipo, documento } o null. */
export function buscarDocumento(tipos, id) {
  if (!id) return null
  for (const tipo of tipos || []) {
    const documento = (tipo.lista || []).find(item => item.id === id)
    if (documento) return { tipo, documento }
  }
  return null
}

/** Documentos cargados que no acreditan ningún registro todavía (de los tipos de la página). */
export function documentosSinAsociar(tipos) {
  return (tipos || []).flatMap(tipo => (tipo.lista || []).filter(puedeEnlazar).map(documento => ({ tipo, documento })))
}

/** Atajos de la página: registros, documentos de la subsección y, si hay, sin registro asociado (sin registros o sin tipos en
 * la página, ese atajo no aparece). */
export function atajosDe({ registros, tipos }) {
  const pendientes = (tipos || []).filter(tipo => !(tipo.lista || []).length && tipo.exigido === true).length
  const sinAsociar = documentosSinAsociar(tipos).length
  return [
    ...(registros === undefined ? [] : [{ id: idBloque.registros, texto: `Registros (${registros})` }]),
    ...(tipos === undefined ? [] : [{ id: idBloque.documentos, texto: `Documentos (${tipos.length} ${tipos.length === 1 ? 'tipo' : 'tipos'}${pendientes ? `, ${pendientes} exigido(s) sin documento` : ''})` }]),
    ...(sinAsociar ? [{ id: idBloque.sinAsociar, texto: `Sin registro asociado (${sinAsociar})`, alerta: true }] : []),
  ]
}

/** Título del paso en la zona de trabajo: «Enlazar: b) Resolución de rotación (folios 3–4)». */
export function tituloPaso(accion, tipo, documento = null) {
  const nombre = `${tipo.literal ? `${tipo.literal}) ` : ''}${tipo.nombre}`
  return `${accion}: ${nombre}${documento ? ` (folios ${folios(documento.values.folio_inicio, documento.values.folio_fin)})` : ''}`
}

/**
 * ¿Se muestra la etiqueta de estado del tipo? Con documentos, o si se exige (ahí «Pendiente» importa). En un tipo opcional
 * sin documentos «Pendiente» repetiría «No se exige» y no dice nada.
 */
export function mostrarEstado(tipo) {
  return (tipo.documentos ?? (tipo.lista || []).length) > 0 || tipo.exigido === true
}
