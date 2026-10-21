import { banderasPersona, valorBandera } from '../../../shared/banderas.js'

export const partesUbicacion = persona => Object.fromEntries(['region', 'provincia', 'distrito'].map(campo => [campo, persona?.[campo] || '']))
const normalizar = (campo, valor) => banderasPersona.includes(campo) ? valorBandera(valor) : typeof valor === 'string' ? valor.trim() || null : valor ?? null

/** Solo envía cambios editables; los campos de identidad registrados se conservan incluso en un borrador alterado. */
export function cambiosInformacionPersonal(original, draft, ubicacion, bloqueados) {
  const cambios = Object.fromEntries(Object.entries(draft)
    .filter(([campo, valor]) => !bloqueados.includes(campo) && normalizar(campo, valor) !== normalizar(campo, original[campo]))
    .map(([campo, valor]) => [campo, normalizar(campo, valor)]))
  const anterior = partesUbicacion(original)
  const siguiente = Object.fromEntries(Object.entries(partesUbicacion(ubicacion)).map(([campo, valor]) => [campo, bloqueados.includes(`ubicacion.${campo}`) ? anterior[campo] : valor.trim()]))
  if (Object.keys(anterior).some(campo => siguiente[campo] !== anterior[campo])) {
    cambios.ubicacion = Object.values(siguiente).some(Boolean) ? siguiente : null
  }
  return cambios
}
