import test from 'node:test'
import assert from 'node:assert/strict'
import { clasificarTipos, depurarSeleccion } from './tiposAplicables.js'

const ana = [
  { id: 'basicos', nombre: 'Certificado oficial de estudios básicos', no_aplica: 'Corresponde solo si el puesto exige estudios básicos…', por_definir: null },
  { id: 'grado', nombre: 'Título de grado', no_aplica: null, por_definir: null },
  { id: 'djbr', nombre: 'Declaración jurada de bienes y rentas', no_aplica: null, por_definir: 'Falta definir si corresponde: «Obligado…» está sin responder en 2.2.' },
]
const luis = [
  { id: 'basicos', nombre: 'Certificado oficial de estudios básicos', no_aplica: null, por_definir: null },
  { id: 'grado', nombre: 'Título de grado', no_aplica: null, por_definir: null },
  { id: 'djbr', nombre: 'Declaración jurada de bienes y rentas', no_aplica: 'Corresponde solo para obligados…', por_definir: null },
]

test('solo se pueden seleccionar los tipos que corresponden; los por definir siguen disponibles con su aviso', () => {
  const { seleccionables, noAplican } = clasificarTipos(ana)
  assert.deepEqual(seleccionables.map(t => t.id), ['grado', 'djbr'])
  assert.deepEqual(noAplican.map(t => t.id), ['basicos'])
  assert.match(seleccionables[1].por_definir, /Falta definir/)
})

test('cambiar de persona actualiza las opciones y quita la selección que dejó de corresponder', () => {
  assert.deepEqual(clasificarTipos(luis).seleccionables.map(t => t.id), ['basicos', 'grado'])
  assert.deepEqual(depurarSeleccion(['grado', 'djbr'], luis), { seleccion: ['grado'], quitados: ['djbr'] })
  assert.deepEqual(depurarSeleccion(['basicos', 'grado'], ana), { seleccion: ['grado'], quitados: ['basicos'] })
})

test('un tipo que la persona no tiene en su lista tampoco se conserva', () => {
  assert.deepEqual(depurarSeleccion(['otro'], ana), { seleccion: [], quitados: ['otro'] })
  assert.deepEqual(depurarSeleccion([], []), { seleccion: [], quitados: [] })
})
