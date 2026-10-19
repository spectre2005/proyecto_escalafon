const values = doc => doc.values || doc
const vigente = doc => !doc.subsanado && !values(doc).anulado && !['anulado', 'falso'].includes(values(doc).estado)

export function resumenTipoDocumento(tipo) {
  if (tipo.restringido) return { cantidad: null, fecha: null, folios: null, estado: 'restringido' }
  const lista = (tipo.lista || (Array.isArray(tipo.documentos) ? tipo.documentos : [])).filter(vigente)
  const cantidad = Number(tipo.cantidad ?? (typeof tipo.documentos === 'number' ? tipo.documentos : lista.length)) || 0
  const fechas = lista.map(doc => values(doc).fecha_emision || values(doc).fecha_incorporacion).filter(Boolean).map(value => String(value).slice(0, 10)).sort()
  const rangos = lista.map(doc => [Number(values(doc).folio_inicio), Number(values(doc).folio_fin)])
  let folios = cantidad === 0 ? 0 : null
  if (lista.length && lista.length === cantidad && rangos.every(([inicio, fin]) => Number.isInteger(inicio) && Number.isInteger(fin) && inicio > 0 && fin >= inicio)) {
    const ordenados = rangos.sort(([a], [b]) => a - b)
    let ultimo = 0
    folios = 0
    for (const [inicio, fin] of ordenados) {
      folios += Math.max(0, fin - Math.max(inicio, ultimo + 1) + 1)
      ultimo = Math.max(ultimo, fin)
    }
  }
  const estados = lista.map(doc => doc.estado_visible || values(doc).estado)
  const estado = tipo.estado || (tipo.aplica === false ? 'no_aplica' : estados.includes('observado') ? 'observado' : estados.some(estado => ['pendiente', 'cargado', 'en_revision', 'en_fiscalizacion'].includes(estado)) ? 'pendiente' : estados.length && estados.every(estado => ['aceptado', 'verificado'].includes(estado)) ? 'aceptado' : cantidad ? 'cargado' : tipo.exigido === null ? 'por_determinar' : tipo.exigido ? 'falta' : 'opcional')
  return { cantidad, fecha: tipo.ultima_fecha || fechas.at(-1) || null, folios: tipo.numero_folios ?? folios, estado }
}
