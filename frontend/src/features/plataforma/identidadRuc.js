import { proponerSlug } from './estado.js'

export function rucValido(ruc) {
  if (typeof ruc !== 'string' || !/^(10|15|16|17|20)[0-9]{9}$/.test(ruc)) return false
  const pesos = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2]
  const suma = pesos.reduce((total, peso, indice) => total + Number(ruc[indice]) * peso, 0)
  const digito = 11 - suma % 11
  return (digito === 10 ? 0 : digito === 11 ? 1 : digito) === Number(ruc[10])
}

/** El nombre comercial y el identificador elegidos a mano se conservan. */
export function completarInstitucionRuc(actual, empresa, { completarNombre = false } = {}) {
  if (!actual || actual.ruc !== empresa.ruc) return actual
  const siguiente = { ...actual, razon_social: empresa.razon_social }
  if (completarNombre && (!actual.nombre?.trim() || actual.nombre === actual.razon_social?.slice(0, 150).trim())) {
    siguiente.nombre = empresa.razon_social.slice(0, 150).trim()
    if (!actual.slug || actual.slug === proponerSlug(actual.nombre)) siguiente.slug = proponerSlug(siguiente.nombre)
  }
  return siguiente
}

/** Al cambiar de contribuyente se descartan sus datos anteriores, conservando los demás campos. */
export function cambiarRucInstitucion(actual, ruc, { completarNombre = false } = {}) {
  if (!actual || actual.ruc === ruc) return actual
  const siguiente = { ...actual, ruc, razon_social: '' }
  if (completarNombre && actual.razon_social && actual.nombre === actual.razon_social.slice(0, 150).trim()) {
    siguiente.nombre = ''
    if (actual.slug === proponerSlug(actual.nombre)) siguiente.slug = ''
  }
  return siguiente
}
