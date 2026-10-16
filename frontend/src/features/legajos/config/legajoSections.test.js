import assert from 'node:assert/strict'
import test from 'node:test'
import { legajoSections, separarMenu } from './legajoSections.js'

test('generic listings go under consultation and nothing is dropped from the menu', () => {
  const seccion03 = legajoSections.find(item => item.number === '03')
  const categorias = [...seccion03.categories, 'documentos-03']
  const { principales, consultas } = separarMenu(categorias)
  assert.deepEqual(principales, ['seccion-03'])
  assert.deepEqual(consultas, ['formacion-academica', 'capacitaciones', 'colegiaturas', 'documentos-03'])
  assert.deepEqual([...principales, ...consultas].sort(), [...categorias].sort())
})

test('sections without generic listings keep every entry as main', () => {
  const seccion02 = legajoSections.find(item => item.number === '02')
  assert.deepEqual(separarMenu(seccion02.categories), { principales: seccion02.categories, consultas: [] })
})
