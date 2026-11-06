import test from 'node:test'
import assert from 'node:assert/strict'
import { buscarTemas, etiquetaOrigen, temasDePantalla, ultimoTema } from './guiaBusqueda.js'

const temas = [
  { id: 'subir', titulo: 'Subir un documento', ruta: '/legajos', palabras: ['pdf', 'adjuntar'], pasos: ['Pulsa «Subir documento».'] },
  { id: 'reportes', titulo: 'Exportar un reporte', ruta: '/reportes', palabras: ['excel'], pasos: ['Usa «Exportar Excel».'] },
]

test('encuentra por palabra clave, sin tildes y con palabras parecidas', () => {
  assert.deepEqual(buscarTemas(temas, '¿Cómo adjunto un PDF?').map(tema => tema.id), ['subir'])
  assert.deepEqual(buscarTemas(temas, 'exportación a excel').map(tema => tema.id), ['reportes'])
})

test('sin palabras útiles o sin coincidencias no devuelve nada', () => {
  assert.deepEqual(buscarTemas(temas, '¿cómo puedo?'), [])
  assert.deepEqual(buscarTemas(temas, 'clima de mañana'), [])
})

test('los temas de la pantalla incluyen sus subrutas, pero no rutas parecidas', () => {
  assert.deepEqual(temasDePantalla(temas, '/legajos/abc').map(tema => tema.id), ['subir'])
  assert.deepEqual(temasDePantalla(temas, '/legajos-otros'), [])
})

test('dice de dónde salió la respuesta y solo la marca si viene de la IA o la guía', () => {
  assert.equal(etiquetaOrigen('ia'), 'Respuesta de la IA')
  assert.equal(etiquetaOrigen('guia'), 'Según la guía')
  assert.equal(etiquetaOrigen('sistema'), null)
})

test('sigue el último tema conversado, aunque el último mensaje no tenga ninguno', () => {
  assert.equal(ultimoTema([{ temas: [{ id: 'a' }] }, { temas: [{ id: 'b' }] }, { temas: [] }, { rol: 'usuario' }]), 'b')
  assert.equal(ultimoTema([{ rol: 'usuario' }]), null)
})

test('una palabra común a muchos temas no decide el resultado', () => {
  const muchos = ['a', 'b', 'c', 'd'].map(id => ({ id, titulo: `Legajo ${id}`, ruta: '/legajos', palabras: ['legajo'], pasos: ['Abre el legajo.'] }))
  const buscados = [...muchos, { id: 'cert', titulo: 'Cargar', ruta: '/legajos', palabras: ['certificado'], pasos: ['Abre el legajo.'] }]
  assert.equal(buscarTemas(buscados, 'certificado del legajo')[0].id, 'cert')
})
