// Adónde lleva cada motivo de Vencimientos: la sección del legajo (?categoria=) y el registro (#registro-{id} o el bloque del
// DNI), que DestinoLegajo despliega y resalta. Los requerimientos van a su página, filtrados por la persona.
const SECCIONES = {
  contrato: ['s02-vinculo', 'Ir al contrato'],
  periodo_prueba: ['s02-prueba', 'Ir al período de prueba'],
  discapacidad: ['s01-discapacidad', 'Ir al certificado'],
  colegiatura: ['seccion-03', 'Ir a la colegiatura'],
  examen_medico: ['s10-sst', 'Ir al examen'],
  ratificacion: ['s07-progresion', 'Ir a la ratificación'],
  desplazamiento: ['s07-desplazamiento', 'Ir al encargo o destaque'],
}

/** { ruta, texto } del enlace de un motivo, o null si su origen no es conocido. */
export function destinoMotivo(persona, motivo) {
  if (motivo.origen === 'requerimiento') return { ruta: `/requerimientos?clase=todas&q=${encodeURIComponent(persona.numero_doc_identidad || persona.nombre.split(' ')[0])}`, texto: 'Ver requerimientos' }
  // Solo el DNI pide fecha de caducidad (DocumentoLegajoController): va a su bloque en Información personal.
  if (motivo.origen === 'documento') return { ruta: `/legajos/${persona.usuario_id}?categoria=general#bloque-S01_B_DOCUMENTO_IDENTIDAD`, texto: 'Ir al DNI' }
  const seccion = SECCIONES[motivo.origen]
  return seccion && motivo.id ? { ruta: `/legajos/${persona.usuario_id}?categoria=${seccion[0]}#registro-${motivo.id}`, texto: seccion[1] } : null
}
