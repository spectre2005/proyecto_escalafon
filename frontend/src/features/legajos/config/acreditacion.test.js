import assert from 'node:assert/strict'
import test from 'node:test'
import { aplicarPrecarga, CAMPOS_PRECARGA, conValor, precargaDesdeDocumento, precargaDesdeRegistro, reemplazarPropuesta, titularFamiliar } from './acreditacion.js'
import { agruparTipos, cantidadDocumentos, indiceNoAplica, tiposQueAcreditan } from './aplicacion.js'

const tipo = (codigo, formulario_datos, extra = {}) => ({ codigo, formulario_datos, ...extra })

test('the record proposes the resolution number only when it is the same document', () => {
  const vinculo = { numero_documento_vinculo: 'RES-100-2026' }
  for (const codigo of ['S02_C_RESOLUCION_DESIGNACION', 'S02_E_RESOLUCION_CARRERA', 'S02_F_CONTRATO']) {
    assert.deepEqual(precargaDesdeRegistro(tipo(codigo, 'vinculos_laborales'), vinculo), { num_resolucion: 'RES-100-2026' })
  }
  // La renovación puede tener otro número: no se propone.
  assert.deepEqual(precargaDesdeRegistro(tipo('S02_D_RESOLUCION_RENOVACION', 'vinculos_laborales'), vinculo), {})
  assert.deepEqual(precargaDesdeRegistro(tipo('S02_G_ADENDA', 'vinculo_adendas'), { numero: 'AD-2' }), { num_resolucion: 'AD-2' })
  assert.deepEqual(precargaDesdeRegistro(tipo('S07_72A_RESOLUCION_PROGRESION', 'progresiones_carrera'), { numero_resolucion: 'R-7' }), { num_resolucion: 'R-7' })
  assert.deepEqual(precargaDesdeRegistro(tipo('S07_72A_RESOLUCION_PROGRESION', 'ratificaciones_docentes'), { numero_resolucion: 'R-8', fecha_resolucion: '2025-03-01' }),
    { num_resolucion: 'R-8', fecha_emision: '2025-03-01' })
  // Una fecha futura no vale como emisión: no se propone.
  assert.deepEqual(precargaDesdeRegistro(tipo('x', 'ratificaciones_docentes'), { numero_resolucion: 'R-9', fecha_resolucion: '2999-01-01' }), { num_resolucion: 'R-9' })
  assert.deepEqual(precargaDesdeRegistro(tipo('S11_A_TERMINO_DESIGNACION', 'desvinculaciones', { principal: true }), { numero_resolucion: 'C-1' }), { num_resolucion: 'C-1' })
  assert.deepEqual(precargaDesdeRegistro(tipo('S11_E_ENTREGA_CARGO', 'desvinculaciones', { principal: false }), { numero_resolucion: 'C-1' }), {})
  assert.deepEqual(precargaDesdeRegistro(tipo('S05_A_APRUEBA_LICENCIA_PERMISO', 'licencias_permisos'), { numero_documento: 'MEM-3' }), { num_resolucion: 'MEM-3' })
  assert.deepEqual(precargaDesdeRegistro(tipo('S07_73B_DESIGNACION', 'desplazamientos'), { numero_documento: '' }), {})
  assert.deepEqual(precargaDesdeRegistro(tipo('S01_C_ANTECEDENTES_PENALES', 'declaraciones_verificaciones'), { fecha: '2026-01-10' }), { fecha_emision: '2026-01-10' })
  assert.deepEqual(precargaDesdeRegistro(tipo('S09_A_CONTROVERSIAS_INDIVIDUALES', 'controversias'), { fecha_resolucion: '2026-01-10' }), {})
  assert.deepEqual(precargaDesdeRegistro(null, {}), {})
})

test('a family member document takes the family member as holder, not the servant', () => {
  const hijo = { apellidos_nombres: 'Quispe Rojas, Ana', numero_doc_identidad: '71234567', parentesco: 'hijo' }
  assert.deepEqual(precargaDesdeRegistro(tipo('S01_J_DNI_HIJOS_MENORES', 'familiares'), hijo),
    { titular_nombre: 'Quispe Rojas, Ana', titular_documento: '71234567', relacion_titular: 'hijo' })
  assert.equal(titularFamiliar({ parentesco: 'concubino' }).relacion_titular, 'conyuge_concubino')
  assert.equal(titularFamiliar({ parentesco: 'conyuge' }).relacion_titular, 'conyuge_concubino')
  const familiares = [{ id: 'f1', values: hijo }]
  assert.deepEqual(precargaDesdeRegistro(tipo('S01_K_CERTIFICADO_DISCAPACIDAD', 'certificados_discapacidad'), { persona: 'familiar', familiar_id: 'f1', fecha_emision: '2026-02-01' }, { familiares }),
    { fecha_emision: '2026-02-01', titular_nombre: 'Quispe Rojas, Ana', titular_documento: '71234567', relacion_titular: 'hijo' })
  // Certificado del propio servidor: el titular sigue siendo el del expediente.
  assert.deepEqual(precargaDesdeRegistro(tipo('S01_K_CERTIFICADO_DISCAPACIDAD', 'certificados_discapacidad'), { persona: 'servidor', fecha_emision: '2026-02-01' }, { familiares }), { fecha_emision: '2026-02-01' })
})

