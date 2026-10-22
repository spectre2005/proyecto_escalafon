import assert from 'node:assert/strict'
import test from 'node:test'
import { pruebaEnNoConDatos, pruebaNoAplica, puestoActual } from './seccion02.js'
import { vinculoParaGuardar } from '../components/seccion02/borradoresVinculo.js'

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

test('the header never presents a link as current when the backend situation says otherwise', () => {
  const base = { nombre_puesto: 'PUESTO', unidad_organica: 'UNIDAD', origen: 'vinculo' }
  const vencido = { codigo: 'no_determinada', texto: 'No determinada: el plazo terminó el 31/12/2025 y no hay cese registrado' }
  assert.equal(puestoActual({ ...base, vinculos_vigentes: 0, situacion: vencido }).nota, `${vencido.texto}.`)
  assert.equal(puestoActual({ ...base, vinculos_vigentes: 0, situacion: { codigo: 'programado', texto: 'Inicio previsto el 04/01/2027' } }).nota, 'Inicio previsto el 04/01/2027.')
  assert.equal(puestoActual({ ...base, vinculos_vigentes: 1, situacion: { codigo: 'vigente', texto: 'Vigente a la fecha' } }).nota, null)
  assert.equal(puestoActual({ ...base, vinculos_vigentes: 2, situacion: { codigo: 'vigente', texto: 'Vigente a la fecha' } }).nota, '2 vínculos vigentes: se muestra el de inicio más reciente.')
  assert.equal(puestoActual({ nombre_puesto: 'ANTERIOR', origen: 'persona', vinculos_vigentes: 0, situacion: null }).nota, 'Sin vínculo vigente: dato registrado antes en la persona.')
})
