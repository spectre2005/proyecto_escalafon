import { leyesDelTipo, errorLey } from './leyes.js'
import assert from 'node:assert/strict'
import test from 'node:test'
import { datosPuestoInicial, vinculoInicial } from '../../legajos/components/seccion02/borradoresVinculo.js'
import { armarRegistro, erroresDe, erroresPersona, PASOS, pasoDeError, personaDelPaso, personaExistente, primerPasoConError, rutaDocumentosIngreso, rutaFicha, sugerenciaTipoVinculo } from './registroPasos.js'

const catalogo = [
  { id: 'ley-30220', codigo: '30220', nombre: 'Ley N° 30220 - Ley Universitaria', aplica_a: 'docente', activo: true },
  { id: 'ley-1057', codigo: '1057', nombre: 'Decreto Legislativo N° 1057 (CAS)', aplica_a: 'administrativo', activo: true },
]
const persona = { numero_doc_identidad: '71000001', nombres: ' Rosa ', apellido_paterno: 'Alta', apellido_materno: '', email_institucional: 'rosa.alta@example.test', tipo_personal: 'docente', ley: '', telefono: '', regimen_normativo_id: '' }

test('the wizard has three steps in order', () => {
  assert.deepEqual(PASOS.map(paso => [paso.numero, paso.titulo]), [[1, 'Datos de la persona'], [2, 'Vínculo y modalidad'], [3, 'Documentos de ingreso']])
})

test('backend errors lead to the step that holds the field', () => {
  assert.equal(pasoDeError('usuarios.0.email_institucional'), 1)
  assert.equal(pasoDeError('usuarios'), 1)
  assert.equal(pasoDeError('vinculo.nombre_puesto'), 2)
  assert.equal(pasoDeError('vinculo.numero_documento_vinculo'), 3)
  assert.equal(pasoDeError('vinculo.tiene_periodo_prueba'), 2)
  // El tipo de vínculo se elige en el paso 2, con los datos del puesto.
  assert.equal(pasoDeError('vinculo.regimen_laboral'), 2)
  assert.equal(pasoDeError('datos_puesto.categoria'), 2)
  assert.equal(pasoDeError('otro'), null)
  assert.equal(primerPasoConError({ 'datos_puesto.categoria': ['x'], 'vinculo.fecha_inicio': ['x'] }), 2)
  assert.equal(primerPasoConError({ 'datos_puesto.categoria': ['x'], 'usuarios.0.numero_doc_identidad': ['x'] }), 1)
  assert.equal(primerPasoConError({ 'vinculo.obligado_djbr': ['x'] }), 2)
  // Un 403 o un 409 no trae campos: se queda en el paso actual.
  assert.equal(primerPasoConError({}), null)
  assert.equal(primerPasoConError(undefined), null)
  assert.deepEqual(erroresDe({ 'vinculo.nombre_puesto': ['a'], 'datos_puesto.categoria': ['b'] }, 'vinculo'), { nombre_puesto: ['a'] })
})

test('step 1 uses the same person rules as the registration', () => {
  const lista = personaDelPaso(persona, 'ley-30220')
  assert.equal(lista.nombres, 'Rosa')
  assert.deepEqual(erroresPersona(lista, { catalogo }), [])
  assert.match(erroresPersona({ ...lista, tipo_personal: '' }, { catalogo }).join(' '), /tipo debe ser docente o administrativo/)
  assert.match(erroresPersona(personaDelPaso({ ...persona, tipo_personal: 'administrativo' }, 'ley-30220'), { catalogo }).join(' '), /corresponde al personal docente/)
  assert.match(erroresPersona(lista, { catalogo, ubicacion: { region: 'Cusco' }, ubigeoId: '' }).join(' '), /región, provincia y distrito/)
  assert.match(erroresPersona(lista, { catalogo, usuarios: [{ numero_doc_identidad: '71000001', email_institucional: 'otra@example.test' }] }).join(' '), /ya está registrada con ese DNI/)
  assert.match(erroresPersona(lista, { catalogo, usuarios: [{ numero_doc_identidad: '71000009', email_institucional: 'ROSA.alta@example.test' }] }).join(' '), /Ya existe un usuario con ese correo/)
})

test('an existing DNI identifies the person instead of creating a duplicate', () => {
  const usuarios = [{ id: 'u-1', numero_doc_identidad: '71000001', nombres: 'Rosa', apellido_paterno: 'Previa', apellido_materno: '' }]
  assert.deepEqual(personaExistente({ numero_doc_identidad: ' 71000001 ' }, usuarios), { id: 'u-1', nombre: 'Rosa Previa' })
  assert.equal(personaExistente({ numero_doc_identidad: '71000002' }, usuarios), null)
  assert.equal(personaExistente({ numero_doc_identidad: '' }, usuarios), null)
})

