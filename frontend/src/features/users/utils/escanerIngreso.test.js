import test from 'node:test'
import assert from 'node:assert/strict'
import { separarPropuestasIngreso } from './escanerIngreso.js'

test('las propuestas seleccionadas completan su formulario sin borrar otros valores', () => {
  const resultado = separarPropuestasIngreso([
    { clave: 'numero_documento_vinculo', valor: ' 015-2026-UNIQ ' },
    { clave: 'emisor', valor: 'Universidad de ejemplo' },
    { clave: 'fecha_emision', valor: '2026-10-21' },
  ])
  assert.deepEqual({ proceso_seleccion: 'CAS', ...resultado.vinculo }, { proceso_seleccion: 'CAS', numero_documento_vinculo: '015-2026-UNIQ' })
  assert.deepEqual(resultado.documento, {})
})

test('no permite campos ajenos al escaner ni valores vacios', () => {
  assert.deepEqual(separarPropuestasIngreso([{ clave: 'usuario_id', valor: 'otro' }, { clave: 'emisor', valor: ' ' }]), { vinculo: {}, documento: {} })
})
