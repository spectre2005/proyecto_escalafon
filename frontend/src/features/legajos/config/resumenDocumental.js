import { cantidadDocumentos } from './aplicacion.js'

export function resumenDocumental(tipo) {
  const documentos = (tipo.lista || (Array.isArray(tipo.documentos) ? tipo.documentos : []))
    .filter(doc => !doc.subsanado && !doc.anulado && (doc.values?.estado || doc.estado) !== 'anulado')
  const fechas = documentos.map(doc => doc.values?.fecha_emision || doc.fecha_emision).filter(Boolean).sort()
  const numeroFolios = documentos.reduce((total, doc) => {
    const datos = doc.values || doc
    const inicio = Number(datos.folio_inicio), fin = Number(datos.folio_fin)
    return total + (inicio > 0 && fin >= inicio ? fin - inicio + 1 : 0)
  }, 0)
  return {
    cantidad: cantidadDocumentos(tipo),
    ultimaFecha: tipo.ultima_fecha ?? fechas.at(-1) ?? null,
    numeroFolios: tipo.numero_folios ?? numeroFolios,
  }
}
