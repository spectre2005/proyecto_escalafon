// Ley (régimen normativo) de la persona: catálogo de /expediente-opciones (regimenes: id, codigo, nombre, aplica_a,
// activo). Se guarda en regimen_normativo_id; el tipo de vínculo (carrera, plazo determinado, designado) va en el
// vínculo laboral (2.2). Alias de código en sincronía con backend/app/Support/RegimenesLaborales.php (ALIAS_LEY).
export const aliasLey = { CAS: '1057' }

export const SIN_DEFINIR = 'Sin definir'

export function leyesDelTipo(catalogo, tipoPersonal) {
  return (catalogo || []).filter(ley => ley.aplica_a === tipoPersonal && ley.activo)
}

/** Código que se escribe en la plantilla (el alias, si lo tiene: 1057 se escribe CAS). */
export function codigoVisible(ley) {
  return Object.keys(aliasLey).find(alias => aliasLey[alias] === ley.codigo) || ley.codigo
}

export function leyPorCodigo(catalogo, codigo) {
  const texto = String(codigo || '').trim().toUpperCase()
  if (!texto) return null
  const buscado = aliasLey[texto] || texto
  return (catalogo || []).find(ley => String(ley.codigo).toUpperCase() === buscado) || null
}

export function leyInicial(catalogo, tipoPersonal) {
  const opciones = leyesDelTipo(catalogo, tipoPersonal)
  return opciones.length === 1 ? opciones[0].id : ''
}

/** Ley de un registro del alta: por id (formulario) o por código (importación). */
export function leyDelRegistro(catalogo, user) {
  if (user.regimen_normativo_id) return (catalogo || []).find(ley => ley.id === user.regimen_normativo_id) || null
  return leyPorCodigo(catalogo, user.ley)
}

/** Error de ley para el tipo de personal, o null. Mismos criterios que RegimenesLaborales::error. */
export function errorLey(catalogo, user) {
  if (!catalogo) return 'Cargando el catálogo de leyes…'
  const ley = leyDelRegistro(catalogo, user)
  const codigos = leyesDelTipo(catalogo, user.tipo_personal).map(codigoVisible)
  if (!ley) return `Indica la ley (régimen normativo)${codigos.length ? `: ${codigos.join(', ')}` : ''}.`
  if (!ley.activo) return `La ley ${ley.nombre} está inactiva en el catálogo.`
  if (ley.aplica_a !== user.tipo_personal) return `La ley ${ley.nombre} corresponde al personal ${ley.aplica_a}, no al ${user.tipo_personal}.`
  return null
}

/** Nombre de la ley de una persona de /usuarios (campo ley); sin ley, Sin definir. */
export function nombreLey(user) {
  return user.ley?.nombre || SIN_DEFINIR
}

/** Registro del alta listo para enviar: la ley va por id (el código de la importación no se envía). */
export function conLey(catalogo, user) {
  const resto = { ...user }
  delete resto.ley
  return { ...resto, regimen_normativo_id: leyDelRegistro(catalogo, user)?.id || null }
}
