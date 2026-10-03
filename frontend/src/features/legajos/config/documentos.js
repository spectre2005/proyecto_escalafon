// Enums del esquema (documentos_usuario). Los valores los valida el backend (DocumentoLegajoController).
export const modalidadesPresentacion = { original: 'Original', copia_simple: 'Copia simple', copia_fedateada: 'Copia fedateada', digital_firmado: 'Digital con firma' }

export const relacionesTitular = { servidor: 'El propio servidor', conyuge_concubino: 'Cónyuge o concubino(a)', hijo: 'Hijo(a)', otro: 'Otro' }

export const folios = (inicio, fin) => inicio ? (fin && fin !== inicio ? `${inicio}–${fin}` : String(inicio)) : 'sin foliar'
