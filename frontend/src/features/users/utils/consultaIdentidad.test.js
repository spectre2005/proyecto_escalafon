import assert from 'node:assert/strict'
import test from 'node:test'
import { datosPuestoInicial, vinculoInicial } from '../../legajos/components/seccion02/borradoresVinculo.js'
import { AVISO_SIMULACION, aplicarIdentidad, camposPorReemplazar, crearConsultaIdentidad, mensajeErrorConsulta, modoConsulta, NO_DISPONIBLE, valoresIdentidad } from './consultaIdentidad.js'
import { armarRegistro, erroresPersona, personaExistente } from './registroPasos.js'
import { updateUserDraft } from './usuariosEmail.js'

const vacio = { numero_doc_identidad: '00000001', nombres: '', apellido_paterno: '', apellido_materno: '', email_institucional: '', tipo_personal: 'docente', ley: '', telefono: '', regimen_normativo_id: '' }
const simulada = { encontrado: true, simulada: true, nombres: 'Ana Lucía', apellido_paterno: 'Demostración', apellido_materno: 'Ficticia' }
const activa = { habilitada: true, disponible: true, simulada: true }
const catalogo = [{ id: 'ley-30220', codigo: '30220', nombre: 'Ley N° 30220 - Ley Universitaria', aplica_a: 'docente', activo: true }]

/** Promesa que se resuelve o rechaza desde fuera, para ordenar respuestas a mano. */
function diferida() {
  let resolver, rechazar
  const promesa = new Promise((ok, error) => { resolver = ok; rechazar = error })
  return { promesa, resolver, rechazar }
}

test('the button appears only for a valid new DNI when the query is enabled and available', () => {
  assert.equal(modoConsulta({ dni: '00000001', existente: null, estado: activa, puedeRegistrar: true }), 'boton')
  assert.equal(modoConsulta({ dni: '0000001', existente: null, estado: activa, puedeRegistrar: true }), null)
  assert.equal(modoConsulta({ dni: '', existente: null, estado: activa, puedeRegistrar: true }), null)
  assert.equal(modoConsulta({ dni: '00000001', existente: null, estado: activa, puedeRegistrar: false }), null)
  assert.equal(modoConsulta({ dni: '00000001', existente: null, estado: null, puedeRegistrar: true }), null)
  assert.equal(modoConsulta({ dni: '00000001', existente: null, estado: { habilitada: false, disponible: false }, puedeRegistrar: true }), null)
  // Activa sin proveedor (p. ej. producción sin proveedor oficial): se avisa y se sigue a mano, sin botón.
  assert.equal(modoConsulta({ dni: '00000001', existente: null, estado: { habilitada: true, disponible: false }, puedeRegistrar: true }), 'no_disponible')
})

test('an already registered person keeps the current warning and does not trigger a query', () => {
  const usuarios = [{ id: 'u1', numero_doc_identidad: '00000001', nombres: 'Rosa', apellido_paterno: 'Alta' }]
  const existente = personaExistente(vacio, usuarios)
  assert.deepEqual(existente, { id: 'u1', nombre: 'Rosa Alta' })
  assert.equal(modoConsulta({ dni: vacio.numero_doc_identidad, existente, estado: activa, puedeRegistrar: true }), null)
})

test('a simulated answer fills names and the fields stay editable', () => {
  const lleno = aplicarIdentidad(vacio, simulada)
  assert.deepEqual([lleno.nombres, lleno.apellido_paterno, lleno.apellido_materno], ['Ana Lucía', 'Demostración', 'Ficticia'])
  assert.equal(lleno.numero_doc_identidad, '00000001')
  // Editable: el borrador acepta otro valor después, como cualquier campo escrito a mano.
  assert.equal(updateUserDraft(lleno, 'nombres', 'Ana', '').nombres, 'Ana')
  assert.equal(AVISO_SIMULACION, 'Simulación: datos ficticios, no consultados a RENIEC')
})

