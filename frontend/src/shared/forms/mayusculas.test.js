import assert from 'node:assert/strict'
import test from 'node:test'
import { activarMayusculas, aMayusculas, convierteAMayusculas } from './mayusculas.js'

// Campo mínimo: ancestros como lista de { selector, texto } (texto solo para la etiqueta que lo envuelve).
function campo({ tagName = 'INPUT', type = 'text', atributos = {}, ancestros = [], ...resto } = {}) {
  return {
    tagName, type, readOnly: false, disabled: false, name: '', ...resto,
    getAttribute: nombre => atributos[nombre] ?? null,
    closest: selector => ancestros.find(ancestro => selector.split(',').some(parte => parte.trim() === ancestro.selector)) || null,
  }
}

test('uppercases Spanish text including ñ and accents', () => {
  assert.equal(aMayusculas('josé peña núñez'), 'JOSÉ PEÑA NÚÑEZ')
})

test('only short text inputs are converted', () => {
  assert.equal(convierteAMayusculas(campo()), true)
  assert.equal(convierteAMayusculas(campo({ tagName: 'TEXTAREA', type: 'textarea' })), false)
  for (const type of ['email', 'password', 'search', 'date', 'number', 'tel', 'url', 'file', 'checkbox']) {
    assert.equal(convierteAMayusculas(campo({ type })), false, type)
  }
  assert.equal(convierteAMayusculas(campo({ readOnly: true })), false)
  assert.equal(convierteAMayusculas(campo({ disabled: true })), false)
  assert.equal(convierteAMayusculas(null), false)
})

test('marked fields, visible passwords and searches keep what is typed', () => {
  assert.equal(convierteAMayusculas(campo({ ancestros: [{ selector: '[data-sin-mayusculas]' }] })), false)
  assert.equal(convierteAMayusculas(campo({ atributos: { autocomplete: 'current-password' } })), false)
  assert.equal(convierteAMayusculas(campo({ atributos: { autocomplete: 'off' } })), true)
  assert.equal(convierteAMayusculas(campo({ ancestros: [{ selector: '[role="search"]' }] })), false)
  assert.equal(convierteAMayusculas(campo({ name: 'q' })), false)
  assert.equal(convierteAMayusculas(campo({ atributos: { 'aria-label': 'Buscar por nombre o DNI' } })), false)
  assert.equal(convierteAMayusculas(campo({ ancestros: [{ selector: 'label', textContent: 'Buscar por DNI, nombre o correo' }] })), false)
  assert.equal(convierteAMayusculas(campo({ ancestros: [{ selector: 'label', textContent: 'Apellido paterno' }] })), true)
})

function documentoFalso() {
  const escritos = []
  class HTMLInputElement {}
  Object.defineProperty(HTMLInputElement.prototype, 'value', { set(valor) { escritos.push(valor); this.valorNativo = valor } })
  class Event { constructor(tipo, opciones) { Object.assign(this, { tipo, ...opciones }) } }
  const oyentes = {}
  return {
    escritos, oyentes, defaultView: { HTMLInputElement, Event },
    addEventListener: (tipo, fn, captura) => { assert.equal(captura, true); oyentes[tipo] = fn },
    removeEventListener: tipo => { delete oyentes[tipo] },
  }
}

test('typing writes the uppercase value with the native setter and keeps the caret', () => {
  const documento = documentoFalso()
  const quitar = activarMayusculas(documento)
  const seleccion = []
  const objetivo = campo({ value: 'perez', selectionStart: 2, selectionEnd: 2, setSelectionRange: (a, b) => seleccion.push([a, b]) })
  documento.oyentes.input({ target: objetivo, isComposing: false })
  assert.deepEqual(documento.escritos, ['PEREZ'])
  assert.deepEqual(seleccion, [[2, 2]])
  documento.oyentes.input({ target: { ...objetivo, value: 'PEREZ' }, isComposing: false })
  documento.oyentes.input({ target: campo({ type: 'email', value: 'ana@x.pe' }), isComposing: false })
  assert.deepEqual(documento.escritos, ['PEREZ'])
  quitar()
  assert.deepEqual(Object.keys(documento.oyentes), [])
})

test('composition waits until it ends and then notifies React with an input event', () => {
  const documento = documentoFalso()
  activarMayusculas(documento)
  const avisos = []
  const objetivo = campo({ value: 'josé', selectionStart: 4, selectionEnd: 4, setSelectionRange: () => {}, dispatchEvent: evento => avisos.push(evento) })
  documento.oyentes.input({ target: objetivo, isComposing: true })
  assert.deepEqual(documento.escritos, [])
  documento.oyentes.compositionend({ target: objetivo })
  assert.deepEqual(documento.escritos, ['JOSÉ'])
  assert.equal(avisos.length, 1)
  assert.equal(avisos[0].tipo, 'input')
  assert.equal(avisos[0].bubbles, true)
})
