import assert from 'node:assert/strict'
import test from 'node:test'
import { advanceOnEnter } from './advanceOnEnter.js'

// DOM mínimo simulado: solo implementa los selectores que usa advanceOnEnter.
function crearFormulario(definiciones) {
  const elementos = definiciones.map(({ tag, type = 'text', disabled = false, readonly = false, visible = true }) => ({
    tag, type, disabled, readonly, visible, enfocado: false,
    focus() { this.enfocado = true },
    getClientRects() { return this.visible ? [{}] : [] },
    compareDocumentPosition(otro) { return elementos.indexOf(otro) > elementos.indexOf(this) ? 4 : 2 },
    matches(selector) {
      const reglas = {
        'input, select': ['input', 'select'].includes(this.tag),
        'input[type="submit"], input[type="button"]': this.tag === 'input' && ['submit', 'button'].includes(this.type),
        ':disabled, [readonly], [type="hidden"]': this.disabled || this.readonly || this.type === 'hidden',
      }
      if (!(selector in reglas)) throw new Error(`Selector no simulado: ${selector}`)
      return reglas[selector]
    },
  }))
  const form = {
    querySelectorAll: () => elementos.filter(el => ['input', 'select', 'textarea'].includes(el.tag)),
    querySelector: () => elementos.find(el => el.tag === 'button' && el.type === 'submit' && !el.disabled) || null,
  }
  return { form, elementos }
}

function evento(form, target, extra = {}) {
  return { key: 'Enter', target, currentTarget: form, prevenido: false, preventDefault() { this.prevenido = true }, ...extra }
}

test('Enter en un campo pasa el foco al siguiente campo', () => {
  const { form, elementos: [dni, nombres] } = crearFormulario([{ tag: 'input' }, { tag: 'input' }])
  const e = evento(form, dni)
  advanceOnEnter(e)
  assert.equal(e.prevenido, true)
  assert.equal(nombres.enfocado, true)
})

test('salta campos deshabilitados, de solo lectura, ocultos o no visibles', () => {
  const { form, elementos } = crearFormulario([
    { tag: 'input' },
    { tag: 'input', disabled: true },
    { tag: 'input', readonly: true },
    { tag: 'input', type: 'hidden' },
    { tag: 'select', visible: false },
    { tag: 'select' },
  ])
  advanceOnEnter(evento(form, elementos[0]))
  assert.deepEqual(elementos.map(el => el.enfocado), [false, false, false, false, false, true])
})

test('en el último campo enfoca el botón de envío habilitado', () => {
  const { form, elementos: [campo, boton] } = crearFormulario([{ tag: 'input' }, { tag: 'button', type: 'submit' }])
  advanceOnEnter(evento(form, campo))
  assert.equal(boton.enfocado, true)
})

test('sin siguiente campo ni botón habilitado no falla', () => {
  const { form, elementos: [campo, boton] } = crearFormulario([{ tag: 'input' }, { tag: 'button', type: 'submit', disabled: true }])
  const e = evento(form, campo)
  assert.doesNotThrow(() => advanceOnEnter(e))
  assert.equal(e.prevenido, true)
  assert.equal(boton.enfocado, false)
})

test('desde un campo de solo lectura avanza al siguiente en orden del documento', () => {
  const { form, elementos: [, fijo, siguiente] } = crearFormulario([{ tag: 'input' }, { tag: 'input', readonly: true }, { tag: 'input' }])
  advanceOnEnter(evento(form, fijo))
  assert.equal(siguiente.enfocado, true)
})

test('ignora otras teclas, la composición IME, los textarea y los botones', () => {
  const { form, elementos: [campo, area, enviar, siguiente] } = crearFormulario([
    { tag: 'input' }, { tag: 'textarea' }, { tag: 'input', type: 'submit' }, { tag: 'input' },
  ])
  const casos = [
    evento(form, campo, { key: 'Tab' }),
    evento(form, campo, { nativeEvent: { isComposing: true } }),
    evento(form, area),
    evento(form, enviar),
  ]
  for (const e of casos) {
    advanceOnEnter(e)
    assert.equal(e.prevenido, false)
  }
  assert.equal(siguiente.enfocado, false)
})
