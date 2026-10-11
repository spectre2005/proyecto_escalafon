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
