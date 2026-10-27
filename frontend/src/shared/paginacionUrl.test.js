import test from 'node:test'
import assert from 'node:assert/strict'
import { conPagina, conPorPagina, leerPaginacion, paginaCorregida, textoRango } from './paginacionUrl.js'

const p = texto => new URLSearchParams(texto)

test('lee página y tamaño, y normaliza valores ausentes o no permitidos', () => {
  assert.deepEqual(leerPaginacion(p('')), { pagina: 1, porPagina: 10 })
  assert.deepEqual(leerPaginacion(p('page=3&por_pagina=15')), { pagina: 3, porPagina: 15 })
  for (const malo of ['page=0', 'page=-2', 'page=1.5', 'page=abc']) assert.equal(leerPaginacion(p(malo)).pagina, 1)
  for (const malo of ['por_pagina=50', 'por_pagina=0', 'por_pagina=abc']) assert.equal(leerPaginacion(p(malo)).porPagina, 10)
  // Excepción justificada de un módulo (p. ej. 50 en Evaluaciones).
  assert.equal(leerPaginacion(p('por_pagina=50'), { opciones: [10, 15, 20, 50] }).porPagina, 50)
})

test('cambiar de página conserva los filtros y no escribe la primera', () => {
  assert.equal(conPagina(p('q=ana&estado=vencido'), 2).toString(), 'q=ana&estado=vencido&page=2')
  assert.equal(conPagina(p('q=ana&page=4'), 1).toString(), 'q=ana')
})

test('cambiar el tamaño vuelve a la primera página y conserva los filtros', () => {
  assert.equal(conPorPagina(p('q=ana&page=3'), 20).toString(), 'q=ana&por_pagina=20')
  assert.equal(conPorPagina(p('por_pagina=20&page=2&dias=60'), 10).toString(), 'dias=60')
})

test('contador con el sustantivo de cada módulo', () => {
  assert.equal(textoRango({ desde: 1, hasta: 10, total: 125, sustantivo: 'legajos' }), 'Mostrando 1–10 de 125 legajos')
  assert.equal(textoRango({ desde: 121, hasta: 125, total: 125, sustantivo: 'personas' }), 'Mostrando 121–125 de 125 personas')
  assert.equal(textoRango({ desde: 0, hasta: 0, total: 0, sustantivo: 'usuarios' }), 'Mostrando 0 de 0 usuarios')
})

test('una página inexistente se corrige a la última válida; sin resultados, a la primera', () => {
  assert.equal(paginaCorregida({ total: 22, pagina: 9, paginas: 3 }), 3)
  assert.equal(paginaCorregida({ total: 22, pagina: 4, paginas: 3 }), 3)
  for (const pagina of [1, 2, 3]) assert.equal(paginaCorregida({ total: 22, pagina, paginas: 3 }), null)
  assert.equal(paginaCorregida({ total: 0, pagina: 5, paginas: 1 }), 1)
  assert.equal(paginaCorregida({ total: 0, pagina: 1, paginas: 1 }), null)
})

test('la corrección conserva filtros y tamaño', () => {
  const url = p('q=ana&estado=vencido&por_pagina=15&page=9')
  assert.equal(conPagina(url, paginaCorregida({ total: 31, pagina: 9, paginas: 3 })).toString(), 'q=ana&estado=vencido&por_pagina=15&page=3')
  assert.equal(conPagina(p('q=zz&page=4'), paginaCorregida({ total: 0, pagina: 4, paginas: 1 })).toString(), 'q=zz')
})
