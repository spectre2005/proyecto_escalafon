export const defaultInstitution = {
  name: 'Mi institución',
  area: 'Gestión de personas',
  initials: 'MI',
}

export function readInstitution() {
  try {
    const value = JSON.parse(localStorage.getItem('institution.identity'))
    if (value && ['name', 'area', 'initials'].every(key => typeof value[key] === 'string' && value[key].trim())) {
      return { name: value.name.slice(0, 80), area: value.area.slice(0, 80), initials: value.initials.slice(0, 4) }
    }
  } catch { /* Use the default identity when browser storage is unavailable. */ }
  return defaultInstitution
}
