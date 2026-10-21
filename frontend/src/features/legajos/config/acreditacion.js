// Precarga del formulario común de carga (SubirDocumentoForm) desde el registro que acredita el documento. Solo propone
// valores que representan el mismo dato del mismo documento; siempre quedan editables y el backend valida igual. Lo que
// describe el archivo (folios, fechas de incorporación, emisor, modalidad) no sale del registro.

// Parentesco del familiar → relación con el titular del documento (relaciones de DocumentoLegajoController).
const relacionPorParentesco = { conyuge: 'conyuge_concubino', concubino: 'conyuge_concubino', hijo: 'hijo', otro: 'otro' }

// Renovación (S02_D): su resolución puede tener otro número que la del vínculo; no se propone.
const SIN_NUMERO_DEL_VINCULO = ['S02_D_RESOLUCION_RENOVACION']

const hoy = () => new Date().toISOString().slice(0, 10)
const texto = valor => (valor === null || valor === undefined ? '' : String(valor).trim())
// Una fecha futura no vale como fecha de emisión (el backend exige before_or_equal:today): no se propone.
const fechaEmision = valor => (texto(valor) && texto(valor) <= hoy() ? texto(valor) : '')

/** Titular de un documento de un familiar: su nombre, su documento y la relación según su parentesco. */
export function titularFamiliar(familiar) {
  if (!familiar) return {}
  return {
    titular_nombre: texto(familiar.apellidos_nombres), titular_documento: texto(familiar.numero_doc_identidad),
    relacion_titular: relacionPorParentesco[familiar.parentesco] || 'otro',
  }
}

/**
 * Valores que el registro aporta al documento que lo acredita (solo los que tiene). tipo: { codigo, formulario_datos,
 * principal }. registro: valores del registro (record.values). familiares: para los certificados de un familiar.
 */
export function precargaDesdeRegistro(tipo, registro, { familiares = [] } = {}) {
  const v = registro || {}
  const numero = valor => (texto(valor) ? { num_resolucion: texto(valor) } : {})
  const fecha = valor => (fechaEmision(valor) ? { fecha_emision: fechaEmision(valor) } : {})
  switch (tipo?.formulario_datos) {
    case 'vinculos_laborales': {
      if (SIN_NUMERO_DEL_VINCULO.includes(tipo.codigo)) return {}
      if (tipo.codigo === 'S02_F_CONTRATO') return { ...numero(v.numero_contrato), ...fecha(v.fecha_contrato) }
      if (['S02_C_RESOLUCION_DESIGNACION', 'S02_E_RESOLUCION_CARRERA', 'S02_RESOLUCION_DOCENTE'].includes(tipo.codigo)) return { ...numero(v.numero_resolucion), ...fecha(v.fecha_resolucion) }
      return {}
    }
    case 'vinculo_adendas': return numero(v.numero)
    case 'progresiones_carrera': return numero(v.numero_resolucion)
    case 'ratificaciones_docentes': return { ...numero(v.numero_resolucion), ...fecha(v.fecha_resolucion) }
    // Solo los del término acreditan la desvinculación con su resolución; los complementarios son otros documentos.
    case 'desvinculaciones': return tipo.principal ? numero(v.numero_resolucion) : {}
    case 'licencias_permisos':
    case 'goces_vacacionales':
    case 'desplazamientos': return numero(v.numero_documento)
    case 'declaraciones_verificaciones': return fecha(v.fecha)
    case 'familiares': return titularFamiliar(v)
    case 'certificados_discapacidad': return {
      ...fecha(v.fecha_emision),
      ...(v.persona === 'familiar' ? titularFamiliar(familiares.find(f => f.id === v.familiar_id)?.values) : {}),
    }
    default: return {}
  }
}

/**
 * Aplica una precarga nueva sin pisar lo que escribió el usuario: un campo se actualiza si está vacío o si conserva el
 * valor que propuso la precarga anterior. anterior y nueva ya incluyen los valores de partida (p. ej. el titular del
 * expediente), así al quitar el registro elegido se vuelve a ellos. Devuelve el borrador nuevo.
 */
export function aplicarPrecarga(borrador, anterior, nueva, campos) {
  const resultado = { ...borrador }
  for (const campo of campos) {
    const actual = texto(borrador[campo])
    if (!actual || actual === texto(anterior[campo])) resultado[campo] = nueva[campo] ?? ''
  }
  return resultado
}

export const CAMPOS_PRECARGA = ['num_resolucion', 'fecha_emision', 'titular_nombre', 'titular_documento', 'relacion_titular']

/**
 * Subsanación: los metadatos del documento que se corrige, para cambiar solo lo necesario. Los folios, la fecha de
 * incorporación y el PDF son del documento nuevo y no se copian.
 */
export function precargaDesdeDocumento(valores) {
  const v = valores || {}
  const campos = ['emisor', 'num_resolucion', 'titular_nombre', 'titular_documento', 'relacion_titular', 'modalidad_presentacion']
  return {
    ...Object.fromEntries(campos.filter(campo => texto(v[campo])).map(campo => [campo, texto(v[campo])])),
    ...(fechaEmision(v.fecha_emision) ? { fecha_emision: fechaEmision(v.fecha_emision) } : {}),
  }
}

/**
 * Formularios combinados (03, 04, 06 y 08): al elegir el documento que se subsana se proponen los datos de su registro.
 * Igual que aplicarPrecarga, pero para cualquier tipo de valor (casillas, números): un campo se reemplaza solo si sigue
 * vacío (su valor inicial) o con lo que propuso el documento anterior; lo que el usuario cambió se respeta.
 */
export function reemplazarPropuesta(borrador, anterior, nueva, vacios) {
  const resultado = { ...borrador }
  for (const campo of new Set([...Object.keys(anterior), ...Object.keys(nueva)])) {
    const actual = borrador[campo]
    if (actual === vacios[campo] || actual === anterior[campo]) resultado[campo] = campo in nueva ? nueva[campo] : vacios[campo]
  }
  return resultado
}

/** Solo los valores con contenido (null o vacío no se proponen). */
export function conValor(datos) {
  return Object.fromEntries(Object.entries(datos).filter(([, valor]) => valor !== null && valor !== undefined && valor !== ''))
}

/** Documento cargado que quedó sin el registro que debería acreditar (sin_registro del backend) y aún se puede enlazar. */
export function puedeEnlazar(documento) {
  return !!documento?.sin_registro && !documento.subsanado
}
