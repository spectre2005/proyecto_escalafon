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
  demasiado_largo: 'el texto es demasiado largo',
}

export function motivosDescarte(descartados, permitidos) {
  return Object.entries(descartados || {})
    .filter(([campo]) => permitidos.includes(campo))
    .map(([campo, motivo]) => ({ campo, motivo: MOTIVOS[motivo] || 'no se pudo comprobar' }))
}

export function datosAplicables(campos, inicial, actual, permitidos) {
  return Object.fromEntries(Object.entries(campos || {}).filter(([key, dato]) =>
    camposEscaner.includes(key) && permitidos.includes(key) && inicial[key] === actual[key]
    && typeof dato?.valor === 'string' && dato.valor.trim() && typeof dato.evidencia === 'string',
  ).map(([key, dato]) => [key, dato.valor]))
}
