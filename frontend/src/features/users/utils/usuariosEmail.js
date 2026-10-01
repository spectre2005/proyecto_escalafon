export function suggestEmail(nombres, apellidoPaterno) {
  const normalize = (value) => value
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
  const nombre = normalize(nombres.trim().split(/\s+/)[0])
  const apellido = normalize(apellidoPaterno.trim())
  return nombre && apellido ? `${nombre}-${apellido}@uniq.edu.pe` : ''
}

export function updateUserDraft(draft, key, value) {
  const next = { ...draft, [key]: value }
  if (['nombres', 'apellido_paterno'].includes(key) &&
      (!draft.email_institucional || draft.email_institucional === suggestEmail(draft.nombres, draft.apellido_paterno))) {
    next.email_institucional = suggestEmail(next.nombres, next.apellido_paterno)
  }
  return next
}
