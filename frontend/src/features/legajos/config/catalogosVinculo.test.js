import test from 'node:test'
import assert from 'node:assert/strict'
import { ajustesPorPersona, aplicarModalidad, aplicarPuesto, elegirTipoVinculo, enumerar, filtrar, modalidadesDe, parecido, procesosDe, puestosDe, rutaUnidad } from './catalogosVinculo.js'

const unidades = [
  { id: 'va', nombre: 'Vicerrectorado Académico', unidad_padre_id: null, activo: true },
  { id: 'upg', nombre: 'Unidad de Posgrado', unidad_padre_id: 'va', activo: true },
  { id: 'vieja', nombre: 'Oficina de Posgrado', unidad_padre_id: null, activo: false },
]

test('search ignores accents and case and hides inactive items unless already chosen', () => {
  assert.deepEqual(filtrar(unidades, 'POSGRÁDO').map(u => u.id), ['upg'])
  assert.deepEqual(filtrar(unidades, 'posgrado', { actual: 'vieja' }).map(u => u.id), ['upg', 'vieja'])
})

test('the unit path goes from the root to the unit and survives a cycle', () => {
  assert.deepEqual(rutaUnidad(unidades, 'upg'), ['Vicerrectorado Académico', 'Unidad de Posgrado'])
  assert.deepEqual(rutaUnidad([{ id: 'a', nombre: 'A', unidad_padre_id: 'b' }, { id: 'b', nombre: 'B', unidad_padre_id: 'a' }], 'a'), ['B', 'A'])
})

test('a nearly identical name is reported before creating a new one', () => {
  const puestos = [{ id: 'p1', nombre: 'Analista en Presupuesto', activo: true }]
  assert.equal(parecido(puestos, 'analista de presupuesto')?.id, 'p1')
  assert.equal(parecido(puestos, 'Asistente de archivo'), null)
})

test('modalities and posts are filtered by type and the chosen unit goes first', () => {
  const modalidades = [{ id: 'cas', regimen_laboral: 'plazo_determinado', tipo_personal: 'administrativo' }, { id: 'dc', regimen_laboral: 'plazo_determinado', tipo_personal: 'docente' }, { id: 'x', regimen_laboral: 'carrera', tipo_personal: null }]
  assert.deepEqual(modalidadesDe(modalidades, 'plazo_determinado', 'docente').map(m => m.id), ['dc'])
  const puestos = [{ id: 'b', nombre: 'B', tipo_personal: 'administrativo', unidad_organica_id: 'otra' }, { id: 'a', nombre: 'A', tipo_personal: 'administrativo', unidad_organica_id: null }, { id: 'c', nombre: 'C', tipo_personal: 'administrativo', unidad_organica_id: 'upg' }, { id: 'd', nombre: 'D', tipo_personal: 'docente' }]
  assert.deepEqual(puestosDe(puestos, 'administrativo', 'upg').map(p => p.id), ['c', 'a', 'b'])
})

test('choosing a post fills only unanswered conditions', () => {
  const { draft, completados } = aplicarPuesto({ obligado_djbr: false, exige_colegiatura: null, tiene_periodo_prueba: null }, { id: 'p', nombre: 'ANALISTA', obligado_djbr: true, exige_colegiatura: true, tiene_periodo_prueba: null })
  assert.deepEqual([draft.puesto_id, draft.nombre_puesto, draft.obligado_djbr, draft.exige_colegiatura], ['p', 'ANALISTA', false, true])
  assert.deepEqual(completados, ['colegiatura'])
  assert.deepEqual(aplicarModalidad({ tiene_periodo_prueba: null }, { id: 'm', tiene_periodo_prueba: true }).draft, { tiene_periodo_prueba: true, modalidad_vinculo_id: 'm' })
})

