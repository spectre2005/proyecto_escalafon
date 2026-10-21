import assert from 'node:assert/strict'
import test from 'node:test'
import { etiquetaVinculo, pruebaEnNoConDatos, pruebaNoAplica, referenciaDocumentos, tiposVinculoPara } from './seccion02.js'
import { vinculoInicial, vinculoParaGuardar } from '../components/seccion02/borradoresVinculo.js'

test('teacher choices exclude confidence while administrative choices retain it', () => {
  assert.deepEqual(Object.keys(tiposVinculoPara('docente')), ['carrera', 'plazo_determinado'])
  assert.equal(tiposVinculoPara('administrativo').designado, 'Designado (confianza)')
})

test('the draft keeps independent documents and process year without guessing a legacy number', () => {
  const antiguo = vinculoInicial({ numero_documento_vinculo: 'ANTIGUO' })
  assert.equal(antiguo.numero_resolucion, '')
  assert.equal(antiguo.numero_contrato, '')
  assert.equal(antiguo.numero_documento_vinculo, 'ANTIGUO')
  const datos = { numero_resolucion: 'R-1', fecha_resolucion: '2026-01-01', numero_contrato: 'C-2', fecha_contrato: '2026-01-02', anio_proceso: 2026 }
  const draft = vinculoInicial(datos)
  for (const [key, value] of Object.entries(datos)) assert.equal(draft[key], value)
  assert.equal(referenciaDocumentos(draft), 'R-1 · C-2')
  assert.equal(referenciaDocumentos(antiguo), 'ANTIGUO')
  assert.match(etiquetaVinculo({ values: { ...draft, nombre_puesto: 'Analista', fecha_inicio: '2026-01-03' } }), /R-1 · C-2/)
})

test('2.4 does not apply only with the flag in No and nothing registered', () => {
  assert.equal(pruebaNoAplica({ tiene_periodo_prueba: false, prueba_con_datos: false }), true)
  assert.equal(pruebaNoAplica({ tiene_periodo_prueba: false, prueba_con_datos: true }), false)
  assert.equal(pruebaNoAplica({ tiene_periodo_prueba: null, prueba_con_datos: false }), false)
  assert.equal(pruebaNoAplica({ tiene_periodo_prueba: true, prueba_con_datos: false }), false)
  assert.equal(pruebaNoAplica(undefined), false)
})

test('with the flag in No and registered data, 2.4 stays visible with a notice', () => {
  assert.equal(pruebaEnNoConDatos({ tiene_periodo_prueba: false, prueba_con_datos: true }), true)
  assert.equal(pruebaEnNoConDatos({ tiene_periodo_prueba: false, prueba_con_datos: false }), false)
  assert.equal(pruebaEnNoConDatos({ tiene_periodo_prueba: true, prueba_con_datos: true }), false)
})

test('a normal registration never sends a cese date left in a historical draft', () => {
  const draft = { nombre_puesto: 'Analista', fecha_inicio: '2026-10-18', fecha_fin_prevista: '2026-12-31', fecha_cese: '2026-10-19' }
  const datos = vinculoParaGuardar(draft)
  assert.equal(datos.fecha_cese, null)
  assert.equal(datos.fecha_fin_prevista, '2026-12-31')
  assert.equal(draft.fecha_cese, '2026-10-19')
})

test('a historical registration explicitly preserves the real cese date', () => {
  const datos = vinculoParaGuardar({ fecha_cese: '2026-10-19', fecha_fin_prevista: '2026-12-31' }, { historico: true })
  assert.equal(datos.fecha_cese, '2026-10-19')
  assert.equal(datos.fecha_fin_prevista, '2026-12-31')
})

test('correcting an open link can still register its cese and blank dates remain null', () => {
  assert.equal(vinculoParaGuardar({ fecha_cese: '2026-10-19' }, { correccion: true }).fecha_cese, '2026-10-19')
  assert.equal(vinculoParaGuardar({ fecha_cese: '' }, { correccion: true }).fecha_cese, null)
})