test('the complete demonstration case (00000004) fills the three fields', () => {
  const completo = { encontrado: true, simulada: true, nombres: 'Lucía Fernanda', apellido_paterno: 'Muestra', apellido_materno: 'Simulada' }
  const lleno = aplicarIdentidad({ ...vacio, numero_doc_identidad: '00000004' }, completo)
  assert.deepEqual([lleno.nombres, lleno.apellido_paterno, lleno.apellido_materno], ['Lucía Fernanda', 'Muestra', 'Simulada'])
  assert.deepEqual(camposPorReemplazar({ ...vacio, numero_doc_identidad: '00000004' }, completo), [])
})

test('a missing second surname is left empty, never invented', () => {
  assert.deepEqual(valoresIdentidad({ nombres: 'María', apellido_paterno: 'Ejemplo', apellido_materno: null }), { nombres: 'María', apellido_paterno: 'Ejemplo', apellido_materno: '' })
  assert.equal(aplicarIdentidad({ ...vacio, apellido_materno: '' }, { ...simulada, apellido_materno: null }).apellido_materno, '')
})

test('values already written need confirmation before being replaced', () => {
  assert.deepEqual(camposPorReemplazar(vacio, simulada), [])
  assert.deepEqual(camposPorReemplazar({ ...vacio, nombres: 'Rosa' }, simulada), ['nombres'])
  assert.deepEqual(camposPorReemplazar({ ...vacio, nombres: 'Rosa', apellido_materno: 'Otra' }, simulada), ['nombres', 'apellido_materno'])
  // Lo mismo que trae la consulta no exige confirmar.
  assert.deepEqual(camposPorReemplazar({ ...vacio, nombres: 'Ana Lucía ' }, simulada), [])
  assert.deepEqual(camposPorReemplazar({ ...vacio, nombres: '   ' }, simulada), [])
})

test('a manually written email is kept and a suggested one follows the names', () => {
  const manual = aplicarIdentidad({ ...vacio, email_institucional: 'ana.personal@otro.test' }, simulada, 'universidad.test')
  assert.equal(manual.email_institucional, 'ana.personal@otro.test')
  const sugerido = aplicarIdentidad(vacio, simulada, 'universidad.test')
  assert.equal(sugerido.email_institucional, 'ana-demostracion@universidad.test')
  // Si el correo era la sugerencia de lo escrito antes, se actualiza como al escribir.
  const previo = updateUserDraft(updateUserDraft(vacio, 'nombres', 'Rosa', 'universidad.test'), 'apellido_paterno', 'Alta', 'universidad.test')
  assert.equal(previo.email_institucional, 'rosa-alta@universidad.test')
  assert.equal(aplicarIdentidad(previo, simulada, 'universidad.test').email_institucional, 'ana-demostracion@universidad.test')
})

test('one query at a time: repeated clicks do not send duplicates', async () => {
  const pendiente = diferida()
  let llamadas = 0
  const consulta = crearConsultaIdentidad(() => { llamadas++; return pendiente.promesa })
  const primera = consulta.consultar('00000001')
  assert.equal(consulta.enCurso, true)
  assert.deepEqual(await consulta.consultar('00000001'), { estado: 'duplicada' })
  pendiente.resolver(simulada)
  assert.deepEqual(await primera, { estado: 'ok', datos: simulada })
  assert.equal(llamadas, 1)
  assert.equal(consulta.enCurso, false)
})

