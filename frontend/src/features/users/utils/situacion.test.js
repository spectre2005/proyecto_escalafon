import assert from 'node:assert/strict'
import test from 'node:test'
import { situacionDe, situaciones, textoAcceso } from './situacion.js'

test('situacionDe devuelve el texto de cada situación laboral conocida', () => {
  assert.equal(situacionDe({ situacion: 'vinculo_vigente' }).texto, 'Con vínculo vigente')
  assert.equal(situacionDe({ situacion: 'sin_vinculo_vigente' }).texto, 'Sin vínculo vigente')
  assert.equal(situacionDe({ situacion: 'sin_legajo' }).texto, 'Sin legajo activo')
  for (const clave of Object.keys(situaciones)) assert.equal(situacionDe({ situacion: clave }), situaciones[clave])
})

test('situacionDe usa "Sin dato" para situaciones ausentes o desconocidas', () => {
  for (const user of [{}, { situacion: null }, { situacion: '' }, { situacion: 'VINCULO_VIGENTE' }, { situacion: 'otra' }]) {
    const resultado = situacionDe(user)
    assert.equal(resultado.texto, 'Sin dato')
    assert.ok(resultado.clase)
  }
})

test('cada situación tiene texto y clase de estilo', () => {
  for (const { texto, clase } of Object.values(situaciones)) {
    assert.ok(texto)
    assert.match(clase, /bg-/)
  }
})

test('textoAcceso depende solo del acceso al sistema', () => {
  assert.equal(textoAcceso(true), 'Con acceso')
  assert.equal(textoAcceso(false), 'Sin acceso')
  assert.equal(textoAcceso(undefined), 'Sin acceso')
})
