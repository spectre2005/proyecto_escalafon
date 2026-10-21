import assert from 'node:assert/strict'
import test from 'node:test'
import { PARAMETRO, PESTANAS, PREDETERMINADA, conSeccion, idPanel, idPestana, pestanaDe, tituloPestana } from './pestanas.js'

test('the five tabs, in order, with the service tab as default', () => {
  assert.deepEqual(PESTANAS.map(p => p.clave), ['servicio', 'administradores', 'datos', 'tecnico', 'auditoria'])
  assert.deepEqual(PESTANAS.map(p => p.titulo), ['Servicio y pagos', 'Administradores', 'Datos y contrato', 'Uso y respaldos', 'Auditoría'])
  assert.equal(PREDETERMINADA, 'servicio')
  assert.equal(PARAMETRO, 'seccion')
})

test('the URL value picks a tab; missing or unknown values fall back to the default', () => {
  for (const { clave } of PESTANAS) assert.equal(pestanaDe(clave), clave)
  for (const valor of [null, undefined, '', 'SERVICIO', 'pagos', 'auditoria ', '__proto__', 'toString']) assert.equal(pestanaDe(valor), 'servicio')
})

test('stable and unique ids for each tab and its panel', () => {
  const ids = PESTANAS.flatMap(p => [idPestana(p.clave), idPanel(p.clave)])
  assert.equal(new Set(ids).size, 10)
  assert.equal(idPestana('datos'), 'institucion-pestana-datos')
  assert.equal(idPanel('datos'), 'institucion-panel-datos')
  assert.equal(tituloPestana('tecnico'), 'Uso y respaldos')
  assert.equal(tituloPestana('otra'), '')
})

test('changing the tab keeps the other URL parameters and does not touch the original', () => {
  const original = new URLSearchParams('q=uni&seccion=datos&page=2')
  const siguiente = conSeccion(original, 'auditoria')
  assert.equal(siguiente.toString(), 'q=uni&seccion=auditoria&page=2')
  assert.equal(original.get('seccion'), 'datos')
  assert.equal(conSeccion(new URLSearchParams(''), 'tecnico').toString(), 'seccion=tecnico')
})
