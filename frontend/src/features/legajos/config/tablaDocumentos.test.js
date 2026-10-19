import test from 'node:test'
import assert from 'node:assert/strict'
import { resumenTipoDocumento } from './tablaDocumentos.js'

test('resume documentos vigentes sin contar versiones sustituidas ni anuladas', () => {
  const result = resumenTipoDocumento({ documentos: [
    { estado: 'aceptado', fecha_emision: '2026-10-19', folio_inicio: 3, folio_fin: 5 },
    { estado: 'observado', subsanado: true, fecha_emision: '2026-10-20', folio_inicio: 1, folio_fin: 2 },
    { estado: 'anulado', folio_inicio: 6, folio_fin: 9 },
  ] })
  assert.deepEqual(result, { cantidad: 1, fecha: '2026-10-19', folios: 3, estado: 'aceptado' })
})

test('adapta valores anidados y no duplica los folios superpuestos', () => {
  const result = resumenTipoDocumento({ lista: [
    { values: { estado: 'cargado', fecha_emision: '2026-09-01', folio_inicio: 1, folio_fin: 3 } },
    { values: { estado: 'observado', fecha_emision: '2026-10-01', folio_inicio: 3, folio_fin: 4 } },
  ] })
  assert.deepEqual(result, { cantidad: 2, fecha: '2026-10-01', folios: 4, estado: 'observado' })
})

test('respeta los agregados del servidor y distingue información incompleta de cero', () => {
  assert.deepEqual(resumenTipoDocumento({ cantidad: 2, numero_folios: 7, ultima_fecha: '2026-10-19', estado: 'pendiente' }), { cantidad: 2, fecha: '2026-10-19', folios: 7, estado: 'pendiente' })
  assert.equal(resumenTipoDocumento({ documentos: 2 }).folios, null)
  assert.equal(resumenTipoDocumento({ lista: [{ values: { estado: 'cargado' } }] }).folios, null)
  assert.equal(resumenTipoDocumento({ exigido: null }).estado, 'por_determinar')
  assert.deepEqual(resumenTipoDocumento({ exigido: true }), { cantidad: 0, fecha: null, folios: 0, estado: 'falta' })
})

test('oculta las métricas de los tipos restringidos y no confunde recepción con aceptación', () => {
  assert.deepEqual(resumenTipoDocumento({ restringido: true, cantidad: 9, numero_folios: 30 }), { cantidad: null, fecha: null, folios: null, estado: 'restringido' })
  assert.equal(resumenTipoDocumento({ lista: [{ values: { estado: 'recepcionado' } }] }).estado, 'cargado')
  assert.equal(resumenTipoDocumento({ estado: 'recepcionado', documentos: 1 }).estado, 'recepcionado')
})
