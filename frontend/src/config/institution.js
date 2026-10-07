export const defaultInstitution = {
  name: 'Mi instituci\u00f3n', area: 'Gesti\u00f3n de personas', initials: 'MI',
  logo: '', ruc: '', address: '', email: '', phone: '', website: '',
  reportTitle: 'Ficha de datos personales', reportFooter: 'Documento de uso institucional.',
  reportColor: '#0f766e', showLogo: true, showEmissionDate: true, showPageNumbers: true,
}

export function normalizeInstitution(value = {}) {
  const source = value && typeof value === 'object' ? value : {}
  const result = { ...defaultInstitution }
  const limits = { name: 150, area: 150, initials: 12, ruc: 11, address: 250, email: 255, phone: 20, website: 255, reportTitle: 100, reportFooter: 180 }
  for (const [key, limit] of Object.entries(limits)) {
    if (typeof source[key] === 'string') result[key] = source[key].trim().slice(0, limit)
  }
  for (const key of ['name', 'area', 'initials', 'reportTitle']) {
    if (!result[key]) result[key] = defaultInstitution[key]
  }
  if (typeof source.logo === 'string' && source.logo.length <= 750000 && /^data:image\/(png|jpeg);base64,[A-Za-z0-9+/=]+$/.test(source.logo)) result.logo = source.logo
  if (typeof source.reportColor === 'string' && /^#[0-9a-f]{6}$/i.test(source.reportColor)) result.reportColor = source.reportColor
  for (const key of ['showLogo', 'showEmissionDate', 'showPageNumbers']) {
    if (typeof source[key] === 'boolean') result[key] = source[key]
  }
  return result
}

export function readInstitution() {
  try { return normalizeInstitution(JSON.parse(localStorage.getItem('institution.identity'))) }
  catch { return { ...defaultInstitution } }
}

export function cacheInstitution(value) {
  try { localStorage.setItem('institution.identity', JSON.stringify(normalizeInstitution(value))) }
  catch { /* The database remains the authoritative saved configuration. */ }
}
