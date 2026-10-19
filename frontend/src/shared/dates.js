export function todayLima(now = new Date()) {
  return new Intl.DateTimeFormat('sv-SE', { timeZone: 'America/Lima' }).format(now)
}

export function formatDate(value) {
  return value ? value.slice(0, 10).split('-').reverse().join('/') : 'Sin fecha'
}
