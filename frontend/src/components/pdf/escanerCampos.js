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

const normalizarValor = valor => String(valor ?? '').trim().replace(/\s+/g, ' ').toLocaleUpperCase('es-PE')

/**
 * Qué pasó con cada campo pedido a la IA, al enviar el formulario. Solo resultados, nunca valores: es lo que se guarda
 * para medir el acierto. Los campos que siguen vacíos y la IA no propuso no se informan.
 * - aceptado / corregido / borrado: la IA lo completó y quedó igual, se cambió o se vació.
 * - sugerencia_usada: la IA lo dejó como «posible dato» y es lo que se guardó.
 * - no_detectado: la IA no lo completó, pero la persona sí lo llenó.
 */
export function resultadoLectura({ pedidos = [], propuestos = {}, sugeridos = {}, finales = {} }) {
  return Object.fromEntries(pedidos.flatMap(campo => {
    const final = normalizarValor(finales[campo])
    if (campo in propuestos) return [[campo, !final ? 'borrado' : final === normalizarValor(propuestos[campo]) ? 'aceptado' : 'corregido']]
    if (final && campo in sugeridos && final === normalizarValor(sugeridos[campo])) return [[campo, 'sugerencia_usada']]
    return final ? [[campo, 'no_detectado']] : []
  }))
}

// Aviso sobre el titular del documento (TitularDocumento en el backend): tono según la gravedad.
export const TONO_TITULAR = { distinto: 'error', dni_distinto: 'aviso', sin_datos: 'aviso', coincide: 'ok', coincide_nombre: 'ok' }

// Estados de una lectura que todavía corre en el servidor (LecturaIa::EN_CURSO): la pantalla sigue consultando.
export const LECTURA_EN_CURSO = ['en_espera', 'leyendo', 'interpretando']

/** Texto del avance de una lectura en curso, para mostrar junto al botón. */
export function textoAvance(lectura) {
  if (lectura?.estado === 'en_espera') return 'En espera: hay otra lectura en curso…'
  if (lectura?.estado === 'interpretando') return 'La IA está interpretando el texto…'
  if (lectura?.estado === 'leyendo' && lectura.pagina_actual && lectura.paginas_leer) return `Leyendo página ${lectura.pagina_actual} de ${lectura.paginas_leer}…`
  return 'Leyendo el PDF…'
}
