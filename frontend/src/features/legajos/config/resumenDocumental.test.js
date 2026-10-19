import assert from 'node:assert/strict'
import test from 'node:test'
import { resumenDocumental } from './resumenDocumental.js'

test('common document lists summarize current evidence without old or annulled versions', () => {
  assert.deepEqual(resumenDocumental({ documentos: 2, lista: [
    { values: { fecha_emision: '2026-09-15', folio_inicio: 1, folio_fin: 3 } },
    { values: { fecha_emision: '2026-10-10', folio_inicio: 4, folio_fin: 4 } },
    { subsanado: true, values: { fecha_emision: '2026-10-15', folio_inicio: 5, folio_fin: 10 } },
    { values: { estado: 'anulado', fecha_emision: '2026-10-18', folio_inicio: 11, folio_fin: 15 } },
  ] }), { cantidad: 2, ultimaFecha: '2026-10-10', numeroFolios: 4 })
})

test('specialized sections use the same summary with their flat document data', () => {
  assert.deepEqual(resumenDocumental({ cantidad: 1, documentos: [
    { fecha_emision: '2026-10-01', folio_inicio: 2, folio_fin: 5 },
    { anulado: true, fecha_emision: '2026-10-19', folio_inicio: 6, folio_fin: 8 },
  ] }), { cantidad: 1, ultimaFecha: '2026-10-01', numeroFolios: 4 })
})

test('a type with no evidence has no date or folios', () => {
  assert.deepEqual(resumenDocumental({ lista: [] }), { cantidad: 0, ultimaFecha: null, numeroFolios: 0 })
})

test('explicit summaries retain zero folios and missing dates are not invented', () => {
  assert.deepEqual(resumenDocumental({ cantidad: 1, ultima_fecha: '2026-10-02', numero_folios: 0, documentos: [{}] }),
    { cantidad: 1, ultimaFecha: '2026-10-02', numeroFolios: 0 })
})
