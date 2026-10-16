// Vistas documentales de una sección (components/documentos/VistasDocumentales.jsx): cada tipo de documento en una sola
// vista según lo que pide al usuario. No cambia qué se exige ni qué corresponde: usa lo que ya informa el backend
// (exigido, aplicabilidad, documentos y su estado).

export const VISTAS = [
  { clave: 'pendientes', titulo: 'Pendientes', ayuda: 'Obligatorios sin cargar, observados o por determinar.', vacio: 'No hay documentos obligatorios pendientes.' },
  { clave: 'conDocumentos', titulo: 'Con documentos', ayuda: 'Tipos que ya tienen archivos.', vacio: 'Todavía no hay documentos cargados.' },
  { clave: 'opcionales', titulo: 'Opcionales', ayuda: 'Se incorporan solo si existen: no son tareas pendientes.', vacio: 'No hay documentos opcionales sin cargar.' },
  { clave: 'noCorresponde', titulo: 'No corresponde', ayuda: 'No corresponden a la persona; lo cargado antes se conserva y se puede consultar.', vacio: 'Todos los tipos corresponden a la persona.' },
]

/**
 * Vista de un tipo documental:
 * - noCorresponde: no aplica a la persona (aunque tenga documentos anteriores, que se siguen viendo y subsanando).
 * - pendientes: un documento observado (hay que subsanarlo), o sin documentos y exigido (true) o por determinar (null).
 * - conDocumentos: tiene documentos y ninguno observado.
 * - opcionales: sin documentos y no exigido (false o sin dato de exigencia).
 *
 * @param {{ aplica?: boolean, exigido?: boolean|null, cantidad?: number, observado?: boolean }} estado
 */
export function vistaDe({ aplica = true, exigido, cantidad = 0, observado = false }) {
  if (!aplica) return 'noCorresponde'
  if (observado) return 'pendientes'
  if (cantidad > 0) return 'conDocumentos'
  return exigido === true || exigido === null ? 'pendientes' : 'opcionales'
}

/** Elementos repartidos en las cuatro vistas, en su orden original. */
export function repartir(elementos, clasificar) {
  const grupos = Object.fromEntries(VISTAS.map(vista => [vista.clave, []]))
  for (const elemento of elementos || []) grupos[clasificar(elemento)].push(elemento)
  return grupos
}

/** Vista con la que se abre la sección: la primera con algo que hacer o ver (pendientes, luego con documentos…). */
export function vistaInicial(grupos) {
  return ['pendientes', 'conDocumentos', 'opcionales', 'noCorresponde'].find(clave => grupos[clave]?.length) || 'pendientes'
}

/**
 * Cambios de vista entre dos repartos (p. ej. al cargar un documento): [{ id, de, a }]. Los que aparecen o desaparecen
 * no cuentan (cambio de catálogo o de permisos, no una carga).
 */
export function cambiosDeVista(antes, ahora) {
  if (!antes) return []
  return Object.entries(ahora).filter(([id, vista]) => antes[id] && antes[id] !== vista).map(([id, vista]) => ({ id, de: antes[id], a: vista }))
}

/**
 * Vista de un ítem de las secciones con formulario propio (03, 04, 06, 08 y 10), con lo que informa su backend:
 * estado (falta, observado, por_determinar, no_aplica…), exigido y documentos (o cantidad). ofrecido: el tipo
 * corresponde a la persona (ofrecerTipo). Los anulados no cuentan como documento cargado.
 */
export function vistaDeItem(item, ofrecido = true) {
  const documentos = Array.isArray(item.documentos) ? item.documentos.filter(documento => !documento.anulado && documento.estado !== 'anulado').length : Number(item.cantidad) || 0
  return vistaDe({
    aplica: ofrecido && item.estado !== 'no_aplica' && item.aplica !== false,
    exigido: item.exigido === undefined ? false : item.exigido,
    cantidad: documentos,
    observado: item.estado === 'observado',
  })
}

/**
 * Elementos que se muestran en la vista elegida: los suyos y los «retenidos», que salieron de ella mientras se miraba
 * (p. ej. al cargar su documento). Siguen a la vista, marcados, hasta cambiar de vista: así el paso en curso (completar
 * datos, enlazar) no desaparece y se ve qué cambió.
 */
export function visiblesEnVista(elementos, clasificar, vista, retenidos = [], idDe = elemento => elemento.id) {
  return (elementos || []).filter(elemento => clasificar(elemento) === vista || retenidos.includes(String(idDe(elemento))))
}
