import assert from 'node:assert/strict'
import test from 'node:test'
import { datosPuestoInicial, vinculoInicial } from '../../legajos/components/seccion02/borradoresVinculo.js'
import { armarRegistro, avisosDeCoherencia, grupoPorNombre, condicionesSinResponder, DESCRIPCION_LEY, tituloLey, erroresDe, erroresPersona, iniciales, PASOS, pasoDeError, personaDelPaso, personaExistente, primerPasoConError, rutaDatosPersonales, rutaDocumentosIngreso, rutaFicha, sugerenciaTipoVinculo } from './registroPasos.js'

const catalogo = [
  { id: 'ley-30220', codigo: '30220', nombre: 'Ley N° 30220 - Ley Universitaria', aplica_a: 'docente', activo: true },
  { id: 'ley-1057', codigo: '1057', nombre: 'Decreto Legislativo N° 1057 (CAS)', aplica_a: 'administrativo', activo: true },
]
const persona = { numero_doc_identidad: '71000001', nombres: ' Rosa ', apellido_paterno: 'Alta', apellido_materno: '', email_institucional: 'rosa.alta@example.test', tipo_personal: 'docente', ley: '', telefono: '', regimen_normativo_id: '' }

test('the wizard has three steps and the last one only reviews', () => {
  assert.deepEqual(PASOS.map(paso => [paso.numero, paso.titulo]), [[1, 'Persona'], [2, 'Puesto y vínculo'], [3, 'Revisar y registrar']])
})