test('a stale answer for a previous DNI is discarded and its request aborted', async () => {
  const lenta = diferida()
  const senales = []
  const consulta = crearConsultaIdentidad((dni, signal) => { senales.push(signal); return dni === '00000001' ? lenta.promesa : Promise.resolve({ ...simulada, nombres: 'Carlos Alberto' }) })
  const vieja = consulta.consultar('00000001')
  consulta.cancelar()
  assert.equal(senales[0].aborted, true)
  const nueva = await consulta.consultar('00000002')
  lenta.resolver(simulada)
  assert.deepEqual(await vieja, { estado: 'obsoleta' })
  assert.equal(nueva.datos.nombres, 'Carlos Alberto')
  // Un rechazo por la cancelación tampoco se muestra como error.
  const abortada = diferida()
  const otra = crearConsultaIdentidad(() => abortada.promesa)
  const resultado = otra.consultar('00000003')
  otra.cancelar()
  abortada.rechazar(Object.assign(new Error('canceled'), { code: 'ERR_CANCELED' }))
  assert.deepEqual(await resultado, { estado: 'obsoleta' })
})

test('errors become plain messages that let the user continue manually', async () => {
  const fallo = crearConsultaIdentidad(() => Promise.reject({ response: { status: 503, data: { codigo: 'proveedor_no_disponible', message: 'detalle' } } }))
  const resultado = await fallo.consultar('00000009')
  assert.equal(resultado.estado, 'error')
  const casos = [
    [{ response: { status: 503, data: { codigo: 'proveedor_no_disponible' } } }, /no respondió/],
    [{ response: { status: 503, data: { codigo: 'proveedor_no_configurado' } } }, /no está disponible/],
    [{ response: { status: 403, data: { codigo: 'modulo_desactivado' } } }, /no está disponible/],
    [{ response: { status: 429, data: { codigo: 'limite_consultas' } } }, /límite/],
    [{ response: { status: 403, data: {} } }, /Tu rol/],
    [{ response: { status: 422, data: { errors: { dni: ['x'] } } } }, /8 dígitos/],
    [{ response: { status: 500, data: { message: 'SQLSTATE secreto', exception: 'PDOException' } } }, /No se pudo completar/],
    [new Error('Network Error'), /No se pudo conectar/],
  ]
  for (const [error, patron] of casos) {
    const texto = mensajeErrorConsulta(error)
    assert.match(texto, patron)
    assert.doesNotMatch(texto, /SQLSTATE|PDOException|detalle/)
    assert.ok(texto === NO_DISPONIBLE || /manualmente/.test(texto))
  }
})

test('the registration does not need the query and still ends in the same payload for POST /usuarios/con-vinculo', () => {
  const persona = { ...vacio, numero_doc_identidad: '45678912', nombres: 'Rosa', apellido_paterno: 'Alta', email_institucional: 'rosa.alta@example.test', regimen_normativo_id: 'ley-30220' }
  // Sin consulta: las mismas validaciones de siempre, sin errores.
  assert.deepEqual(erroresPersona(persona, { catalogo }), [])
  const condiciones = { tiene_derechohabientes: null, con_discapacidad: null }
  const puestos = { docente: datosPuestoInicial(true), administrativo: datosPuestoInicial(false) }
  const manual = armarRegistro({ persona, catalogo, ubigeoId: '', condiciones, puedeDiscapacidad: true, vinculo: vinculoInicial, puestos })
  // Con consulta: el cuerpo tiene las mismas claves; solo cambian nombres y apellidos, sin marcas de la consulta.
  const consultada = { ...aplicarIdentidad({ ...persona, nombres: '', apellido_paterno: '' }, simulada), email_institucional: persona.email_institucional }
  const conConsulta = armarRegistro({ persona: consultada, catalogo, ubigeoId: '', condiciones, puedeDiscapacidad: true, vinculo: vinculoInicial, puestos })
  assert.deepEqual(Object.keys(conConsulta.usuarios[0]).sort(), Object.keys(manual.usuarios[0]).sort())
  assert.equal(conConsulta.usuarios[0].nombres, 'Ana Lucía')
  assert.equal(JSON.stringify(conConsulta).includes('simulada'), false)
  assert.deepEqual(Object.keys(conConsulta).sort(), ['datos_puesto', 'usuarios', 'vinculo'])
})
