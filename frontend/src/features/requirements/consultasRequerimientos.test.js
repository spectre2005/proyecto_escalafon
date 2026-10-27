import test from 'node:test'
import assert from 'node:assert/strict'
import { parametrosLista, urlOpciones, urlSugerencias } from './consultasRequerimientos.js'

test('las opciones solo se piden con el formulario abierto', () => {
  assert.equal(urlOpciones(false), null)
  assert.equal(urlOpciones(true), '/solicitudes-legajo/opciones')
})

test('las sugerencias solo se piden en su pestaña y nunca caen en otra consulta', () => {
  for (const clase of ['documentos', 'actualizacion']) assert.equal(urlSugerencias({ admin: true, clase }), null)
  assert.equal(urlSugerencias({ admin: false, clase: 'sugerencias' }), null)
  assert.equal(urlSugerencias({ admin: true, clase: 'sugerencias', q: 'ana maría', page: 2 }), '/solicitudes-legajo/sugerencias?q=ana%20mar%C3%ADa&page=2')
  assert.equal(urlSugerencias({ admin: true, clase: 'sugerencias' }), '/solicitudes-legajo/sugerencias?q=&page=1')
})

test('las sugerencias envían el tamaño solo si no es el de por defecto', () => {
  assert.equal(urlSugerencias({ admin: true, clase: 'sugerencias', page: 3, porPagina: 10 }), '/solicitudes-legajo/sugerencias?q=&page=3')
  assert.equal(urlSugerencias({ admin: true, clase: 'sugerencias', page: 2, porPagina: 20 }), '/solicitudes-legajo/sugerencias?q=&page=2&por_pagina=20')
})

test('la lista principal conserva filtros y paginación, salvo en Sugerencias', () => {
  const url = new URLSearchParams('clase=actualizacion&estado=abierto&q=ana&page=3&por_pagina=15')
  assert.equal(parametrosLista(url, 'actualizacion', { pagina: 3, porPagina: 15 }).toString(), 'clase=actualizacion&estado=abierto&q=ana&por_pagina=15&page=3')
  assert.equal(parametrosLista(new URLSearchParams('page=0&por_pagina=99'), 'documentos', { pagina: 1, porPagina: 10 }).toString(), 'clase=documentos')
  // Paginar Sugerencias no mueve la lista principal (que sigue aportando avisos y estado del correo).
  assert.equal(parametrosLista(new URLSearchParams('clase=sugerencias&q=ana&page=4&por_pagina=20'), 'sugerencias', { pagina: 4, porPagina: 20 }).toString(), '')
})
