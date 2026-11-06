export const camposEscaner = ['nombre', 'emisor', 'fecha_emision', 'num_resolucion', 'numero_documento', 'fecha_caducidad', 'fecha_inicio', 'fecha_fin', 'fecha_vencimiento', 'vigente_hasta', 'numero_registro', 'numero_colegiatura', 'horas', 'cargo', 'funcion_asignada', 'anios', 'meses', 'dias', 'concepto', 'fecha', 'entidad', 'motivo']

const MOTIVOS = {
  cita_no_encontrada: 'la cita no aparece en el documento',
  fecha_no_valida: 'la fecha no es válida',
  fecha_no_coincide: 'la fecha no coincide con la cita',
  fecha_futura: 'la fecha de emisión es futura',
  cantidad_no_coincide: 'la cantidad no coincide con la cita',
  valor_fuera_de_cita: 'el dato no está en la cita',
  numero_sin_digitos: 'el número no tiene dígitos',
  es_el_titular: 'es el nombre de la persona',
  es_el_titulo: 'es el título del documento',
  es_el_firmante: 'es quien firma el documento',
  no_es_el_emisor: 'el título indica otro emisor',
  demasiado_largo: 'el texto es demasiado largo',
}

export function etiquetaCampo(campo) {
  return campo.replaceAll('_', ' ')
}

// Las fechas llegan como AAAA-MM-DD: para decidir, se muestran como se leen en un documento.
export function valorLegible(valor) {
  const fecha = /^(\d{4})-(\d{2})-(\d{2})$/.exec(valor)
  return fecha ? `${fecha[3]}/${fecha[2]}/${fecha[1]}` : valor
}

// Solo se ofrece lo que sigue vacío: una sugerencia sin comprobar nunca reemplaza lo que ya se escribió.
export function sugerenciasAplicables(sugerencias, actual, permitidos) {
  return Object.fromEntries(Object.entries(sugerencias || {}).filter(([campo, dato]) =>
    camposEscaner.includes(campo) && permitidos.includes(campo) && !actual[campo] && typeof dato?.valor === 'string' && dato.valor.trim(),
  ))
}

export function motivosDescarte(descartados, permitidos, sugeridos = {}) {
  return Object.entries(descartados || {})
    .filter(([campo]) => permitidos.includes(campo) && !(campo in sugeridos))
    .map(([campo, motivo]) => ({ campo, motivo: MOTIVOS[motivo] || 'no se pudo comprobar' }))
}

export function datosAplicables(campos, inicial, actual, permitidos) {
  return Object.fromEntries(Object.entries(campos || {}).filter(([key, dato]) =>
    camposEscaner.includes(key) && permitidos.includes(key) && inicial[key] === actual[key]
    && typeof dato?.valor === 'string' && dato.valor.trim() && typeof dato.evidencia === 'string',
  ).map(([key, dato]) => [key, dato.valor]))
}
