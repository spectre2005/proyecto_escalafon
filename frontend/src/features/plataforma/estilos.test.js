import assert from 'node:assert/strict'
import test from 'node:test'
import { ENTRADA, TARJETA, boton } from './estilos.js'

test('buttons share one shape and differ only by type and size', () => {
  assert.equal(boton(), 'rounded-xl px-4 py-2 text-sm border border-slate-200 bg-white text-slate-700')
  assert.equal(boton('principal'), 'rounded-xl px-4 py-2 text-sm bg-teal-700 font-semibold text-white')
  assert.equal(boton('peligro'), 'rounded-xl px-4 py-2 text-sm bg-rose-700 font-semibold text-white')
  assert.equal(boton('peligroContorno', 'pequeno'), 'rounded-xl px-3 py-1.5 text-xs border border-rose-200 bg-white font-semibold text-rose-700')
  // «campo»: a la altura de un campo de formulario (ENTRADA usa py-2.5).
  assert.match(boton('principal', 'campo'), /py-2\.5/)
  assert.match(ENTRADA, /py-2\.5/)
  for (const tipo of ['principal', 'secundario', 'peligro', 'peligroContorno']) {
    for (const tamano of ['normal', 'campo', 'pequeno']) assert.match(boton(tipo, tamano), /^rounded-xl /)
  }
})

test('the primary button is teal everywhere, never the old dark variant', () => {
  assert.doesNotMatch(boton('principal'), /slate-900/)
  assert.doesNotMatch(boton('principal', 'campo'), /slate-900/)
})

test('an unknown button type or size fails loudly instead of rendering without style', () => {
  assert.throws(() => boton('enlace'), /Botón desconocido/)
  assert.throws(() => boton('principal', 'grande'), /Botón desconocido/)
})

test('fields keep the disabled style and cards keep the panel look', () => {
  assert.match(ENTRADA, /disabled:bg-slate-50/)
  assert.equal(TARJETA, 'rounded-2xl border border-slate-200 bg-white p-6')
})