test('backend errors lead to the step that holds the field', () => {
  assert.equal(pasoDeError('usuarios.0.email_institucional'), 1)
  assert.equal(pasoDeError('usuarios'), 1)
  // Paso 2: puesto, datos del puesto, condiciones y todo el vínculo.
  for (const clave of ['vinculo.nombre_puesto', 'vinculo.puesto_id', 'vinculo.tiene_periodo_prueba', 'datos_puesto.categoria', 'vinculo.regimen_laboral', 'vinculo.fecha_inicio', 'vinculo', 'datos_puesto']) {
    assert.equal(pasoDeError(clave), 2, clave)
  }
  assert.equal(pasoDeError('otro'), null)
  assert.equal(primerPasoConError({ 'datos_puesto.categoria': ['x'], 'vinculo.fecha_inicio': ['x'] }), 2)
  assert.equal(primerPasoConError({ 'datos_puesto.categoria': ['x'], 'usuarios.0.numero_doc_identidad': ['x'] }), 1)
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
  assert.deepEqual(personaExistente({ numero_doc_identidad: ' 71000001 ' }, usuarios), { id: 'u-1', nombre: 'Rosa Previa', puedeIncorporar: false, tieneLegajoActivo: false, numeroIncorporacion: 1 })
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
  assert.equal(sugerir('administrativo', { es_cargo_confianza: false }, '1057'), 'plazo_determinado')
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

test('birth date and address travel only when they were filled in', () => {
  const vinculo = { ...vinculoInicial(), regimen_laboral: 'carrera', nombre_puesto: 'Docente', fecha_inicio: '2026-03-01' }
  const puestos = { docente: datosPuestoInicial(true), administrativo: datosPuestoInicial(false) }
  const armar = datos => armarRegistro({ persona: personaDelPaso({ ...persona, ...datos }, 'ley-30220'), catalogo, ubigeoId: '', condiciones: { tiene_derechohabientes: null, con_discapacidad: null }, puedeDiscapacidad: false, vinculo, puestos }).usuarios[0]
  const conDatos = armar({ fecha_nacimiento: '1980-03-15', domicilio: ' JR. FICTICIO 123 ' })
  assert.equal(conDatos.fecha_nacimiento, '1980-03-15')
  assert.equal(conDatos.domicilio, 'JR. FICTICIO 123')
  const sinDatos = armar({ fecha_nacimiento: '', domicilio: '  ' })
  assert.equal('fecha_nacimiento' in sinDatos, false)
  assert.equal('domicilio' in sinDatos, false)
})

test('the birth date cannot be in the future or impossible', () => {
  const base = { ...persona, regimen_normativo_id: 'ley-30220' }
  assert.deepEqual(erroresPersona({ ...base, fecha_nacimiento: '1980-03-15' }, { catalogo }), [])
  assert.match(erroresPersona({ ...base, fecha_nacimiento: '2999-01-01' }, { catalogo }).join(' '), /posterior a hoy/)
  assert.match(erroresPersona({ ...base, fecha_nacimiento: '1980-02-30' }, { catalogo }).join(' '), /fecha válida/)
})

test('passive files allow another incorporation and active files block it', () => {
  const lista = personaDelPaso(persona, 'ley-30220')
  const previa = { ...lista, id: 'u-1', tiene_legajo_pasivo: true, tiene_legajo_activo: false, legajos_count: 2 }
  assert.deepEqual(erroresPersona(lista, { catalogo, usuarios: [previa] }), [])
  assert.equal(personaExistente(lista, [previa]).numeroIncorporacion, 3)
  assert.equal(personaExistente(lista, [previa]).puedeIncorporar, true)
  assert.match(erroresPersona(lista, { catalogo, usuarios: [{ ...previa, tiene_legajo_activo: true }] }).join(' '), /ya está registrada/)
})

test('a person with an active file can be registered again only after confirming the new admission', () => {
  const usuarios = [{ id: 'u-9', numero_doc_identidad: '71000009', nombres: 'Ana', apellido_paterno: 'Vuelve', email_institucional: 'ana@x.pe', tiene_legajo_activo: true, tiene_legajo_pasivo: false, legajos_count: 1 }]
  const nueva = { ...persona, numero_doc_identidad: '71000009', email_institucional: 'ana@x.pe' }
  const existente = personaExistente(nueva, usuarios)
  assert.deepEqual([existente.puedeIncorporar, existente.tieneLegajoActivo, existente.numeroIncorporacion], [false, true, 2])
  const bloqueo = 'Esta persona ya está registrada con ese DNI.'
  assert.ok(erroresPersona(nueva, { usuarios }).includes(bloqueo))
  assert.ok(!erroresPersona(nueva, { usuarios, nuevoIngreso: true }).includes(bloqueo))
  const base = { persona: { ...nueva, tipo_personal: 'administrativo' }, catalogo: [], ubigeoId: '', condiciones: {}, puedeDiscapacidad: false, vinculo: {}, puestos: { administrativo: {} } }
  assert.equal(armarRegistro({ ...base, nuevoIngreso: true }).cerrar_legajo_actual, true)
  assert.equal('cerrar_legajo_actual' in armarRegistro(base), false)
})

test('the career level is sent only if it belongs to the chosen law and group', () => {
  const leyes = [...catalogo, { id: 'ley-276', codigo: '276', nombre: 'Decreto Legislativo N° 276', aplica_a: 'administrativo', activo: true }]
  const administrativo = { ...persona, tipo_personal: 'administrativo' }
  const puestos = { docente: datosPuestoInicial(true), administrativo: { ...datosPuestoInicial(false), grupo_ocupacional: 'tecnico', nivel_carrera: 'STB' } }
  const escalas = ['STF', 'STE', 'STD', 'STC', 'STB', 'STA'].map((codigo, indice) => ({ codigo_ley: '276', grupo_ocupacional: 'tecnico', codigo, orden: indice + 1, activo: true }))
  const armar = ley => armarRegistro({ persona: personaDelPaso(administrativo, ley), catalogo: leyes, escalas, ubigeoId: '', condiciones: {}, puedeDiscapacidad: false, vinculo: vinculoInicial(), puestos })
  assert.equal(armar('ley-276').datos_puesto.nivel_carrera, 'STB')
  // Se eligió STB con el 276 y luego se cambió la ley a CAS: no se envía.
  assert.equal(armar('ley-1057').datos_puesto.nivel_carrera, '')
})

test('lists the conditions still unanswered, without blocking the registration', () => {
  const vinculo = { obligado_djbr: false, exige_colegiatura: null, exige_estudios_basicos: true, tiene_periodo_prueba: undefined }
  assert.deepEqual(condicionesSinResponder({ vinculo, condiciones: { tiene_derechohabientes: true, con_discapacidad: null } }),
    ['El puesto exige colegiatura', 'Tiene período de prueba', 'Discapacidad del servidor o de alguno de sus hijos'])
  // Sin permiso para registrar discapacidad, no se le pide.
  assert.deepEqual(condicionesSinResponder({ vinculo: { obligado_djbr: true, exige_colegiatura: true, exige_estudios_basicos: true, tiene_periodo_prueba: true }, condiciones: { con_discapacidad: null }, puedeDiscapacidad: false }), [])
})

test('helpers for the summary: initials, law description and routes', () => {
  assert.equal(iniciales({ nombres: 'Ana Lucía', apellido_paterno: 'Torres' }), 'AT')
  assert.equal(iniciales({}), '')
  assert.equal(DESCRIPCION_LEY['1057'], 'CAS · sin nivel de carrera')
  assert.equal(rutaDatosPersonales('u-1'), '/legajos/u-1?categoria=general')
})

test('each law card has a short name', () => {
  assert.equal(tituloLey({ codigo: '1057', nombre: 'Decreto Legislativo N° 1057 (CAS)' }), 'D.L. 1057')
  assert.equal(tituloLey({ codigo: '30057', nombre: 'Ley N° 30057 - Ley del Servicio Civil' }), 'Ley 30057')
  assert.equal(tituloLey({ codigo: 'OTRO', nombre: 'Régimen especial' }), 'Régimen especial')
})

test('avisa de datos que suelen ser un error, sin impedir registrar', () => {
  const carrera = { regimen_laboral: 'carrera', fecha_inicio: '2026-11-19', fecha_fin_prevista: '2026-11-30' }
  assert.match(avisosDeCoherencia({ vinculo: carrera })[0], /carrera .* normalmente no tiene fecha de fin, y este termina el 30\/11\/2026/)
  assert.match(avisosDeCoherencia({ vinculo: { regimen_laboral: 'carrera' }, codigoLey: '1057' })[0], /CAS .* no es de carrera/)
  assert.match(avisosDeCoherencia({ vinculo: { regimen_laboral: 'plazo_determinado' }, datosPuesto: { es_cargo_confianza: true }, tipo: 'administrativo' })[0], /Cargo de confianza/)
  assert.match(avisosDeCoherencia({ vinculo: { fecha_inicio: '2026-03-01', fecha_resultado_seleccion: '2026-03-10' } })[0], /posterior al inicio/)
  // Lo coherente no genera avisos.
  assert.deepEqual(avisosDeCoherencia({ vinculo: { regimen_laboral: 'carrera', fecha_inicio: '2026-03-01', fecha_resultado_seleccion: '2026-02-20' }, codigoLey: '276', tipo: 'administrativo' }), [])
})

test('casos de las capturas: docente ordinario contratado y técnico en el grupo profesional', () => {
  // Captura 1: Profesor ordinario principal con vínculo «Plazo determinado · Docente contratado».
  const docente = avisosDeCoherencia({ tipo: 'docente', datosPuesto: { condicion_docente: 'ordinario' }, vinculo: { regimen_laboral: 'plazo_determinado', fecha_inicio: '2026-11-19', fecha_fin_prevista: '2026-12-01' } })
  assert.match(docente[0], /docente ordinario es de carrera/)
  assert.match(avisosDeCoherencia({ tipo: 'docente', datosPuesto: { condicion_docente: 'contratado' }, vinculo: { regimen_laboral: 'carrera' } })[0], /contratado no es de carrera/)
  // Captura 2: «Técnico administrativo» con grupo Profesional.
  const tecnico = avisosDeCoherencia({ tipo: 'administrativo', datosPuesto: { grupo_ocupacional: 'profesional' }, vinculo: { nombre_puesto: 'Técnico administrativo', regimen_laboral: 'plazo_determinado' } })
  assert.match(tecnico[0], /es del grupo Técnico, pero elegiste el grupo Profesional/)
  // El grupo del puesto del catálogo manda sobre el nombre.
  assert.deepEqual(avisosDeCoherencia({ tipo: 'administrativo', grupoDelPuesto: 'profesional', datosPuesto: { grupo_ocupacional: 'profesional' }, vinculo: { nombre_puesto: 'Técnico administrativo' } }), [])
  // Lo coherente no avisa.
  assert.deepEqual(avisosDeCoherencia({ tipo: 'docente', datosPuesto: { condicion_docente: 'ordinario' }, vinculo: { regimen_laboral: 'carrera' } }), [])
})

test('el grupo se deduce del nombre solo si lo dice una vez y como palabra', () => {
  assert.equal(grupoPorNombre('Técnico administrativo'), 'tecnico')
  assert.equal(grupoPorNombre('AUXILIAR DE ARCHIVO'), 'auxiliar')
  assert.equal(grupoPorNombre('Profesional en contabilidad'), 'profesional')
  assert.equal(grupoPorNombre('Secretaria'), '')
  assert.equal(grupoPorNombre('Técnico o auxiliar'), '')
  assert.equal(grupoPorNombre('Tecnicos varios'), '')
})
