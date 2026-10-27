// Paginación común de los listados: tamaño en la URL (`por_pagina`) y texto del contador. Cada listado decide si
// pagina en el servidor o en el navegador; aquí solo se leen y escriben los parámetros.

export const OPCIONES_POR_PAGINA = [10, 15, 20]
export const POR_PAGINA = 10

/** Página y tamaño de la URL; un valor ausente o no permitido vuelve al valor por defecto. */
export function leerPaginacion(params, { opciones = OPCIONES_POR_PAGINA, porDefecto = POR_PAGINA } = {}) {
  const pagina = Number(params.get('page'))
  const porPagina = Number(params.get('por_pagina'))
  return { pagina: Number.isInteger(pagina) && pagina > 0 ? pagina : 1, porPagina: opciones.includes(porPagina) ? porPagina : porDefecto }
}

/** Parámetros con la página indicada; la primera no se escribe. */
export function conPagina(params, pagina) {
  const siguiente = new URLSearchParams(params)
  if (pagina > 1) siguiente.set('page', String(pagina))
  else siguiente.delete('page')
  return siguiente
}

/** Parámetros con otro tamaño (el valor por defecto no se escribe) y de vuelta en la primera página. */
export function conPorPagina(params, porPagina, porDefecto = POR_PAGINA) {
  const siguiente = new URLSearchParams(params)
  if (porPagina === porDefecto) siguiente.delete('por_pagina')
  else siguiente.set('por_pagina', String(porPagina))
  siguiente.delete('page')
  return siguiente
}

/** «Mostrando 1–10 de 125 legajos». */
export function textoRango({ desde, hasta, total, sustantivo }) {
  return total ? `Mostrando ${desde}–${hasta} de ${total} ${sustantivo}` : `Mostrando 0 de 0 ${sustantivo}`
}

/**
 * Página a la que hay que llevar una URL con una página inexistente, o null si la página es válida. Con resultados, la
 * última válida; sin ninguno, la primera (es una búsqueda vacía, no una página inexistente).
 */
export function paginaCorregida({ total, pagina, paginas }) {
  if (total > 0) return pagina > paginas ? paginas : null
  return pagina > 1 ? 1 : null
}
