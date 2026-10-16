// Enums del esquema (documentos_usuario). Los valores los valida el backend (DocumentoLegajoController).
export const modalidadesPresentacion = { original: 'Original', copia_simple: 'Copia simple', copia_fedateada: 'Copia fedateada', digital_firmado: 'Digital con firma' }

export const relacionesTitular = { servidor: 'El propio servidor', conyuge_concubino: 'Cónyuge o concubino(a)', hijo: 'Hijo(a)', otro: 'Otro' }

export const folios = (inicio, fin) => inicio ? (fin && fin !== inicio ? `${inicio}–${fin}` : String(inicio)) : 'sin foliar'

// Folio final si cada página del PDF ocupa un folio. Solo es una sugerencia: la regla de foliado está pendiente de
// confirmar con la universidad (P3) y el usuario escribe o acepta el folio final. Sin datos válidos, null.
export function folioFinalPorPaginas(inicio, paginas) {
  const desde = Number(inicio), hojas = Number(paginas)
  return Number.isInteger(desde) && desde > 0 && Number.isInteger(hojas) && hojas > 0 ? desde + hojas - 1 : null
}

// Última modalidad de presentación usada por quien carga (comodidad de este navegador; no es un dato del legajo). El
// almacenamiento puede no estar disponible: entonces no se propone nada.
const CLAVE_MODALIDAD = 'documentos.modalidad'

export function modalidadRecordada(almacen = globalThis.localStorage) {
  try {
    const valor = almacen?.getItem(CLAVE_MODALIDAD)
    return valor && valor in modalidadesPresentacion ? valor : ''
  } catch {
    return ''
  }
}

export function recordarModalidad(valor, almacen = globalThis.localStorage) {
  if (!(valor in modalidadesPresentacion)) return
  try {
    almacen?.setItem(CLAVE_MODALIDAD, valor)
  } catch {
    // Sin almacenamiento la próxima carga empieza sin modalidad elegida.
  }
}

const DNI = 'S01_B_DOCUMENTO_IDENTIDAD'

// Texto del botón de carga en la fila de un tipo, o null si no corresponde: con un documento vigente en un tipo de un
// solo documento, se corrige con «Subsanar» en ese documento (ListaDocumentos), no con otro botón en la fila. La copia
// de DNI no se subsana: se actualiza (el PDF nuevo queda vigente y el anterior en el historial).
export function textoSubirTipo(tipo) {
  const conVigente = (tipo.lista || []).some(documento => !documento.subsanado)
  if (!conVigente || tipo.permite_multiples) return 'Subir documento'
  return tipo.codigo === DNI ? 'Actualizar copia de DNI' : null
}
