import test from 'node:test'
import assert from 'node:assert/strict'
import { camposEscaner, datosAplicables, etiquetaCampo, LECTURA_EN_CURSO, motivosDescarte, resultadoLectura, sugerenciasAplicables, textoAvance, valorLegible } from './escanerCampos.js'

test('solo aplica campos permitidos y conserva la edición realizada durante la lectura', () => {
  const recibidos = { emisor: { valor: 'Universidad', evidencia: 'Universidad' }, fecha_emision: { valor: '2026-10-01', evidencia: '1 de octubre de 2026' }, folio_inicio: { valor: '99', evidencia: '99' }, anios: { valor: '5', evidencia: '5 años' } }
  assert.deepEqual(datosAplicables(recibidos, { emisor: '', fecha_emision: '' }, { emisor: 'Edición manual', fecha_emision: '' }, ['emisor', 'fecha_emision', 'folio_inicio']), { fecha_emision: '2026-10-01' })
})

test('ignora valores vacíos, sin evidencia y campos de otra sección', () => {
  assert.deepEqual(datosAplicables({ emisor: { valor: '' }, cargo: { valor: 'Director', evidencia: 'Director' }, nombre: { valor: 'Resolución' } }, {}, {}, ['emisor', 'nombre']), {})
})

test('explica por qué se descartó un dato y omite los campos de otra sección', () => {
  assert.deepEqual(motivosDescarte({ fecha_emision: 'fecha_no_coincide', folio_inicio: 'cita_no_encontrada', cargo: 'desconocido' }, ['fecha_emision', 'cargo']), [
    { campo: 'fecha_emision', motivo: 'la fecha no coincide con la cita' },
    { campo: 'cargo', motivo: 'no se pudo comprobar' },
  ])
})

test('una sugerencia no reemplaza lo escrito ni se ofrece fuera del formulario', () => {
  const sugerencias = { fecha_emision: { valor: '2024-08-12', evidencia: '12.VIII.2024' }, emisor: { valor: 'Oficina', evidencia: 'Oficina' }, cargo: { valor: 'Docente', evidencia: 'Docente' }, nombre: { valor: ' ', evidencia: 'x' } }
  assert.deepEqual(Object.keys(sugerenciasAplicables(sugerencias, { fecha_emision: '', emisor: 'Ya escrito', nombre: '' }, ['fecha_emision', 'emisor', 'nombre'])), ['fecha_emision'])
})

test('lo sugerido no se repite como descartado y las fechas se leen como en un documento', () => {
  assert.deepEqual(motivosDescarte({ fecha_emision: 'fecha_no_coincide', cargo: 'es_el_titular' }, ['fecha_emision', 'cargo'], { fecha_emision: {} }), [{ campo: 'cargo', motivo: 'es el nombre de la persona' }])
  assert.equal(valorLegible('2024-08-12'), '12/08/2024')
  assert.equal(valorLegible('Docente'), 'Docente')
})

test('resultado de la lectura: aceptado, corregido, borrado, sugerencia usada y no detectado, sin enviar valores', () => {
  const resultado = resultadoLectura({
    pedidos: ['emisor', 'fecha_emision', 'num_resolucion', 'nombre', 'horas', 'cargo'],
    propuestos: { emisor: 'Universidad  de Ejemplo', fecha_emision: '2026-01-02', cargo: 'Director' },
    sugeridos: { nombre: 'Resolución rectoral' },
    finales: { emisor: 'UNIVERSIDAD DE EJEMPLO ', fecha_emision: '2026-01-03', cargo: '', nombre: 'RESOLUCIÓN RECTORAL', num_resolucion: '012-2026', horas: '' },
  })
  assert.deepEqual(resultado, { emisor: 'aceptado', fecha_emision: 'corregido', cargo: 'borrado', nombre: 'sugerencia_usada', num_resolucion: 'no_detectado' })
  assert.ok(Object.values(resultado).every(valor => ['aceptado', 'corregido', 'borrado', 'sugerencia_usada', 'no_detectado'].includes(valor)))
})

test('una sugerencia distinta de lo guardado cuenta como no detectado', () => {
  assert.deepEqual(resultadoLectura({ pedidos: ['nombre'], sugeridos: { nombre: 'A' }, finales: { nombre: 'B' } }), { nombre: 'no_detectado' })
  assert.deepEqual(resultadoLectura({ pedidos: ['nombre'], finales: {} }), {})
})

test('el avance de la lectura se describe por estado y página', () => {
  assert.equal(textoAvance({ estado: 'en_espera' }), 'En espera: hay otra lectura en curso…')
  assert.equal(textoAvance({ estado: 'leyendo', pagina_actual: 4, paginas_leer: 12 }), 'Leyendo página 4 de 12…')
  assert.equal(textoAvance({ estado: 'leyendo' }), 'Leyendo el PDF…')
  assert.equal(textoAvance({ estado: 'interpretando' }), 'La IA está interpretando el texto…')
  assert.deepEqual(LECTURA_EN_CURSO, ['en_espera', 'leyendo', 'interpretando'])
})

test('los campos se nombran como los lee una persona', () => {
  assert.equal(etiquetaCampo('fecha_emision'), 'Fecha de emisión')
  assert.equal(etiquetaCampo('num_resolucion'), 'N.º de resolución')
  assert.equal(etiquetaCampo('campo_nuevo'), 'campo nuevo')
  // Todo campo del lector tiene nombre propio (empieza en mayúscula), no la clave técnica.
  for (const campo of camposEscaner) assert.match(etiquetaCampo(campo), /^[A-ZÁÉÍÓÚ]/, campo)
})
