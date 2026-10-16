import assert from 'node:assert/strict'
import test from 'node:test'
import { clavePendiente, lugarPendiente, motivoSinRespuesta, preguntasPerfil, solicitudRespuesta } from './perfilPendiente.js'

const persona = { bandera: 'tiene_derechohabientes', ambito: 'persona' }
const discapacidad = { bandera: 'con_discapacidad', ambito: 'persona' }
const puesto = { bandera: 'obligado_djbr', ambito: 'vinculo', vinculo_id: 'v-1', puesto: 'Especialista' }
const todo = { editar: true, sensibles: true, discapacidad: true, legajoActivo: true }

test('there is a short question for each of the six conditions', () => {
  assert.deepEqual(Object.keys(preguntasPerfil).sort(), ['con_discapacidad', 'exige_colegiatura', 'exige_estudios_basicos', 'obligado_djbr', 'tiene_derechohabientes', 'tiene_periodo_prueba'])
})

test('each answer goes to the record that stores it', () => {
  assert.deepEqual(solicitudRespuesta('u-1', persona, true), { url: '/expedientes/u-1', data: { tiene_derechohabientes: true } })
  assert.deepEqual(solicitudRespuesta('u-1', puesto, false), { url: '/expedientes/u-1/vinculos-laborales/v-1', data: { obligado_djbr: false } })
  assert.equal(lugarPendiente(persona), 'Datos de la persona')
  assert.equal(lugarPendiente(puesto), 'Vínculo laboral: Especialista')
  assert.notEqual(clavePendiente(puesto), clavePendiente({ ...puesto, vinculo_id: 'v-2' }))
})

test('only yes or no are sent: unanswered is never turned into no', () => {
  for (const valor of [null, undefined, '', 0]) assert.throws(() => solicitudRespuesta('u-1', persona, valor))
})

test('the same permissions as the backend decide who can answer', () => {
  assert.equal(motivoSinRespuesta(persona, todo), null)
  assert.equal(motivoSinRespuesta(puesto, todo), null)
  assert.match(motivoSinRespuesta(puesto, { ...todo, editar: false }), /no puede editar/)
  assert.match(motivoSinRespuesta(persona, { ...todo, sensibles: false }), /datos sensibles/)
  assert.equal(motivoSinRespuesta(puesto, { ...todo, sensibles: false }), null)
  assert.match(motivoSinRespuesta(discapacidad, { ...todo, discapacidad: false }), /administración de la ORH/)
  assert.equal(motivoSinRespuesta(persona, { ...todo, discapacidad: false }), null)
  assert.match(motivoSinRespuesta(puesto, { ...todo, legajoActivo: false }), /legajo activo/)
})
