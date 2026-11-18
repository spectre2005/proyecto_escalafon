import assert from 'node:assert/strict'
import test from 'node:test'
import { motivoSinNivel, nivelesDe, nivelTrasCambiarGrupo, opcionesNivelCarrera, pruebaEnNoConDatos, pruebaNoAplica, puestoActual } from './seccion02.js'
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

// Escalas como llegan de /expediente-opciones (niveles): 276 técnico completo, 728 auxiliar y un nivel desactivado.
const escalas = [
  ...['STF', 'STE', 'STD', 'STC', 'STB', 'STA'].map((codigo, indice) => ({ codigo_ley: '276', grupo_ocupacional: 'tecnico', codigo, nombre: `Servidor Técnico ${codigo.slice(-1)}`, orden: indice + 1, activo: true })),
  ...['A-1', 'A-2', 'A-3'].map((codigo, indice) => ({ codigo_ley: '728', grupo_ocupacional: 'auxiliar', codigo, orden: indice + 1, activo: true })),
  { codigo_ley: '276', grupo_ocupacional: 'tecnico', codigo: 'STX', orden: 9, activo: false },
]

test('el nivel de carrera sale de la escala de la ley y el grupo, del ingreso al máximo', () => {
  assert.deepEqual(nivelesDe(escalas, '276', 'tecnico'), ['STF', 'STE', 'STD', 'STC', 'STB', 'STA'])
  assert.equal(opcionesNivelCarrera(escalas, '276', 'tecnico').STF, 'Servidor Técnico F (STF) · nivel de ingreso')
  assert.equal(opcionesNivelCarrera(escalas, '276', 'tecnico').STD, 'Servidor Técnico D (STD)')
  assert.equal(opcionesNivelCarrera(escalas, '276', 'tecnico').STA, 'Servidor Técnico A (STA) · nivel más alto')
  // Sin nombre registrado se muestra la sigla.
  assert.equal(opcionesNivelCarrera(escalas, '728', 'auxiliar')['A-2'], 'A-2')
  assert.deepEqual(Object.keys(opcionesNivelCarrera(escalas, '728', 'auxiliar')), ['A-1', 'A-2', 'A-3'])
  assert.deepEqual(opcionesNivelCarrera(escalas, '1057', 'tecnico'), {})
  // Un nivel desactivado no se ofrece, salvo que sea el ya guardado.
  assert.equal('STX' in opcionesNivelCarrera(escalas, '276', 'tecnico'), false)
  assert.equal(opcionesNivelCarrera(escalas, '276', 'tecnico', 'STX').STX, 'STX · desactivado')
})

test('dice por qué el nivel no se puede elegir', () => {
  assert.equal(motivoSinNivel(escalas, '1057', 'tecnico'), 'No aplica en CAS')
  assert.equal(motivoSinNivel(escalas, '30057', 'tecnico'), 'Sin escala registrada para esta ley')
  assert.equal(motivoSinNivel(escalas, undefined, 'tecnico'), 'Elige primero la ley')
  assert.equal(motivoSinNivel(escalas, '276', ''), 'Elige primero el grupo')
  assert.equal(motivoSinNivel(escalas, '276', 'profesional'), 'Sin niveles para este grupo')
  assert.equal(motivoSinNivel(escalas, '276', 'tecnico'), null)
})

test('al cambiar de ley o de grupo solo se conserva un nivel que siga en la escala activa', () => {
  assert.equal(nivelTrasCambiarGrupo(escalas, 'SPA', '276', 'tecnico'), '')
  assert.equal(nivelTrasCambiarGrupo(escalas, 'STB', '276', 'tecnico'), 'STB')
  assert.equal(nivelTrasCambiarGrupo(escalas, 'STB', '728', 'auxiliar'), '')
  assert.equal(nivelTrasCambiarGrupo(escalas, 'STX', '276', 'tecnico'), '')
  assert.equal(nivelTrasCambiarGrupo(escalas, 'STB', '1057', 'tecnico'), '')
})
