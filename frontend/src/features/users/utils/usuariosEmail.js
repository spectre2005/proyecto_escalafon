// Correo sugerido con el dominio del personal configurado por la institución (Configuración → Identidad, emailDomain).
// Sin dominio configurado no se sugiere nada: no se inventa ni se fija un dominio en el código.
export function suggestEmail(nombres, apellidoPaterno, dominio = '') {
  if (!dominio) return ''
  const normalize = (value) => value
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
  const nombre = normalize(nombres.trim().split(/\s+/)[0])
  const apellido = normalize(apellidoPaterno.trim())
  return nombre && apellido ? `${nombre}-${apellido}@${dominio}` : ''
}

// El correo se completa solo mientras esté vacío o siga siendo la sugerencia anterior (no pisa lo escrito a mano).
export function updateUserDraft(draft, key, value, dominio = '') {
  const next = { ...draft, [key]: value }
  if (dominio && ['nombres', 'apellido_paterno'].includes(key) &&
      (!draft.email_institucional || draft.email_institucional === suggestEmail(draft.nombres, draft.apellido_paterno, dominio))) {
    next.email_institucional = suggestEmail(next.nombres, next.apellido_paterno, dominio)
  }
  return next
}
