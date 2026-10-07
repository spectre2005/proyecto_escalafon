import test from 'node:test'
import assert from 'node:assert/strict'
import { relacionesTitular, relacionTitularTexto } from './documentos.js'

test('las nuevas cargas ofrecen las siete relaciones en el orden solicitado', () => {
  assert.deepEqual(Object.values(relacionesTitular), ['El propio servidor', 'Cónyuge', 'Conviviente', 'Hijo / Hija', 'Padre / Madre', 'Hermano / Hermana', 'Otro'])
})

test('la lectura distingue el valor heredado y muestra el detalle de otro', () => {
  assert.equal(relacionTitularTexto({ relacion_titular: 'conyuge_concubino' }), 'Cónyuge o concubino(a) (valor anterior)')
  assert.equal(relacionTitularTexto({ relacion_titular: 'otro', relacion_titular_otro: 'Abuelo' }), 'Otro: Abuelo')
  assert.equal(relacionTitularTexto({ relacion_titular: 'otro' }), 'Otro')
})
