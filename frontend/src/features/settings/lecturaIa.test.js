import assert from 'node:assert/strict'
import test from 'node:test'
import { fraseAcierto, MINIMO_PARA_VEREDICTO, ordenarCampos, tramos, veredicto } from './lecturaIa.js'

test('el veredicto depende del acierto y no se da con pocos datos', () => {
  assert.deepEqual(veredicto(92, 20), { texto: 'Confiable', tono: 'bien' })
  assert.deepEqual(veredicto(80, 20), { texto: 'Confiable', tono: 'bien' })
  assert.deepEqual(veredicto(70, 20), { texto: 'Revisar con cuidado', tono: 'aviso' })
  assert.deepEqual(veredicto(40, 20), { texto: 'Poco confiable', tono: 'mal' })
  assert.deepEqual(veredicto(100, MINIMO_PARA_VEREDICTO - 1), { texto: 'Pocos datos aún', tono: 'neutro' })
  assert.deepEqual(veredicto(null, 0), { texto: 'Sin datos', tono: 'neutro' })
})

test('la frase explica el porcentaje de cada 10 datos', () => {
  assert.equal(fraseAcierto(82), 'De cada 10 datos que completó la IA, 8 se guardaron sin cambios.')
  assert.equal(fraseAcierto(10), 'De cada 10 datos que completó la IA, 1 se guardó sin cambios.')
  assert.match(fraseAcierto(null), /Todavía no hay/)
})

test('primero los campos menos confiables y al final los que la IA nunca completó', () => {
  const filas = [
    { campo: 'emisor', propuestos: 10, acierto: 90, no_detectado: 0 },
    { campo: 'horas', propuestos: 0, acierto: null, no_detectado: 3 },
    { campo: 'fecha_emision', propuestos: 10, acierto: 50, no_detectado: 1 },
    { campo: 'cargo', propuestos: 0, acierto: null, no_detectado: 7 },
  ]
  assert.deepEqual(ordenarCampos(filas).map(fila => fila.campo), ['fecha_emision', 'emisor', 'cargo', 'horas'])
})

test('los tramos de la barra suman el total y omiten los vacíos', () => {
  const partes = tramos({ propuestos: 10, aceptado: 7, corregido: 3, borrado: 0 })
  assert.deepEqual(partes.map(parte => [parte.clave, parte.ancho]), [['aceptado', 70], ['corregido', 30]])
  assert.deepEqual(tramos({ propuestos: 0, aceptado: 0, corregido: 0, borrado: 0 }), [])
})
