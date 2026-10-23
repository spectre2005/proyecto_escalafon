import test from 'node:test'
import assert from 'node:assert/strict'
import { destinoDocumentoRequerido } from './destinoDocumentoRequerido.js'

test('dirige al documento en su sección o subsección', () => {
  const casos = [
    [1, 'S01_B_DOCUMENTO_IDENTIDAD', null, 'general#bloque-S01_B_DOCUMENTO_IDENTIDAD'],
    [2, 'S02_ADENDA', '2.2', 's02-vinculo#item-tipo1'],
    [3, 'FORMACION', null, 'seccion-03#item-tipo1'],
    [5, 'S05_B_GOCE_VACACIONAL', null, 's05-vacaciones#item-tipo1'],
    [7, 'PROGRESION', '7.2', 's07-progresion#item-tipo1'],
    [8, 'REC_CARTA_FELICITACION', null, 'reconocimientos#item-REC_CARTA_FELICITACION'],
    [8, 'SAN_RESOLUCION_SANCION', null, 'sanciones#item-SAN_RESOLUCION_SANCION'],
    [9, 'S09_C_AFILIACION_SINDICATO', null, 's09-afiliacion#item-tipo1'],
    [10, 'SST', '10.1', 's10-sst#item-tipo1'],
    [11, 'CESE', null, 's11-documentos#item-tipo1'],
  ]
  for (const [seccion, codigo, subseccion, destino] of casos) {
    assert.equal(destinoDocumentoRequerido({ usuario_id: 'persona1', tipo_documento: {
      id: 'tipo1', codigo, seccion_legajo_id: seccion, subseccion_legajo: { codigo: subseccion },
    } }), `/legajos/persona1?categoria=${destino}`)
  }
})

test('sin un tipo documental conserva el acceso general al legajo', () => {
  assert.equal(destinoDocumentoRequerido({ usuario_id: 'persona1' }), '/legajos/persona1')
})
