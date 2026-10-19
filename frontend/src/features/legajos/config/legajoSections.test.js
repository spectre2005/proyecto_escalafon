import assert from 'node:assert/strict'
import test from 'node:test'
import { CATEGORIA_DOCUMENTOS, categoriaVigente, legajoSections } from './legajoSections.js'

test('each section has one entry per topic: no generic listings repeat its panel', () => {
  const categorias = legajoSections.flatMap(item => item.categories)
  for (const repetida of ['formacion-academica', 'capacitaciones', 'colegiaturas', 'experiencia-laboral', 'compensaciones-beneficios']) {
    assert.equal(categorias.includes(repetida), false, repetida)
  }
  assert.equal(categorias.some(categoria => categoria.startsWith('documentos')), false)
  assert.deepEqual(['03', '04', '06'].map(numero => legajoSections.find(item => item.number === numero).categories), [['seccion-03'], ['seccion-04'], ['seccion-06']])
})

test('saved links to retired entries lead to the entry that shows the same', () => {
  assert.equal(categoriaVigente('formacion-academica'), 'seccion-03')
  assert.equal(categoriaVigente('colegiaturas'), 'seccion-03')
  assert.equal(categoriaVigente('experiencia-laboral'), 'seccion-04')
  assert.equal(categoriaVigente('compensaciones-beneficios'), 'seccion-06')
  assert.equal(categoriaVigente('tiempo-servicios-reconocidos'), 'seccion-06')
  assert.equal(categoriaVigente('documentos-03'), CATEGORIA_DOCUMENTOS)
  assert.equal(categoriaVigente('documentos-10'), 's10-sst')
  assert.equal(categoriaVigente('s05-licencias'), 's05-licencias')
  assert.equal(categoriaVigente(CATEGORIA_DOCUMENTOS), CATEGORIA_DOCUMENTOS)
})
