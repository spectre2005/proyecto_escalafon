import assert from 'node:assert/strict'
import test from 'node:test'
import { destinoMotivo } from './destinosVencimiento.js'

const persona = { usuario_id: 'persona-1', nombre: 'ANA FICTICIA' }

test('each reason links to its own record inside the legajo section', () => {
  const casos = {
    contrato: '/legajos/persona-1?categoria=s02-vinculo#registro-r1',
    periodo_prueba: '/legajos/persona-1?categoria=s02-prueba#registro-r1',
    discapacidad: '/legajos/persona-1?categoria=s01-discapacidad#registro-r1',
    colegiatura: '/legajos/persona-1?categoria=seccion-03#registro-r1',
    examen_medico: '/legajos/persona-1?categoria=s10-sst#registro-r1',
    ratificacion: '/legajos/persona-1?categoria=s07-progresion#registro-r1',
    desplazamiento: '/legajos/persona-1?categoria=s07-desplazamiento#registro-r1',
  }
  for (const [origen, ruta] of Object.entries(casos)) assert.equal(destinoMotivo(persona, { origen, id: 'r1' }).ruta, ruta)
})

test('the ID card, the requirements and unknown reasons', () => {
  assert.deepEqual(destinoMotivo(persona, { origen: 'documento', id: 'd1' }), { ruta: '/legajos/persona-1?categoria=general#bloque-S01_B_DOCUMENTO_IDENTIDAD', texto: 'Ir al DNI' })
  assert.deepEqual(destinoMotivo(persona, { origen: 'requerimiento', id: 'q1' }), { ruta: '/requerimientos?q=ANA%20FICTICIA', texto: 'Ver requerimientos' })
  assert.equal(destinoMotivo(persona, { origen: 'otro', id: 'x' }), null)
  assert.equal(destinoMotivo(persona, { origen: 'contrato' }), null)
})