test('proposed values stay editable and never overwrite what the user typed', () => {
  const base = { num_resolucion: '', fecha_emision: '', titular_nombre: 'Servidor Uno', titular_documento: '40000001', relacion_titular: 'servidor' }
  const hijo = { ...base, titular_nombre: 'Hijo Uno', titular_documento: '70000001', relacion_titular: 'hijo' }
  // Elegir el familiar reemplaza el titular de partida.
  let borrador = aplicarPrecarga({ ...base, emisor: 'RENIEC' }, base, hijo, CAMPOS_PRECARGA)
  assert.deepEqual([borrador.titular_nombre, borrador.titular_documento, borrador.relacion_titular, borrador.emisor], ['Hijo Uno', '70000001', 'hijo', 'RENIEC'])
  // El usuario corrige el DNI; al cambiar de registro, ese campo se respeta y el resto se actualiza.
  borrador = { ...borrador, titular_documento: '70000009' }
  const otro = { ...base, titular_nombre: 'Hija Dos', titular_documento: '70000002', relacion_titular: 'hijo' }
  borrador = aplicarPrecarga(borrador, hijo, otro, CAMPOS_PRECARGA)
  assert.deepEqual([borrador.titular_nombre, borrador.titular_documento], ['Hija Dos', '70000009'])
  // Quitar el registro vuelve a los valores de partida en lo que no se tocó.
  borrador = aplicarPrecarga(borrador, otro, base, CAMPOS_PRECARGA)
  assert.equal(borrador.titular_nombre, 'Servidor Uno')
  assert.equal(borrador.titular_documento, '70000009')
})

test('a correction proposes the metadata of the document it corrects', () => {
  assert.deepEqual(precargaDesdeDocumento({ emisor: 'ORH', fecha_emision: '2026-01-05', num_resolucion: 'R-1', titular_nombre: 'Ana', titular_documento: '40000001',
    relacion_titular: 'servidor', modalidad_presentacion: 'copia_simple', folio_inicio: 3, folio_fin: 4, fecha_incorporacion: '2026-01-06' }),
  { emisor: 'ORH', num_resolucion: 'R-1', titular_nombre: 'Ana', titular_documento: '40000001', relacion_titular: 'servidor', modalidad_presentacion: 'copia_simple', fecha_emision: '2026-01-05' })
  assert.deepEqual(precargaDesdeDocumento(null), {})
})

test('combined forms keep the corrected record data and respect the user changes, booleans included', () => {
  const vacios = { cargo: '', fecha_fin: '', hasta_la_fecha: false, es_docencia_universitaria: false }
  const anterior = conValor({ cargo: 'Analista', fecha_fin: '', hasta_la_fecha: true, es_docencia_universitaria: false })
  let borrador = { ...vacios, ...anterior }
  assert.deepEqual(borrador, { cargo: 'Analista', fecha_fin: '', hasta_la_fecha: true, es_docencia_universitaria: false })
  borrador = { ...borrador, cargo: 'Analista II' }
  const nueva = { cargo: 'Jefe', fecha_fin: '2025-12-31', hasta_la_fecha: false, es_docencia_universitaria: true }
  borrador = reemplazarPropuesta(borrador, anterior, nueva, vacios)
  assert.deepEqual(borrador, { cargo: 'Analista II', fecha_fin: '2025-12-31', hasta_la_fecha: false, es_docencia_universitaria: true })
  // Volver a «documento adicional» limpia lo propuesto, salvo lo escrito por el usuario.
  borrador = reemplazarPropuesta(borrador, nueva, {}, vacios)
  assert.deepEqual(borrador, { cargo: 'Analista II', fecha_fin: '', hasta_la_fecha: false, es_docencia_universitaria: false })
})

test('document types are grouped as pending, with documents and not applicable without hiding history', () => {
  const tipos = [{ id: 'a', codigo: 'A', documentos: 0 }, { id: 'b', codigo: 'B', documentos: 2 }, { id: 'c', codigo: 'C', documentos: 1 }, { id: 'd', codigo: 'D', documentos: 0 }]
  const indice = indiceNoAplica([{ id: 'c', codigo: 'C', motivo: 'No corresponde.' }, { id: 'd', codigo: 'D', motivo: 'No corresponde.' }])
  const grupos = agruparTipos(tipos, indice)
  assert.deepEqual(grupos.pendientes.map(t => t.id), ['a'])
  assert.deepEqual(grupos.conDocumentos.map(t => t.id), ['b'])
  // c ya no se ofrece pero tiene documentos: sigue visible (para consultar y subsanar). d no se muestra, como antes.
  assert.deepEqual(grupos.noCorresponde.map(t => t.id), ['c'])
  assert.equal(cantidadDocumentos({ cantidad: 3 }), 3)
  assert.equal(cantidadDocumentos({ lista: [{}, {}] }), 2)
  assert.equal(cantidadDocumentos({ documentos: [{}] }), 1)
  assert.equal(cantidadDocumentos({}), 0)
})

test('attach from a record only offers types that are offered and can accredit it', () => {
  const tipos = [{ id: 't1', codigo: 'T1' }, { id: 't2', codigo: 'T2' }, { id: 't3', codigo: 'T3' }]
  const enlaces = { t1: [{ id: 'r1' }], t2: [{ id: 'r2' }], t3: [{ id: 'r1' }] }
  const indice = indiceNoAplica([{ id: 't3', codigo: 'T3', motivo: 'No corresponde.' }])
  assert.deepEqual(tiposQueAcreditan(tipos, indice, tipoActual => enlaces[tipoActual.id], 'r1').map(t => t.id), ['t1'])
  assert.deepEqual(tiposQueAcreditan(tipos, indice, () => null, 'r1'), [])
})
