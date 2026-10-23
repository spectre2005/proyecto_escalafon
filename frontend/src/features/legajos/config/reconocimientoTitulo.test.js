import test from 'node:test'
import assert from 'node:assert/strict'
import { camposSeleccionados, seleccionInicial } from './reconocimientoTitulo.js'

const niveles = { titulo_profesional: 'Título profesional', tecnico: 'Técnico' }

test('marca todos los datos detectados y permite desmarcar los que no se aplicarán', () => {
  const campos = { nombre: 'Ingeniero', emisor: 'Universidad', pais: 'México' }
  const seleccion = seleccionInicial(campos, niveles)
  assert.deepEqual(seleccion, { nombre: true, emisor: true, pais: true })
  assert.deepEqual(camposSeleccionados(campos, { ...seleccion, nombre: false }, niveles), { emisor: 'Universidad', pais: 'México' })
})

test('no cambia identidad, autenticidad ni nivel incompatible con el tipo elegido', () => {
  const campos = { titular_nombre: 'Otra persona', titular_documento: '10000000', fiscalizado: true, nivel: 'doctorado', nombre: 'Doctor en Ciencias' }
  const seleccion = seleccionInicial(campos, niveles)
  assert.deepEqual(seleccion, { nombre: true })
  assert.deepEqual(camposSeleccionados(campos, Object.fromEntries(Object.keys(campos).map(key => [key, true])), niveles), { nombre: 'Doctor en Ciencias' })
})

test('una lectura sin datos no inventa valores', () => {
  assert.deepEqual(seleccionInicial({}, niveles), {})
  assert.deepEqual(camposSeleccionados({}, { pais: true, numero_registro: true }, niveles), {})
})


test('permite aplicar el nivel básico y la serie sin modificar la identidad', () => {
  const campos = { titular_nombre: 'Persona de Prueba', nombre: 'Certificado de Estudios', nivel: 'educacion_basica', numero_diploma: 'H-000001' }
  const nivelesBasicos = { educacion_basica: 'Educación básica' }
  const seleccion = seleccionInicial(campos, nivelesBasicos)
  assert.deepEqual(camposSeleccionados(campos, seleccion, nivelesBasicos), { nombre: 'Certificado de Estudios', nivel: 'educacion_basica', numero_diploma: 'H-000001' })
})
