// Opciones de documentos_usuario; el backend valida los códigos de las nuevas cargas.
export const modalidadesPresentacion = { original: 'Original', copia_simple: 'Copia simple', copia_fedateada: 'Copia fedateada', digital_firmado: 'Digital con firma' }

export const relacionesTitular = { servidor: 'El propio servidor', conyuge: 'Cónyuge', conviviente: 'Conviviente', hijo: 'Hijo / Hija', padre_madre: 'Padre / Madre', hermano_hermana: 'Hermano / Hermana', otro: 'Otro' }

export function relacionTitularTexto(valores) {
  if (valores.relacion_titular === 'conyuge_concubino') return 'Cónyuge o concubino(a) (valor anterior)'
  const etiqueta = relacionesTitular[valores.relacion_titular] || valores.relacion_titular || 'No registrada'
  return valores.relacion_titular === 'otro' && valores.relacion_titular_otro ? `${etiqueta}: ${valores.relacion_titular_otro}` : etiqueta
}

export const folios = (inicio, fin) => inicio ? (fin && fin !== inicio ? `${inicio}–${fin}` : String(inicio)) : 'sin foliar'
