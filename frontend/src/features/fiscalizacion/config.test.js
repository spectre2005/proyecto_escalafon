import assert from 'node:assert/strict'
import test from 'node:test'
import { estados, fecha, nombreDocumento, persona, resultados, resultadosFuente, resumenAlcance } from './config.js'

test('technical results are distinct from administrative conclusions', () => {
  assert.equal(resultadosFuente.integridad_coincide, 'Huella coincidente')
  assert.equal(resultados.integridad_coincide, undefined)
  assert.equal(resultados.firma_valida, undefined)
  assert.equal(estados.cerrado, 'Cerrado')
})
test('scope summary counts evaluated documents independently from technical checks', () => {
  assert.deepEqual(resumenAlcance([{ resultado: 'pendiente', verificaciones: [{ resultado_fuente: 'firma_valida' }] }, { resultado: 'conforme' }, { resultado: 'observado' }, { resultado: 'no_concluyente' }]), { total: 4, evaluados: 3, alertas: 1 })
})
test('document labels preserve section and folios and tolerate missing data', () => {
  assert.equal(nombreDocumento({ nombre: 'Título', seccion_legajo_id: 3, folio_inicio: 1, folio_fin: 2 }), 'Título · Sección 3 · Folios 1–2')
  assert.match(nombreDocumento(null), /Documento/)
  assert.equal(fecha('2026-10-22T00:00:00Z'), '22/10/2026')
  assert.equal(persona({ nombres: 'Ana', apellido_paterno: 'Pérez' }), 'Ana Pérez')
})
