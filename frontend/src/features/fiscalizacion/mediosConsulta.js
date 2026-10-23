export const canalesOficio = { mesa_partes_fisica: 'Mesa de partes física', mesa_partes_virtual: 'Mesa de partes virtual', correo: 'Correo institucional', otro: 'Otro canal' }
export const mediosConsulta = {
  correo: {
    nombre: 'Correo institucional', referencia: 'Identificador del mensaje (opcional)', fecha: 'Fecha de envío del correo', evidencia: 'PDF del correo enviado',
    ayuda: 'Selecciona el PDF del mensaje enviado, con remitente, destinatario, asunto, fecha y contenido visibles. Registrar esta actuación no envía el correo.',
    campos: { remitente: { label: 'Correo del remitente', type: 'email', max: 254 }, destinatario: { label: 'Correo del destinatario', type: 'email', max: 254 }, asunto: { label: 'Asunto del correo', max: 250, amplio: true } },
  },
  oficio: {
    nombre: 'Oficio', referencia: 'Número de oficio', fecha: 'Fecha de envío del oficio', evidencia: 'Oficio enviado y constancia de envío o cargo',
    ayuda: 'Selecciona el PDF del oficio y su constancia de envío. El número del oficio y la fecha de emisión por sí solos no prueban que se haya enviado.',
    campos: { destinatario: { label: 'Nombre o área destinataria', max: 200 }, cargo_destinatario: { label: 'Cargo del destinatario (opcional)', max: 150, opcional: true }, canal_envio: { label: 'Canal de envío', opciones: canalesOficio }, expediente: { label: 'Número de expediente o cargo (opcional)', max: 80, opcional: true } },
  },
  registro_oficial: {
    nombre: 'Registro oficial', referencia: 'Código o referencia de consulta (opcional)', fecha: 'Fecha de consulta al registro', evidencia: 'Reporte o captura de la consulta en PDF',
    ayuda: 'Selecciona el reporte o captura con la fuente, criterio, fecha y resultado de la consulta. El resultado se registra como evidencia y no aprueba automáticamente el documento.',
    campos: { nombre_registro: { label: 'Nombre del registro o portal', max: 200 }, url_registro: { label: 'Dirección web del registro', type: 'url', max: 1000 }, criterio_busqueda: { label: 'Criterio utilizado en la búsqueda', max: 250, amplio: true }, resultado_consulta: { label: 'Resultado observado en el registro', max: 2000, textarea: true, amplio: true } },
  },
}

export function prepararConsulta(datos) {
  const { medio, ...resto } = datos
  const campos = mediosConsulta[medio]?.campos || {}
  const extras = new Set(Object.values(mediosConsulta).flatMap(config => Object.keys(config.campos)))
  return { ...Object.fromEntries(Object.entries(resto).filter(([key]) => !extras.has(key))), medio,
    datos_medio: Object.fromEntries(Object.keys(campos).filter(key => datos[key]?.trim()).map(key => [key, datos[key].trim()])) }
}

export function detalleMedio(consulta) {
  const config = mediosConsulta[consulta.medio]
  return Object.entries(config?.campos || {}).filter(([key]) => consulta.datos_medio?.[key]).map(([key, campo]) => ({ label: campo.label.replace(' (opcional)', ''), valor: campo.opciones?.[consulta.datos_medio[key]] || consulta.datos_medio[key] }))
}

export function cambiarMedioConsulta(datos, medio) {
  if (datos.medio === medio) return datos
  const vacios = Object.fromEntries(Object.values(mediosConsulta).flatMap(config => Object.keys(config.campos)).map(key => [key, '']))
  return { ...datos, ...vacios, medio }
}
