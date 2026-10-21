import assert from 'node:assert/strict'
import test from 'node:test'
import { cambiarModalidad, cambiarTipoVinculo, coexistenDocenciaYCas, requisitosIngreso, rotulosIngreso, tieneDocencia } from './ingresoLaboral.js'
import { tiposVinculoPara } from './seccion02.js'

const draft = { regimen_laboral: 'plazo_determinado', modalidad_ingreso: 'concurso_publico', proceso_seleccion_id: 'p-1', proceso_seleccion: 'CAS-001-2026', anio_proceso: 2026, fecha_resultado_seleccion: '2026-01-01', fecha_fin_prevista: '2026-12-31', numero_contrato: 'C-1', sustento_ingreso: '' }

test('CAS requires a contract, and confidence also requires a designation resolution', () => {
  assert.deepEqual(requisitosIngreso(draft, '1057'), { contrato: true, resolucion: false, proceso: true })
  assert.deepEqual(requisitosIngreso(cambiarModalidad(draft, 'designacion_confianza'), '1057'), { contrato: true, resolucion: true, proceso: false })
  assert.deepEqual(requisitosIngreso({ modalidad_ingreso: 'concurso_publico', regimen_laboral: 'carrera' }, '276'), { contrato: false, resolucion: true, proceso: true })
})

test('changing to confidence clears the contest and keeps the actual contract reference', () => {
  const siguiente = cambiarModalidad(draft, 'designacion_confianza')
  assert.equal(siguiente.regimen_laboral, 'designado')
  for (const campo of ['proceso_seleccion_id', 'proceso_seleccion', 'anio_proceso', 'fecha_resultado_seleccion']) assert.equal(siguiente[campo], '')
  assert.equal(siguiente.numero_contrato, 'C-1')
  assert.equal(draft.proceso_seleccion_id, 'p-1')
})

test('indefinite duration removes the planned end and does not imply career status', () => {
  const siguiente = cambiarTipoVinculo(draft, 'plazo_indeterminado')
  assert.equal(siguiente.fecha_fin_prevista, '')
  assert.equal(siguiente.regimen_laboral, 'plazo_indeterminado')
  assert.equal(requisitosIngreso(siguiente, '1057').contrato, true)
  assert.equal('carrera' in tiposVinculoPara('administrativo', '1057'), false)
  assert.equal('plazo_indeterminado' in tiposVinculoPara('administrativo', '1057'), true)
})

test('switching from confidence requires explicitly choosing the next admission mode', () => {
  const confianza = cambiarModalidad(draft, 'designacion_confianza')
  assert.equal(cambiarTipoVinculo(confianza, 'plazo_indeterminado').modalidad_ingreso, '')
  assert.equal(cambiarModalidad(confianza, 'otro_sustentado').regimen_laboral, '')
  assert.equal(requisitosIngreso({ regimen_laboral: 'plazo_determinado', modalidad_ingreso: '' }, '1057').contrato, false)
})

test('teacher admission asks for its resolution and keeps the teaching contract separate from CAS', () => {
  assert.deepEqual(requisitosIngreso({ ...draft, tipo_personal: 'docente' }, '30220'), { contrato: true, resolucion: true, proceso: true })
  assert.deepEqual(requisitosIngreso({ ...draft, regimen_laboral: 'carrera' }, '30220', 'docente'), { contrato: false, resolucion: true, proceso: true })
  for (const condicion of ['ordinario', 'contratado', 'extraordinario']) {
    const rotulos = rotulosIngreso('docente', condicion, '30220')
    assert.match(rotulos.resolucion, /docente/)
    assert.equal(rotulos.contrato, 'Contrato docente')
    assert.doesNotMatch(rotulos.proceso, /CAS/)
  }
  assert.match(rotulosIngreso('docente', 'ordinario', '30220').resolucion, /nombramiento/)
  assert.match(rotulosIngreso('docente', 'contratado', '30220').resolucion, /contratación/)
  assert.match(rotulosIngreso('administrativo', null, '1057').proceso, /CAS/)
  assert.doesNotMatch(rotulosIngreso('administrativo', null, '276').proceso, /CAS/)
})

test('a teaching history offers the additional CAS route even when the teacher link ended', () => {
  assert.equal(tieneDocencia({ perfil: { tipos_personal: ['docente'] }, vinculos: [] }), true)
  assert.equal(tieneDocencia({ vinculos: [{ values: { tipo_personal: 'docente' }, vigente: false }] }), true)
  assert.equal(tieneDocencia({ vinculos: [{ values: { tipo_personal: 'docente', anulado: true } }] }), false)
  assert.equal(tieneDocencia({ perfil: { tipos_personal: ['administrativo'] }, vinculos: [] }), false)
})

test('the coexistence notice uses dates without treating historical or annulled links as simultaneous', () => {
  const docente = { vigente: true, values: { tipo_personal: 'docente' }, codigo_ley: '30220' }
  const cas = { vigente: true, values: { tipo_personal: 'administrativo' }, codigo_ley: '1057' }
  assert.equal(coexistenDocenciaYCas([docente, cas]), true)
  assert.equal(coexistenDocenciaYCas([docente, { ...cas, codigo_ley: 'CAS' }]), true)
  assert.equal(coexistenDocenciaYCas([docente, { ...cas, codigo_ley: '276' }]), false)
  assert.equal(coexistenDocenciaYCas([{ ...docente, vigente: false }, cas]), false)
  assert.equal(coexistenDocenciaYCas([docente, { ...cas, values: { tipo_personal: 'administrativo', anulado: true } }]), false)
  assert.equal(coexistenDocenciaYCas(), false)
})
