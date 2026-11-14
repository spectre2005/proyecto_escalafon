import assert from 'node:assert/strict'
import test from 'node:test'
import { completarDesdePuesto, equivalenciaPuesto, puestosSugeridos, sugerenciasPuesto } from './puestosSugeridos.js'

test('suggestions depend on the personnel type and come from a single source', () => {
  assert.ok(sugerenciasPuesto('docente').includes('Profesor ordinario principal'))
  assert.ok(sugerenciasPuesto('administrativo').includes('Asistente administrativo'))
  assert.ok(!sugerenciasPuesto('docente').includes('Asistente administrativo'))
  assert.ok(!sugerenciasPuesto('administrativo').includes('Docente contratado'))
  assert.deepEqual(sugerenciasPuesto(''), [])
  assert.equal(sugerenciasPuesto('docente'), puestosSugeridos.docente)
  // «Otro» no es una sugerencia que se guarde: es la opción para escribir una denominación propia.
  assert.ok(!Object.values(puestosSugeridos).flat().some(puesto => puesto.startsWith('Otro')))
})

test('a position saved in uppercase still matches its equivalence', () => {
  assert.deepEqual(equivalenciaPuesto('docente', 'PROFESOR ORDINARIO PRINCIPAL'), { condicion_docente: 'ordinario', categoria: 'principal' })
})

test('only unequivocal positions fill teacher data, and never over the user choice', () => {
  assert.deepEqual(equivalenciaPuesto('docente', 'Profesor ordinario principal'), { condicion_docente: 'ordinario', categoria: 'principal' })
  assert.deepEqual(equivalenciaPuesto('docente', 'Docente contratado'), { condicion_docente: 'contratado' })
  assert.deepEqual(equivalenciaPuesto('docente', 'Jefe de práctica'), {})
  assert.deepEqual(equivalenciaPuesto('docente', 'Docente investigador'), {})
  assert.deepEqual(equivalenciaPuesto('administrativo', 'Profesor ordinario principal'), {})

  const vacio = { condicion_docente: '', categoria: '', dedicacion: '' }
  const lleno = completarDesdePuesto(vacio, equivalenciaPuesto('docente', 'Profesor ordinario auxiliar'))
  assert.deepEqual(lleno.datos, { condicion_docente: 'ordinario', categoria: 'auxiliar', dedicacion: '' })
  assert.deepEqual(lleno.completados, ['condicion_docente', 'categoria'])
  // Ya eligió contratado: no se cambia nada (ni la categoría, que sin ordinario no vale) y se avisa.
  const elegido = { condicion_docente: 'contratado', categoria: '', dedicacion: 'exclusiva' }
  const distinto = completarDesdePuesto(elegido, equivalenciaPuesto('docente', 'Profesor ordinario principal'))
  assert.equal(distinto.datos, elegido)
  assert.deepEqual([distinto.completados, distinto.distintos], [[], ['condicion_docente']])
  // Coincide en parte: solo se completa lo vacío.
  assert.deepEqual(completarDesdePuesto({ condicion_docente: 'ordinario', categoria: '' }, equivalenciaPuesto('docente', 'Profesor ordinario asociado')).datos,
    { condicion_docente: 'ordinario', categoria: 'asociado' })
})
