// Tipos de documento que no corresponden a la persona (docente o administrativo, régimen), según
// GET /expedientes/{usuario}/documentos/no-aplicables: [{ id, codigo, motivo }]. Sin respuesta se ofrece todo; el backend
// rechaza igualmente la carga de un tipo que no corresponde.

// Índice por id y por código: cada panel identifica sus tipos por uno u otro.
export function indiceNoAplica(lista) {
  const indice = new Map()
  for (const tipo of lista || []) {
    indice.set(tipo.id, tipo.motivo)
    indice.set(tipo.codigo, tipo.motivo)
  }
  return indice
}

export function motivoNoAplica(indice, tipo) {
  return indice?.get(tipo.id) ?? indice?.get(tipo.codigo) ?? null
}

// Solo se ofrece cargar lo que corresponde.
export function ofrecerTipo(indice, tipo) {
  return !motivoNoAplica(indice, tipo)
}

// Un tipo que no corresponde se oculta, salvo que ya tenga documentos (lo registrado no se esconde).
export function mostrarTipo(indice, tipo) {
  return ofrecerTipo(indice, tipo) || (tipo.documentos ?? tipo.cantidad ?? 0) > 0
}

// Documentos cargados de un tipo (los paneles los informan como documentos, cantidad o lista).
export function cantidadDocumentos(tipo) {
  const cantidad = typeof tipo.documentos === 'number' ? tipo.documentos : tipo.cantidad ?? tipo.lista?.length ?? (Array.isArray(tipo.documentos) ? tipo.documentos.length : 0)
  return Number(cantidad) || 0
}

/**
 * Tipos de una lista agrupados para reducir desplazamiento, con lo que el sistema ya sabe: Pendientes (se ofrecen y aún
 * no tienen documentos), Con documentos (se ofrecen y tienen alguno) y No corresponde (ya no se ofrecen, pero tienen
 * documentos que se siguen viendo y se pueden subsanar). Un tipo que no corresponde y no tiene documentos no se muestra,
 * igual que antes (mostrarTipo). No cambia qué se exige: solo el orden en pantalla.
 */
export function agruparTipos(tipos, indice) {
  const grupos = { pendientes: [], conDocumentos: [], noCorresponde: [] }
  for (const tipo of tipos || []) {
    if (!mostrarTipo(indice, tipo)) continue
    if (!ofrecerTipo(indice, tipo)) grupos.noCorresponde.push(tipo)
    else grupos[cantidadDocumentos(tipo) > 0 ? 'conDocumentos' : 'pendientes'].push(tipo)
  }
  return grupos
}

/**
 * Tipos que pueden acreditar un registro concreto: se ofrecen a la persona y el registro está entre sus enlaces posibles
 * (enlacesDe del panel, con sus reglas de compatibilidad). Sin ninguno, el registro no muestra «Adjuntar documento».
 */
export function tiposQueAcreditan(tipos, indice, enlacesDe, registroId) {
  return (tipos || []).filter(tipo => ofrecerTipo(indice, tipo) && (enlacesDe(tipo) || []).some(enlace => enlace.id === registroId))
}
