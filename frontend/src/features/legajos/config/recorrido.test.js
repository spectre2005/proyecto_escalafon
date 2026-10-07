import test from 'node:test'
import assert from 'node:assert/strict'
import { categoriaRecorrido, esCargaDocumento, puedeAvanzarAutomaticamente } from './recorrido.js'

test('Agregar ahora dirige a la pantalla y categoría existentes del documento', () => {
  assert.equal(categoriaRecorrido({ id: 1 }, { codigo: 'S01_C_ANTECEDENTES_PENALES' }), 's01-antecedentes')
  assert.equal(categoriaRecorrido({ id: 1 }, { codigo: 'S01_J_DNI_HIJOS_MENORES' }), 's01-familia')
  assert.equal(categoriaRecorrido({ id: 2 }, { subseccion: '2.4' }), 's02-prueba')
  assert.equal(categoriaRecorrido({ id: 3 }), 'seccion-03')
  assert.equal(categoriaRecorrido({ id: 4 }), 'seccion-04')
  assert.equal(categoriaRecorrido({ id: 5 }, { bloque: 'vacaciones' }), 's05-vacaciones')
  assert.equal(categoriaRecorrido({ id: 6 }), 'seccion-06')
  assert.equal(categoriaRecorrido({ id: 7 }, { subseccion: '7.2' }), 's07-progresion')
  assert.equal(categoriaRecorrido({ id: 8 }, { codigo: 'SAN_RESOLUCION_SANCION' }), 'sanciones')
  assert.equal(categoriaRecorrido({ id: 9 }, { bloque: 'afiliacion' }), 's09-afiliacion')
  assert.equal(categoriaRecorrido({ id: 10 }, { subseccion: '10.2' }), 's10-bienestar')
  assert.equal(categoriaRecorrido({ id: 11 }, { codigo: 'S11_E_ENTREGA_CARGO' }), 's11-documentos')
  assert.equal(categoriaRecorrido({ id: 12 }), null)
})

test('solo observa cargas de documentos de esta persona y conserva los endpoints existentes', () => {
  for (const url of ['/expedientes/persona/documentos', '/expedientes/persona/seccion-03/documentos', '/expedientes/persona/seccion-04/documentos', '/expedientes/persona/seccion-06/documentos', '/expedientes/persona/seccion-08/documentos', '/expedientes/persona/seccion-10/documentos']) {
    assert.equal(esCargaDocumento({ method: 'post', url }, 'persona'), true)
    assert.equal(esCargaDocumento({ method: 'get', url }, 'persona'), false)
  }
  assert.equal(esCargaDocumento({ method: 'post', url: '/expedientes/otra/documentos' }, 'persona'), false)
  assert.equal(esCargaDocumento({ method: 'post', url: '/expedientes/persona/documentos/id/recepcionar' }, 'persona'), false)
  assert.equal(esCargaDocumento({ method: 'post', url: '/expedientes/persona/recorrido' }, 'persona'), false)
})

test('espera al formulario enlazado y la confirmación del servidor antes de avanzar', () => {
  assert.equal(puedeAvanzarAutomaticamente({ puede_continuar: true, solo_opcionales: false }, false), true)
  assert.equal(puedeAvanzarAutomaticamente({ puede_continuar: true, solo_opcionales: false }, true), false)
  assert.equal(puedeAvanzarAutomaticamente({ puede_continuar: false, solo_opcionales: false }, false), false)
  assert.equal(puedeAvanzarAutomaticamente({ puede_continuar: true, solo_opcionales: true }, false), false)
  assert.equal(puedeAvanzarAutomaticamente(null, false), false)
})
