import assert from 'node:assert/strict'
import test from 'node:test'
import { ajustarAPuesto, completarDesdePuesto, diferenciasConPuesto, equivalenciaPuesto, gruposDelPuesto, puestosSugeridos, sincronizarConPuesto, sugerenciasPuesto } from './puestosSugeridos.js'

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

test('changing the position updates what the system filled, not what the user chose', () => {
  const vacio = { condicion_docente: '', categoria: '', dedicacion: 'exclusiva', subtipo_extraordinario: '' }
  const auxiliar = sincronizarConPuesto(vacio, equivalenciaPuesto('docente', 'Profesor ordinario auxiliar'))
  assert.deepEqual([auxiliar.datos.condicion_docente, auxiliar.datos.categoria], ['ordinario', 'auxiliar'])
  assert.deepEqual(auxiliar.autocompletados, ['condicion_docente', 'categoria'])

  // Caso reportado: de auxiliar a asociado, la categoría que puso el sistema sigue al puesto.
  const asociado = sincronizarConPuesto(auxiliar.datos, equivalenciaPuesto('docente', 'Profesor ordinario asociado'), auxiliar.autocompletados)
  assert.deepEqual([asociado.datos.condicion_docente, asociado.datos.categoria, asociado.datos.dedicacion], ['ordinario', 'asociado', 'exclusiva'])
  assert.deepEqual([asociado.completados, asociado.distintos], [['categoria'], []])

  // A un puesto sin equivalencia: lo que venía del puesto anterior se vacía.
  const jefe = sincronizarConPuesto(asociado.datos, equivalenciaPuesto('docente', 'Jefe de práctica'), asociado.autocompletados)
  assert.deepEqual([jefe.datos.condicion_docente, jefe.datos.categoria, jefe.datos.dedicacion], ['', '', 'exclusiva'])
  assert.deepEqual([jefe.quitados, jefe.autocompletados], [['condicion_docente', 'categoria'], []])

  // A contratado: la categoría del ordinario anterior se quita (sin ordinario no vale).
  const contratado = sincronizarConPuesto(auxiliar.datos, equivalenciaPuesto('docente', 'Docente contratado'), auxiliar.autocompletados)
  assert.deepEqual([contratado.datos.condicion_docente, contratado.datos.categoria], ['contratado', ''])
})

test('a manual choice that contradicts the new position is kept and reported', () => {
  const manual = { condicion_docente: 'ordinario', categoria: 'auxiliar', subtipo_extraordinario: '' }
  const resultado = sincronizarConPuesto(manual, equivalenciaPuesto('docente', 'Profesor ordinario asociado'), [])
  assert.deepEqual(resultado.datos, manual)
  assert.deepEqual([resultado.distintos, resultado.completados], [['categoria'], []])
})

test('differences are computed in both directions and adjust fixes them', () => {
  const equivalencia = equivalenciaPuesto('docente', 'Profesor ordinario asociado')
  assert.deepEqual(diferenciasConPuesto({ condicion_docente: 'ordinario', categoria: 'auxiliar' }, equivalencia), ['categoria'])
  assert.deepEqual(diferenciasConPuesto({ condicion_docente: 'ordinario', categoria: '' }, equivalencia), [])
  assert.deepEqual(diferenciasConPuesto({ condicion_docente: 'contratado', categoria: '' }, {}), [])

  const ajustado = ajustarAPuesto({ condicion_docente: 'extraordinario', categoria: '', subtipo_extraordinario: 'emerito', dedicacion: 'exclusiva' }, equivalencia)
  assert.deepEqual(ajustado, { condicion_docente: 'ordinario', categoria: 'asociado', subtipo_extraordinario: '', dedicacion: 'exclusiva' })
  assert.deepEqual(diferenciasConPuesto(ajustado, equivalencia), [])
})

test('cada denominación sugerida admite solo sus grupos ocupacionales', () => {
  assert.deepEqual(gruposDelPuesto('Técnico administrativo'), ['tecnico'])
  assert.deepEqual(gruposDelPuesto('AUXILIAR ADMINISTRATIVO'), ['auxiliar'])
  assert.deepEqual(gruposDelPuesto('Secretario'), ['tecnico', 'auxiliar'])
  assert.equal(gruposDelPuesto('Puesto escrito antes'), null)
  assert.equal(gruposDelPuesto(''), null)
})

test('el puesto del catálogo manda con su grupo; sin grupo admite cualquiera', () => {
  const catalogo = [{ nombre: 'Técnico administrativo', tipo_personal: 'administrativo', grupo_ocupacional: 'profesional' }, { nombre: 'Chofer', tipo_personal: 'administrativo', grupo_ocupacional: null }]
  assert.deepEqual(gruposDelPuesto('Técnico administrativo', catalogo), ['profesional'])
  assert.equal(gruposDelPuesto('Chofer', catalogo), null)
})

test('el puesto elegido por su id manda; por nombre solo cuentan los activos y no importan las tildes', () => {
  const catalogo = [
    { id: 'a1', nombre: 'ANALISTA', tipo_personal: 'administrativo', grupo_ocupacional: 'profesional' },
    { id: 'a2', nombre: 'ANALISTA', tipo_personal: 'administrativo', grupo_ocupacional: 'tecnico' },
    { id: 'x', nombre: 'ASISTENTE ADMINISTRATIVO', tipo_personal: 'administrativo', grupo_ocupacional: 'profesional', activo: false },
  ]
  assert.deepEqual(gruposDelPuesto('ANALISTA', catalogo, 'a2'), ['tecnico'])
  assert.deepEqual(gruposDelPuesto('ANALISTA', catalogo).sort(), ['profesional', 'tecnico'])
  // Un puesto desactivado del mismo nombre no reemplaza a la denominación sugerida.
  assert.deepEqual(gruposDelPuesto('Asistente administrativo', catalogo), ['tecnico'])
  assert.deepEqual(gruposDelPuesto('TECNICO ADMINISTRATIVO'), ['tecnico'])
})

