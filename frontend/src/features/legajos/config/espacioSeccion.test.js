import assert from 'node:assert/strict'
import test from 'node:test'
import { atajosDe, buscarDocumento, documentosSinAsociar, idBloque, tituloSeccion } from './espacioSeccion.js'

const tipos = [
  { id: 't-b', literal: 'b', nombre: 'Resolución de rotación', exigido: false, lista: [
    { id: 'd-1', values: { folio_inicio: 3, folio_fin: 4 }, sin_registro: false, subsanado: false },
    { id: 'd-2', values: { folio_inicio: 5, folio_fin: 5 }, sin_registro: true, subsanado: false },
  ] },
  { id: 't-c', literal: 'c', nombre: 'Resolución de destaque', exigido: true, lista: [] },
  { id: 't-d', literal: 'd', nombre: 'Encargo', exigido: false, lista: [{ id: 'd-3', values: {}, sin_registro: true, subsanado: true }] },
]

test('the record card finds the document that backs it, in any type', () => {
  assert.equal(buscarDocumento(tipos, 'd-1').tipo.id, 't-b')
  assert.equal(buscarDocumento(tipos, 'no-existe'), null)
  assert.equal(buscarDocumento(tipos, null), null)
})

test('documents without a record are listed apart, but not the corrected ones', () => {
  assert.deepEqual(documentosSinAsociar(tipos).map(({ documento }) => documento.id), ['d-2'])
})

test('page shortcuts tell where the records, the types and the pending links are', () => {
  assert.deepEqual(atajosDe({ registros: 2, tipos }), [
    { id: idBloque.registros, texto: 'Registros (2)' },
    { id: idBloque.documentos, texto: 'Documentos (3 tipos, 1 exigido(s) sin documento)' },
    { id: idBloque.sinAsociar, texto: 'Sin registro asociado (1)', alerta: true },
  ])
  // Sin registros (p. ej. documentos de la 11) no hay atajo a registros.
  assert.equal(atajosDe({ tipos: [] }).length, 1)
  assert.equal(atajosDe({ tipos: [tipos[1]] })[0].texto, 'Documentos (1 tipo, 1 exigido(s) sin documento)')
  // Página solo de registros (p. ej. desvinculaciones en la 11): sin atajo a documentos.
  assert.deepEqual(atajosDe({ registros: 0 }), [{ id: idBloque.registros, texto: 'Registros (0)' }])
})

test('the section title comes from the same catalogue as the menu', () => {
  assert.match(tituloSeccion('07'), /^07 · Evaluación de desempeño/)
  assert.equal(tituloSeccion('99'), 'Sección 99')
})

test('the work area says which document the step is about', async () => {
  const { tituloPaso } = await import('./espacioSeccion.js')
  assert.equal(tituloPaso('Enlazar', tipos[0], tipos[0].lista[0]), 'Enlazar: b) Resolución de rotación (folios 3–4)')
  assert.equal(tituloPaso('Completar los datos', tipos[1]), 'Completar los datos: c) Resolución de destaque')
})

test('the state tag is only shown when it says something', async () => {
  const { mostrarEstado } = await import('./espacioSeccion.js')
  assert.equal(mostrarEstado({ exigido: false, documentos: 0 }), false)
  assert.equal(mostrarEstado({ exigido: false, documentos: 2 }), true)
  assert.equal(mostrarEstado({ exigido: true, documentos: 0 }), true)
  assert.equal(mostrarEstado({ lista: [{ id: 'd' }] }), true)
  assert.equal(mostrarEstado({ lista: [] }), false)
})
