export function auditLabel(value) {
  const text = String(value || '').replaceAll('_', ' ').replaceAll('.', ' · ')
  return text ? text[0].toUpperCase() + text.slice(1) : 'Sin especificar'
}

function canonical(value) {
  if (Array.isArray(value)) return value.map(canonical)
  if (value && typeof value === 'object') return Object.fromEntries(Object.keys(value).sort().map(key => [key, canonical(value[key])]))
  return value
}

export function auditChanges(before, after) {
  const previous = before && typeof before === 'object' ? before : {}
  const current = after && typeof after === 'object' ? after : {}
  return [...new Set([...Object.keys(previous), ...Object.keys(current)])].filter(key => JSON.stringify(canonical(previous[key])) !== JSON.stringify(canonical(current[key]))).map(key => ({ key, before: previous[key], after: current[key] }))
}

export function auditValue(value) {
  if (value === undefined) return 'Sin registrar'
  if (value === null || value === '') return 'Vacío'
  if (typeof value === 'boolean') return value ? 'Sí' : 'No'
  if (typeof value === 'object') return JSON.stringify(value, null, 2)
  return String(value)
}