test('switching to an appointed post clears the selection process and a modality of another type', () => {
  const cambios = {}
  const poner = clave => valor => { cambios[clave] = valor }
  const opciones = { modalidades: [{ id: 'cas', regimen_laboral: 'plazo_determinado' }] }
  elegirTipoVinculo({ modalidad_vinculo_id: 'cas', tipo_proceso_seleccion_id: 'p' }, poner, opciones, 'designado')
  assert.deepEqual(cambios, { regimen_laboral: 'designado', modalidad_vinculo_id: '', tipo_proceso_seleccion_id: '', proceso_seleccion: '', fecha_resultado_seleccion: '' })
  const otros = {}
  elegirTipoVinculo({ modalidad_vinculo_id: 'cas' }, clave => valor => { otros[clave] = valor }, opciones, 'plazo_determinado')
  assert.deepEqual(otros, { regimen_laboral: 'plazo_determinado' })
  assert.equal(enumerar(['a', 'b', 'c']), 'a, b y c')
})

// Catálogo como el de partida: los procesos y modalidades dicen a qué tipo de personal y ley corresponden.
const catalogo = {
  procesos: [
    { id: 'cas', nombre: 'Proceso CAS', tipo_personal: 'administrativo', regimen_normativo_id: 'ley1057' },
    { id: 'publico', nombre: 'Concurso público de méritos', tipo_personal: 'administrativo', regimen_normativo_id: null },
    { id: 'docente', nombre: 'Concurso docente', tipo_personal: 'docente', regimen_normativo_id: null },
    { id: 'interno', nombre: 'Concurso interno', tipo_personal: null, regimen_normativo_id: null },
  ],
  modalidades: [
    { id: 'nombrado', regimen_laboral: 'carrera', tipo_personal: 'administrativo', regimen_normativo_id: 'ley276' },
    { id: 'contrato_cas', regimen_laboral: 'plazo_determinado', tipo_personal: 'administrativo', regimen_normativo_id: 'ley1057' },
  ],
  puestos: [{ id: 'profesor', nombre: 'Profesor', tipo_personal: 'docente' }],
}

test('un administrativo del 276 no ve el concurso docente ni el proceso CAS', () => {
  assert.deepEqual(procesosDe(catalogo.procesos, 'administrativo', 'ley276').map(p => p.id), ['publico', 'interno'])
  assert.deepEqual(procesosDe(catalogo.procesos, 'administrativo', 'ley1057').map(p => p.id), ['cas', 'publico', 'interno'])
  assert.deepEqual(procesosDe(catalogo.procesos, 'docente', 'ley30220').map(p => p.id), ['docente', 'interno'])
})

test('la modalidad de una ley solo se ofrece con esa ley', () => {
  assert.deepEqual(modalidadesDe(catalogo.modalidades, 'carrera', 'administrativo', 'ley276').map(m => m.id), ['nombrado'])
  assert.deepEqual(modalidadesDe(catalogo.modalidades, 'carrera', 'administrativo', 'ley728').map(m => m.id), [])
  assert.deepEqual(modalidadesDe(catalogo.modalidades, 'plazo_determinado', 'administrativo', 'ley276').map(m => m.id), [])
})

test('al cambiar la ley o el tipo se vacía lo que deja de corresponder', () => {
  const draft = { puesto_id: 'profesor', nombre_puesto: 'Profesor', modalidad_vinculo_id: 'contrato_cas', tipo_proceso_seleccion_id: 'cas', proceso_seleccion: 'Proceso CAS' }
  assert.deepEqual(ajustesPorPersona(draft, catalogo, 'administrativo', 'ley276'),
    { puesto_id: '', nombre_puesto: '', modalidad_vinculo_id: '', tipo_proceso_seleccion_id: '', proceso_seleccion: '' })
  // Lo que sigue correspondiendo no se toca.
  assert.deepEqual(ajustesPorPersona({ modalidad_vinculo_id: 'contrato_cas', tipo_proceso_seleccion_id: 'publico' }, catalogo, 'administrativo', 'ley1057'), {})
})
