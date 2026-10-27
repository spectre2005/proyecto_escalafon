import { conPagina, conPorPagina, POR_PAGINA } from '../../shared/paginacionUrl.js'

// Consultas bajo demanda de Requerimientos (useManualQuery, sin sondeo): null = no se pide nada.

// Opciones (todas las personas con legajo activo y el catálogo): solo con el formulario de registro abierto.
export function urlOpciones(formularioAbierto) {
  return formularioAbierto ? '/solicitudes-legajo/opciones' : null
}

// Sugerencias (recorren el perfil de cada persona): solo en su pestaña y para quien gestiona requerimientos.
// El tamaño por defecto no se envía (el servidor usa el mismo, PorPagina).
export function urlSugerencias({ admin, clase, q = '', page = 1, porPagina = POR_PAGINA }) {
  if (!admin || clase !== 'sugerencias') return null
  return `/solicitudes-legajo/sugerencias?q=${encodeURIComponent(q)}&page=${page}${porPagina !== POR_PAGINA ? `&por_pagina=${porPagina}` : ''}`
}

/**
 * Parámetros de la lista principal. En Sugerencias solo aporta los avisos y el estado del correo: no recibe la búsqueda
 * ni la paginación de esa pestaña.
 */
export function parametrosLista(params, clase, { pagina, porPagina }) {
  const siguiente = conPagina(conPorPagina(params, porPagina), pagina)
  siguiente.delete('nuevo')
  siguiente.delete('persona')
  if (clase === 'todas') {
    siguiente.delete('clase')
  } else if (clase === 'sugerencias') {
    for (const clave of ['clase', 'q', 'page', 'por_pagina']) siguiente.delete(clave)
  } else {
    siguiente.set('clase', clase)
  }
  return siguiente
}