test('the result links straight to the file and to the 2.2 documents', () => {
  assert.equal(rutaFicha('u-1'), '/legajos/u-1')
  const url = new URL(rutaDocumentosIngreso('u-1'), 'http://localhost')
  assert.equal(url.pathname, '/legajos/u-1')
  assert.equal(url.searchParams.get('categoria'), 's02-vinculo')
  assert.equal(url.searchParams.get('ir'), 'documentos')
})

test('the link type suggestion follows the backend rule and only proposes', () => {
  const sugerir = (tipoPersonal, datosPuesto, codigoLey) => sugerenciaTipoVinculo({ tipoPersonal, datosPuesto, codigoLey }).valor
  assert.equal(sugerir('docente', { condicion_docente: 'ordinario' }, '30220'), 'carrera')
  assert.equal(sugerir('docente', { condicion_docente: 'contratado' }, '30220'), 'plazo_determinado')
  // Extraordinario: los datos no alcanzan.
  assert.equal(sugerir('docente', { condicion_docente: 'extraordinario' }, '30220'), null)
  assert.equal(sugerir('administrativo', { es_cargo_confianza: true }, '1057'), 'designado')
  assert.equal(sugerir('administrativo', { es_cargo_confianza: false }, '1057'), null)
  // D.L. 276 admite carrera o designado: no se sugiere ninguno, aunque sea de confianza.
  assert.equal(sugerir('administrativo', { es_cargo_confianza: true }, '276'), null)
  assert.equal(sugerir('administrativo', {}, ''), null)
  assert.match(sugerenciaTipoVinculo({ tipoPersonal: 'administrativo', datosPuesto: {}, codigoLey: '' }).motivo, /elige/)
})

test('the final call carries every step and only the job data of the person type', () => {
  const puestos = { docente: { ...datosPuestoInicial(true), condicion_docente: 'contratado', categoria: 'principal', dedicacion: 'tiempo_parcial' },
    administrativo: { ...datosPuestoInicial(false), cargo_estructural: 'Escrito antes de cambiar de tipo' } }
  const vinculo = { ...vinculoInicial(), regimen_laboral: 'plazo_determinado', nombre_puesto: ' Docente contratado ', fecha_inicio: '2026-03-01', numero_documento_vinculo: 'RES-1', tiene_periodo_prueba: true }
  const registro = armarRegistro({ persona: personaDelPaso(persona, 'ley-30220'), catalogo, ubigeoId: '', condiciones: { tiene_derechohabientes: true, con_discapacidad: true }, puedeDiscapacidad: false, vinculo, puestos })
  // Una sola operación con las tres partes.
  assert.deepEqual(Object.keys(registro), ['usuarios', 'vinculo', 'datos_puesto'])
  assert.equal(registro.usuarios.length, 1)
  assert.equal(registro.usuarios[0].regimen_normativo_id, 'ley-30220')
  assert.equal('ley' in registro.usuarios[0], false)
  assert.deepEqual([registro.usuarios[0].tiene_derechohabientes, registro.usuarios[0].con_discapacidad, registro.usuarios[0].ubigeo_id], [true, null, null])
  assert.equal(registro.vinculo.nombre_puesto, 'Docente contratado')
  assert.equal(registro.vinculo.fecha_fin_prevista, null)
  assert.equal(registro.vinculo.tiene_periodo_prueba, true)
  // El tipo de personal, la ley y el cese del vínculo los pone el backend: no se envían.
  for (const campo of ['tipo_personal', 'regimen_normativo_id', 'fecha_cese']) assert.equal(campo in registro.vinculo, false)
  // Datos del puesto del tipo de la persona; la categoría sin ordinario se envía vacía.
  assert.deepEqual(registro.datos_puesto, { condicion_docente: 'contratado', categoria: null, dedicacion: 'tiempo_parcial', subtipo_extraordinario: null, funcion_previa: null, codigo_renacyt: null })
  // Lo escrito para el otro tipo se conserva en el borrador y no se envía.
  assert.equal(puestos.administrativo.cargo_estructural, 'Escrito antes de cambiar de tipo')
  assert.equal('cargo_estructural' in registro.datos_puesto, false)
})

test('CAS stays outside teaching choices even if its catalog classification was misconfigured', () => {
  const modificado = catalogo.map(ley => ({ ...ley, aplica_a: 'docente' }))
  assert.deepEqual(leyesDelTipo(modificado, 'docente').map(ley => ley.codigo), ['30220'])
  assert.match(errorLey(modificado, { tipo_personal: 'docente', regimen_normativo_id: 'ley-1057' }), /vínculo administrativo independiente/)
})
