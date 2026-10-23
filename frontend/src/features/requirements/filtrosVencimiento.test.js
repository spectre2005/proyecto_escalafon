import assert from 'node:assert/strict'
import test from 'node:test'
import { aplicarDias, conFiltro, diasValidos, opcionesEstado } from './filtrosVencimiento.js'

test('changing a filter keeps the others in the address and goes back to page 1', () => {
  const params = new URLSearchParams('q=ana&origen=contrato&page=3&dias=60')
  assert.equal(conFiltro(params, 'estado', 'vencido').toString(), 'q=ana&origen=contrato&dias=60&estado=vencido')
  assert.equal(conFiltro(params, 'origen', '').toString(), 'q=ana&dias=60')
  // El original no cambia (se comparte el enlace tal cual).
  assert.equal(params.get('page'), '3')
})

test('next days apply only valid, different values on confirmation', () => {
  const params = new URLSearchParams('origen=contrato&page=2')
  assert.equal(aplicarDias(params, '60').toString(), 'origen=contrato&dias=60')
  assert.equal(aplicarDias(params, '30'), null)
  for (const invalido of ['0', '366', '7.5', 'abc', '']) assert.equal(aplicarDias(params, invalido), null)
  assert.equal(aplicarDias(new URLSearchParams('dias=60'), '60'), null)
  assert.equal(diasValidos(' 15 '), 15)
})

test('status options: «Solo por vencer» and requirements only with their permission', () => {
  assert.deepEqual(opcionesEstado(true), [['vencido', 'Vencido'], ['fuera_de_plazo', 'Requerimiento fuera de plazo'], ['por_vencer', 'Solo por vencer']])
  assert.deepEqual(opcionesEstado(false).map(([clave]) => clave), ['vencido', 'por_vencer'])
})
