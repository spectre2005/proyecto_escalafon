import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import Icon from '../../../components/ui/Icon'
import TresEstados from '../../../components/ui/TresEstados'
import UbigeoFields from '../../../components/ui/UbigeoFields'
import { advanceOnEnter } from '../../../shared/forms/advanceOnEnter'
import { CampoTipoVinculo, CamposDatosPuesto, CamposDocumentoIngreso, CamposUbicacionPuesto, CamposVigencia, CondicionesPuesto } from '../../legajos/components/seccion02/CamposVinculo'
import { datosPuestoInicial, vinculoInicial } from '../../legajos/components/seccion02/borradoresVinculo'
import { ajustarAPuesto, diferenciasConPuesto, equivalenciaPuesto, sincronizarConPuesto } from '../../legajos/config/puestosSugeridos'
import { categoriasDocente, condicionesDocente, nivelTrasCambiarGrupo } from '../../legajos/config/seccion02'
import { leyesDelTipo, leyInicial } from '../utils/leyes'
import { columns, hoyLima } from '../utils/usuariosCsv'
import { updateUserDraft } from '../utils/usuariosEmail'
import { consultarIdentidad, estadoConsultaIdentidad } from '../api/usuariosApi'
import { usePuede } from '../../auth/session'
import { AVISO_SIMULACION, aplicarIdentidad, camposPorReemplazar, consultarAlCompletar, crearConsultaIdentidad, domicilioTrasCambioDni, ESPERA_CONSULTA_MS, mensajeErrorConsulta, modoConsulta, mensajeUbicacion, NO_DISPONIBLE, UBICACION_VACIA, ubicacionManual, ubicacionTrasCambioDni, ubicacionTrasConsulta, valoresIdentidad } from '../utils/consultaIdentidad'
import ResumenRegistro from './ResumenRegistro'
import { armarRegistro, erroresDe, erroresPersona, PASOS, personaDelPaso, personaExistente, primerPasoConError, rutaFicha, sugerenciaTipoVinculo } from '../utils/registroPasos'

// Registro manual en cuatro pasos dentro de la ventana «Agregar personal»: persona, puesto y cargo, vínculo laboral y
// revisión final. Los valores se conservan al avanzar y retroceder; cada paso se valida antes de continuar y todo se envía en una
// sola operación al final (onGuardar → POST /usuarios/con-vinculo, transaccional).

// Sin selección inicial: el tipo de personal lo elige el usuario. La ley se toma sola solo si su tipo tiene una única ley.
// Fecha de nacimiento y domicilio: opcionales y solo para quien ve datos sensibles (el backend lo exige igual).
const personaVacia = { ...Object.fromEntries(columns.map(key => [key, ''])), tipo_personal: '', regimen_normativo_id: '', fecha_nacimiento: '', domicilio: '' }
const condicionesVacias = { tiene_derechohabientes: null, con_discapacidad: null }
// Datos del puesto de cada tipo por separado: cambiar el tipo de personal no borra lo ya escrito para el otro.
const puestosVacios = () => ({ docente: datosPuestoInicial(true), administrativo: datosPuestoInicial(false) })
const camposPersona = [
  ['numero_doc_identidad', 'DNI', true], ['nombres', 'Nombres', true], ['apellido_paterno', 'Apellido paterno', true],
  ['apellido_materno', 'Apellido materno', false], ['email_institucional', 'Correo electrónico', true], ['telefono', 'Teléfono', false],
  ['fecha_nacimiento', 'Fecha de nacimiento', false],
]
const camposSensibles = ['fecha_nacimiento', 'domicilio']
const largo = { numero_doc_identidad: 8, telefono: 9, nombres: 120, email_institucional: 255, domicilio: 250 }
const tiposPersonal = { docente: 'Docente', administrativo: 'Administrativo' }
const etiquetas = { ...Object.fromEntries(camposPersona.map(([key, label]) => [key, label.toLowerCase()])), domicilio: 'domicilio' }
const identidadInicial = { cargando: false, mensaje: null, origen: null, pendiente: null, ubicacion: null }
const estilosMensaje = { ok: 'bg-teal-50 text-teal-800', info: 'border border-amber-200 bg-amber-50 text-amber-800', error: 'bg-rose-50 text-rose-700' }
const botonSecundario = 'rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-teal-50'

const nombresPerfil = { condicion_docente: ['condición', condicionesDocente], categoria: ['categoría', categoriasDocente] }
// «condición «Ordinario» y categoría «Asociado»» con los valores de `datos`.
const textoCampos = (campos, datos) => campos.map(campo => `${nombresPerfil[campo][0]} «${nombresPerfil[campo][1][datos[campo]] || datos[campo]}»`).join(' y ')

function Grupo({ titulo, ayuda, children }) {
  return <fieldset className="grid min-w-0 gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
    <legend className="mb-2 text-[12px] font-semibold text-slate-800">{titulo}{ayuda && <span className="ml-2 font-normal text-slate-500">{ayuda}</span>}</legend>
    {children}
  </fieldset>
}

function Progreso({ paso, onIr, disabled }) {
  return <ol className="flex items-center gap-2 sm:gap-3" aria-label="Progreso del registro">
    {PASOS.map(({ numero, titulo, resumen }) => {
      const hecho = numero < paso, actual = numero === paso
      const contenido = <>
        <span className={`grid size-7 shrink-0 place-items-center rounded-full text-xs font-semibold ${actual ? 'bg-teal-700 text-white' : hecho ? 'bg-teal-50 text-teal-800' : 'bg-slate-100 text-slate-500'}`}>{hecho ? <Icon name="check" size={14} /> : numero}</span>
        <span className={`min-w-0 text-left ${actual ? '' : 'max-sm:hidden'}`}><span className={`block truncate text-xs font-semibold ${actual ? 'text-slate-800' : 'text-slate-500'}`}>{titulo}</span><span className="hidden truncate text-xs text-slate-500 lg:block">{resumen}</span></span>
      </>
      // En pantallas pequeñas solo el paso actual muestra su título; los demás, su número.
      return <li key={numero} aria-current={actual ? 'step' : undefined} className={`flex min-w-0 items-center gap-2 ${actual ? 'flex-1' : 'flex-none sm:flex-1'}`}>
        {hecho ? <button type="button" disabled={disabled} onClick={() => onIr(numero)} className="flex min-w-0 items-center gap-2 rounded-lg hover:bg-teal-50" aria-label={`Volver al paso ${numero}: ${titulo}`}>{contenido}</button>
          : <span className="flex min-w-0 items-center gap-2">{contenido}</span>}
        {numero < PASOS.length && <span aria-hidden="true" className="hidden h-px flex-1 bg-slate-200 sm:block" />}
      </li>
    })}
  </ol>
}

// DNI ya registrado. Con su legajo pasivo, se registra un nuevo ingreso con un legajo nuevo. Con su legajo aún activo
// (dejó la institución y vuelve), se puede confirmar que ese legajo pase a Legajos pasivos como historial y registrar el
// nuevo ingreso; sin confirmar, no se crea otro registro.
function PersonaRegistrada({ persona, puedeVerFicha, puedeRegistrar, nuevoIngreso, onNuevoIngreso }) {
  const ficha = puedeVerFicha && <Link to={rutaFicha(persona.id)} className="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-white px-3 py-1.5 font-semibold text-amber-800 hover:bg-amber-100">Ir a su ficha <Icon name="arrow" size={14} /></Link>
  const nombre = <strong>{persona.nombre || 'Esta persona'}</strong>
  if (persona.puedeIncorporar || nuevoIngreso) return <div role="status" className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-teal-200 bg-teal-50 px-4 py-3 text-xs leading-relaxed text-teal-800">
    <span>Nuevo ingreso de {nombre}: se creará su legajo n.° {persona.numeroIncorporacion}.{nuevoIngreso ? ' Su legajo actual pasará a Legajos pasivos como historial, con sus documentos.' : ' Su legajo anterior se conserva en Legajos pasivos.'}</span>
    {nuevoIngreso && <button type="button" onClick={() => onNuevoIngreso(false)} className="font-semibold underline">Deshacer</button>}
  </div>
  if (persona.tieneLegajoActivo && puedeRegistrar) return <div role="alert" className="mb-4 grid gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-relaxed text-amber-800">
    <span>{nombre} ya está registrada y tiene un legajo activo. Si dejó la institución y vuelve a ingresar, registra su nuevo ingreso: su legajo actual pasará a Legajos pasivos como historial y se abrirá uno nuevo.</span>
    <span className="flex flex-wrap gap-2">
      <button type="button" onClick={() => onNuevoIngreso(true)} className="rounded-lg bg-teal-700 px-3 py-1.5 font-semibold text-white hover:bg-teal-800">Registrar nuevo ingreso</button>
      {ficha}
    </span>
  </div>
  return <div role="alert" className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-relaxed text-amber-800">
    <span>{nombre} ya está registrada con ese DNI. No se creará otro registro.{puedeVerFicha ? ' Revisa su ficha para continuar con su legajo.' : ''}</span>
    {ficha}
  </div>
}

// Al escribir un DNI ya registrado se completan los datos de la persona (así un reingreso no la duplica).
function conPersonaPrevia(borrador, usuarios) {
  const previa = usuarios.find(item => item.numero_doc_identidad === borrador.numero_doc_identidad)
  if (!previa) return borrador
  return { ...borrador, ...Object.fromEntries(Object.keys(personaVacia).filter(campo => campo !== 'numero_doc_identidad' && previa[campo] !== undefined).map(campo => [campo, previa[campo] ?? ''])) }
}

export default function RegistroManual({ usuarios, opciones, dominioCorreo = '', puedeDiscapacidad, puedeRegistrar, puedeVerFicha = false, saving, bloqueado, mensaje, onGuardar, onCancel }) {
  const formRef = useRef(null)
  const contenidoRef = useRef(null)
  const [paso, setPaso] = useState(1)
  const [draft, setDraft] = useState(personaVacia)
  const [condiciones, setCondiciones] = useState(condicionesVacias)
  // Ubicación con su origen (manual o consulta de un DNI): la elegida a mano prevalece sobre la consulta.
  const [ubicacionEstado, setUbicacionEstado] = useState(UBICACION_VACIA)
  const ubicacion = ubicacionEstado.value
  const ubigeoId = ubicacionEstado.id
  const ubicacionRef = useRef(ubicacionEstado)
  const puedeSensibles = usePuede('datos_sensibles.ver')
  const [vinculo, setVinculo] = useState(vinculoInicial)
  const [puestos, setPuestos] = useState(puestosVacios)
  const [avisos, setAvisos] = useState([])
  const [errores, setErrores] = useState({})
  const [notaPuesto, setNotaPuesto] = useState('')
  // Campos del perfil docente que rellenó el sistema desde el puesto: siguen al puesto si este cambia; lo editado a mano, no.
  const [autoPuesto, setAutoPuesto] = useState([])
  // Persona existente informada por el backend al guardar (la lista local puede no estar al día).
  const [existenteBackend, setExistenteBackend] = useState(null)
  // Nuevo ingreso confirmado de una persona con legajo activo (pasa a pasivo al registrar). Se anula al cambiar el DNI.
  const [nuevoIngreso, setNuevoIngreso] = useState(false)
  // Consulta de identidad por DNI (opcional): estado de la institución, una consulta a la vez y lo que se completó.
  const [estadoIdentidad, setEstadoIdentidad] = useState(null)
  const [identidad, setIdentidad] = useState(identidadInicial)
  const [consultor] = useState(() => crearConsultaIdentidad(consultarIdentidad))
  const draftRef = useRef(draft)
  const avisoIdentidadRef = useRef(null)
  // Último DNI consultado solo al completarse: no se vuelve a consultar solo (para repetir, el botón).
  const consultadoRef = useRef('')
  const catalogo = opciones.data?.data.regimenes
  const tipo = draft.tipo_personal
  const leyElegida = draft.regimen_normativo_id || leyInicial(catalogo, tipo)
  const erroresPersonaCampo = erroresDe(errores, 'usuarios.0')
  const existente = personaExistente(draft, usuarios) || (existenteBackend?.dni === draft.numero_doc_identidad.trim() ? existenteBackend.persona : null)
  const modoIdentidad = modoConsulta({ dni: draft.numero_doc_identidad, existente, estado: estadoIdentidad, puedeRegistrar })

  useEffect(() => { draftRef.current = draft }, [draft])
  useEffect(() => { ubicacionRef.current = ubicacionEstado }, [ubicacionEstado])
  // Sin permiso no se pregunta; si el estado no llega, no hay botón y el registro sigue manual.
  useEffect(() => {
    if (!puedeRegistrar) return undefined
    const controlador = new AbortController()
    estadoConsultaIdentidad(controlador.signal).then(setEstadoIdentidad, () => {})
    return () => controlador.abort()
  }, [puedeRegistrar])
  useEffect(() => () => consultor.cancelar(), [consultor])
  // En pantallas pequeñas el aviso queda debajo de los campos: se trae a la vista si pide confirmación o informa un error.
  useEffect(() => {
    if (identidad.pendiente || (identidad.mensaje && identidad.mensaje.tipo !== 'ok')) avisoIdentidadRef.current?.scrollIntoView?.({ block: 'nearest' })
  }, [identidad.pendiente, identidad.mensaje])

  // Al completar los ocho dígitos se consulta sola, tras una breve espera; si el DNI cambia antes, no se envía.
  useEffect(() => {
    const dni = draft.numero_doc_identidad.trim()
    if (!consultarAlCompletar({ modo: modoIdentidad, dni, ultimoConsultado: consultadoRef.current, ocupado: saving || bloqueado })) return undefined
    const espera = setTimeout(() => {
      consultadoRef.current = dni
      consultarDni(dni)
    }, ESPERA_CONSULTA_MS)
    return () => clearTimeout(espera)
  })

  // Un DNI nuevo descarta la consulta en curso, lo que esperaba confirmación y la ubicación que trajo otra consulta.
  function reiniciarIdentidad(dni) {
    consultor.cancelar()
    consultadoRef.current = ''
    setIdentidad(identidadInicial)
    setUbicacionEstado(actual => ubicacionTrasCambioDni(actual, String(dni || '').trim()))
  }

  function aplicarConsulta(dni, datos, avisoUbicacion = null) {
    setDraft(previo => previo.numero_doc_identidad.trim() === dni ? aplicarIdentidad(previo, datos, dominioCorreo) : previo)
    setIdentidad({ cargando: false, pendiente: null, origen: { simulada: datos.simulada, valores: valoresIdentidad(datos) }, ubicacion: avisoUbicacion,
      mensaje: { tipo: 'ok', texto: `Se completaron nombres y apellidos${valoresIdentidad(datos).domicilio ? ' y el domicilio' : ''}. Revísalos antes de continuar; puedes corregirlos.` } })
  }

  async function consultarDni(dni = draftRef.current.numero_doc_identidad.trim()) {
    if (consultor.enCurso || modoIdentidad !== 'boton') return
    setIdentidad(previo => ({ ...previo, cargando: true, mensaje: null, pendiente: null }))
    const resultado = await consultor.consultar(dni)
    // Cancelada (cambió el DNI o se cerró la ventana), repetida o de un DNI que ya no está escrito: no se aplica nada.
    if (resultado.estado === 'obsoleta' || resultado.estado === 'duplicada' || draftRef.current.numero_doc_identidad.trim() !== dni) return
    if (resultado.estado === 'error') {
      setIdentidad(previo => ({ ...previo, cargando: false, mensaje: { tipo: 'error', texto: mensajeErrorConsulta(resultado.error) } }))
      return
    }
    const datos = resultado.datos
    if (!datos.encontrado) {
      setIdentidad(previo => ({ ...previo, cargando: false, mensaje: { tipo: 'info', texto: datos.simulada
        ? 'Simulación: este DNI no es uno de los casos de demostración. Completa los datos manualmente.'
        : 'No se encontraron datos para este DNI. Completa los datos manualmente.' } }))
      return
    }
    // La ubicación no espera la confirmación de los nombres: solo se completa si no hay una elegida a mano.
    const avisoUbicacion = mensajeUbicacion(ubicacionRef.current, datos)
    setUbicacionEstado(actual => ubicacionTrasConsulta(actual, dni, datos))
    if (camposPorReemplazar(draftRef.current, datos).length) {
      setIdentidad(previo => ({ ...previo, cargando: false, pendiente: { dni, datos }, ubicacion: avisoUbicacion }))
      return
    }
    aplicarConsulta(dni, datos, avisoUbicacion)
  }

  const codigoLey = (catalogo || []).find(ley => ley.id === leyElegida)?.codigo
  const sugerencia = tipo ? sugerenciaTipoVinculo({ tipoPersonal: tipo, datosPuesto: puestos[tipo], codigoLey }) : null
  // El nivel de carrera depende de la ley (paso 1): si se cambió la ley, un nivel que ya no vale no se muestra ni se envía
  // (armarRegistro aplica la misma regla).
  const escalas = opciones.data?.data.niveles
  const datosPuestoVisibles = tipo === 'administrativo'
    ? { ...puestos.administrativo, nivel_carrera: nivelTrasCambiarGrupo(escalas, puestos.administrativo.nivel_carrera, codigoLey, puestos.administrativo.grupo_ocupacional) }
    : puestos[tipo]

  const ponerVinculo = key => valor => {
    setVinculo(previo => ({ ...previo, [key]: valor }))
    if (key === 'nombre_puesto') completarPerfil(valor)
  }
  const setCampoPuesto = key => event => {
    const valor = event.target.type === 'checkbox' ? event.target.checked : event.target.value
    setPuestos(previos => ({ ...previos, [tipo]: { ...previos[tipo], [key]: valor } }))
    // Elegido a mano: ya no sigue al puesto.
    setAutoPuesto(previos => previos.filter(campo => campo !== key))
    setNotaPuesto('')
    setAvisos([])
  }

  // Puesto docente con equivalencia inequívoca (p. ej. «Profesor ordinario principal»): rellena condición y categoría.
  // Si el puesto cambia, lo que rellenó el sistema lo sigue; lo elegido a mano se conserva y, si no coincide, se avisa abajo.
  function completarPerfil(puesto) {
    const { datos, autocompletados, completados, quitados } = sincronizarConPuesto(puestos.docente, equivalenciaPuesto(tipo, puesto), autoPuesto)
    setPuestos(previos => ({ ...previos, docente: datos }))
    setAutoPuesto(autocompletados)
    setAvisos([])
    if (completados.length) setNotaPuesto(`Se completó desde el puesto: ${textoCampos(completados, datos)}. Puedes cambiarlo.`)
    else if (quitados.length) setNotaPuesto('Se vaciaron la condición y la categoría que venían del puesto anterior: elígelas para este puesto.')
    else setNotaPuesto('')
  }

  // Contradicción entre el puesto y los datos del puesto, calculada siempre (al cambiar arriba o abajo).
  const equivalenciaActual = equivalenciaPuesto(tipo, vinculo.nombre_puesto)
  const conflictosPuesto = tipo === 'docente' ? diferenciasConPuesto(puestos.docente, equivalenciaActual) : []
  const mensajeConflicto = `El puesto «${vinculo.nombre_puesto}» corresponde a ${textoCampos(Object.keys(equivalenciaActual), equivalenciaActual)}.`

  function ajustarPerfilAlPuesto() {
    setPuestos(previos => ({ ...previos, docente: ajustarAPuesto(previos.docente, equivalenciaActual) }))
    setAutoPuesto(Object.keys(equivalenciaActual))
    setNotaPuesto('')
    setAvisos([])
  }

  function irA(numero) {
    setPaso(numero)
    contenidoRef.current?.scrollTo({ top: 0 })
  }

  // Validación del paso: los controles visibles (obligatorios, formatos y fechas) y, en el paso 1, las reglas del alta.
  function pasoValido() {
    if (!formRef.current.reportValidity()) return false
    const lista = paso === 1 ? erroresPersona(personaDelPaso(draft, leyElegida), { catalogo, usuarios, ubicacion, ubigeoId, nuevoIngreso })
      : paso === 2 && conflictosPuesto.length ? [`${mensajeConflicto} Ajusta los datos del puesto (parte B) o elige otro puesto para continuar.`] : []
    setAvisos(lista)
    return !lista.length
  }

  async function enviar(event) {
    event.preventDefault()
    if (saving || bloqueado || !pasoValido()) return
    if (paso < PASOS.length) return irA(paso + 1)
    const persona = personaDelPaso(draft, leyElegida)
    const pendientes = erroresPersona(persona, { catalogo, usuarios, ubicacion, ubigeoId, nuevoIngreso })
    if (pendientes.length) { setAvisos(pendientes); return irA(1) }
    setErrores({})
    const resultado = await onGuardar(armarRegistro({ persona, catalogo, escalas, ubigeoId, condiciones, puedeDiscapacidad, vinculo, puestos, nuevoIngreso }))
    if (!resultado.ok) {
      setExistenteBackend(resultado.existente ? { dni: persona.numero_doc_identidad, persona: resultado.existente } : null)
      setErrores(resultado.errors || {})
      const destino = primerPasoConError(resultado.errors)
      if (destino) irA(destino)
    }
  }

  const campo = (key, label, requerido) => {
    const numerico = ['numero_doc_identidad', 'telefono'].includes(key)
    return <label key={key} className={key === 'email_institucional' ? 'sm:col-span-2 lg:col-span-1' : undefined}>
      {label}{requerido && ' *'}
      <input name={key} value={draft[key]} type={key === 'email_institucional' ? 'email' : key === 'fecha_nacimiento' ? 'date' : 'text'} max={key === 'fecha_nacimiento' ? hoyLima() : undefined} inputMode={numerico ? 'numeric' : undefined}
        placeholder={key === 'email_institucional' ? (dominioCorreo ? `nombre-apellido@${dominioCorreo}` : 'correo@dominio') : undefined} maxLength={largo[key] || 80} required={requerido}
        pattern={key === 'numero_doc_identidad' ? '[0-9]{8}' : key === 'telefono' ? '[0-9]{9}' : undefined}
        title={key === 'numero_doc_identidad' ? 'El DNI debe contener 8 dígitos.' : key === 'telefono' ? 'El teléfono debe contener 9 dígitos.' : undefined}
        aria-invalid={erroresPersonaCampo[key] ? true : undefined}
        onChange={event => {
          const valor = numerico ? event.target.value.replace(/\D/g, '').slice(0, largo[key]) : event.target.value
          const cambiaDni = key === 'numero_doc_identidad' && valor !== draft.numero_doc_identidad
          let siguiente = updateUserDraft(draft, key, valor, dominioCorreo)
          if (cambiaDni) siguiente = conPersonaPrevia(siguiente, usuarios)
          if (cambiaDni) { reiniciarIdentidad(valor); setNuevoIngreso(false) }
          // El domicilio que trajo la consulta del DNI anterior no pasa a la persona nueva.
          setDraft(cambiaDni ? domicilioTrasCambioDni(siguiente, identidad.origen) : siguiente)
        }} />
      {key === 'email_institucional' && <small className="text-xs text-slate-500">{dominioCorreo ? 'Se sugiere con el nombre y el apellido; puedes cambiarlo. ' : ''}Se utiliza para enviar requerimientos y notificaciones. Si la persona tiene una cuenta habilitada en el sistema, también puede utilizarse para iniciar sesión.</small>}
      {identidad.origen && draft[key] && identidad.origen.valores[key] === draft[key] && <small className="text-xs font-semibold text-amber-800">{identidad.origen.simulada ? 'Dato simulado (ficticio)' : 'Dato de la consulta de identidad'}</small>}
      {erroresPersonaCampo[key] && <span className="text-rose-700">{[].concat(erroresPersonaCampo[key]).join(' ')}</span>}
    </label>
  }

  // El DNI con su botón de consulta debajo (fuera del label: un botón dentro de un label recibiría sus clics).
  const campoDni = () => <div key="numero_doc_identidad" className="flex min-w-0 flex-col gap-1.5">
    {campo('numero_doc_identidad', 'DNI', true)}
    {modoIdentidad === 'boton' && <div className="flex flex-wrap items-center gap-2">
      <button type="button" onClick={() => consultarDni()} disabled={identidad.cargando} aria-busy={identidad.cargando} className={`inline-flex items-center gap-1.5 ${botonSecundario} disabled:cursor-wait disabled:opacity-60`}>
        <Icon name="search" size={13} />{identidad.cargando ? 'Consultando…' : 'Consultar datos de identidad'}
      </button>
      {estadoIdentidad?.simulada && <small className="text-xs font-semibold text-amber-800">Modo simulado: solo datos ficticios</small>}
    </div>}
    {modoIdentidad === 'no_disponible' && <small className="text-xs text-slate-500">{NO_DISPONIBLE}</small>}
  </div>

  const avisoIdentidad = <div ref={avisoIdentidadRef} className="col-span-full grid gap-2 empty:hidden" aria-live="polite">
    {identidad.cargando && <p role="status" className="text-xs text-slate-600">Consultando datos de identidad… Puedes seguir completando el formulario.</p>}
    {identidad.pendiente && <div role="alert" className={`rounded-lg px-3 py-2.5 text-xs leading-relaxed ${estilosMensaje.info}`}>
      {identidad.pendiente.datos.simulada && <strong className="block">{AVISO_SIMULACION}.</strong>}
      La consulta trae otros datos para {camposPorReemplazar(draft, identidad.pendiente.datos).map(campoNombre => etiquetas[campoNombre]).join(', ') || 'los nombres'}. ¿Reemplazar lo que escribiste?
      <span className="mt-2 flex flex-wrap gap-2">
        <button type="button" className={botonSecundario} onClick={() => aplicarConsulta(identidad.pendiente.dni, identidad.pendiente.datos, identidad.ubicacion)}>Reemplazar</button>
        <button type="button" className={botonSecundario} onClick={() => setIdentidad({ ...identidadInicial, ubicacion: identidad.ubicacion })}>Conservar lo escrito</button>
      </span>
    </div>}
    {/* Mientras se pide confirmación, el aviso de simulación va dentro de esa pregunta: uno solo a la vista. */}
    {identidad.origen?.simulada && !identidad.pendiente && <p role="note" className={`rounded-lg px-3 py-2 text-xs font-semibold ${estilosMensaje.info}`}>{AVISO_SIMULACION}. No acreditan la identidad de ninguna persona.</p>}
    {identidad.mensaje && <p role={identidad.mensaje.tipo === 'error' ? 'alert' : 'status'} className={`rounded-lg px-3 py-2 text-xs ${estilosMensaje[identidad.mensaje.tipo]}`}>{identidad.mensaje.texto}</p>}
  </div>

  // El DNI repetido se muestra con su enlace (PersonaRegistrada), no en la lista general.
  const general = [...avisos, ...[].concat(errores.usuarios || [])].filter(texto => !(existente && /ya está registrada con ese DNI/.test(texto)))
  return <form ref={formRef} onSubmit={enviar} onKeyDown={advanceOnEnter} aria-busy={saving} aria-labelledby="registro-paso-titulo"
    className="flex min-h-0 flex-1 flex-col bg-white [&_label]:flex [&_label]:min-w-0 [&_label]:flex-col [&_label]:gap-1.5 [&_label]:text-xs [&_label]:text-slate-600 [&_input:not([type=file]):not([type=checkbox])]:py-2.5 [&_select]:py-2.5">
    <div className="shrink-0 border-b border-slate-100 px-4 py-3 md:px-6">
      <Progreso paso={paso} onIr={irA} disabled={saving} />
    </div>
    <div ref={contenidoRef} className="min-h-0 flex-1 overflow-y-auto px-4 py-4 md:px-6">
      <h3 id="registro-paso-titulo" className="sr-only">Paso {paso} de {PASOS.length}: {PASOS[paso - 1].titulo}</h3>
      {mensaje?.error && <p role="alert" className="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-xs leading-relaxed text-rose-700">{mensaje.text}</p>}
      {paso === 1 && existente && <PersonaRegistrada persona={existente} puedeVerFicha={puedeVerFicha} puedeRegistrar={puedeRegistrar} nuevoIngreso={nuevoIngreso && !!existente.tieneLegajoActivo} onNuevoIngreso={valor => { setNuevoIngreso(valor); setAvisos([]) }} />}
      {general.length > 0 && <ul role="alert" className="mb-4 list-disc rounded-lg bg-rose-50 px-8 py-3 text-xs leading-relaxed text-rose-700">{general.map(texto => <li key={texto}>{texto}</li>)}</ul>}
      {!puedeRegistrar && <p role="alert" className="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-xs text-rose-700">Tu rol no puede abrir legajos o registrar vínculos: no puedes registrar personal manualmente. La importación sigue disponible.</p>}
      <fieldset disabled={saving || bloqueado} className="grid min-w-0 gap-5">
        {paso === 1 && <>
          <Grupo titulo="Identificación y contacto">
            {camposPersona.filter(([key]) => puedeSensibles || !camposSensibles.includes(key)).map(([key, label, requerido]) => key === 'numero_doc_identidad' ? campoDni() : campo(key, label, requerido))}
            {avisoIdentidad}
          </Grupo>
          <Grupo titulo="Clasificación" ayuda="El tipo de vínculo (carrera, plazo determinado o designado) se indica en el paso 3, con una sugerencia.">
            <label>Tipo de personal *
              <select name="tipo_personal" required value={tipo} aria-invalid={erroresPersonaCampo.tipo_personal ? true : undefined}
                onChange={event => setDraft({ ...draft, tipo_personal: event.target.value, regimen_normativo_id: '' })}>
                <option value="" disabled>Seleccionar tipo de personal</option>
                {Object.entries(tiposPersonal).map(([valor, texto]) => <option key={valor} value={valor}>{texto}</option>)}
              </select>
              {erroresPersonaCampo.tipo_personal && <span className="text-rose-700">{[].concat(erroresPersonaCampo.tipo_personal).join(' ')}</span>}
            </label>
            <label className="sm:col-span-1 lg:col-span-2">Ley / régimen normativo *
              <select name="regimen_normativo_id" required disabled={!catalogo || !tipo} value={leyElegida} aria-invalid={erroresPersonaCampo.regimen_normativo_id ? true : undefined}
                onChange={event => setDraft({ ...draft, regimen_normativo_id: event.target.value })}>
                <option value="" disabled>{!catalogo ? 'Cargando leyes…' : tipo ? 'Seleccionar ley' : 'Primero elige el tipo de personal'}</option>
                {leyesDelTipo(catalogo, tipo).map(ley => <option key={ley.id} value={ley.id}>{ley.nombre}</option>)}
              </select>
              {erroresPersonaCampo.regimen_normativo_id && <span className="text-rose-700">{[].concat(erroresPersonaCampo.regimen_normativo_id).join(' ')}</span>}
              {opciones.error && <span role="alert" className="text-red-700">No se pudo cargar el catálogo de leyes. <button type="button" className="underline" onClick={opciones.retry}>Reintentar</button></span>}
            </label>
          </Grupo>
          <Grupo titulo="Ubicación" ayuda="Opcional; si la indicas, completa región, provincia y distrito.">
            <UbigeoFields value={ubicacion} onChange={(siguiente, id) => setUbicacionEstado(ubicacionManual(siguiente, id))} disabled={saving} />
            {ubicacionEstado.origen && ubicacionEstado.origen !== 'manual' && <small className="col-span-full text-xs font-semibold text-amber-800">{identidad.origen?.simulada ? 'Ubicación simulada (ficticia)' : 'Ubicación de la consulta de identidad'}</small>}
            {identidad.ubicacion && <p role="status" className={`col-span-full rounded-lg px-3 py-2 text-xs ${estilosMensaje.info}`}>{identidad.ubicacion}</p>}
            {puedeSensibles && <div className="col-span-full">{campo('domicilio', 'Domicilio', false)}</div>}
          </Grupo>
          <Grupo titulo="Condiciones de la persona" ayuda="Si no lo sabes, deja «Sin responder».">
            <TresEstados name="alta-tiene_derechohabientes" label="¿Tiene derechohabientes?" ayuda="Cónyuge, concubino/a o hijos." error={erroresPersonaCampo.tiene_derechohabientes}
              value={condiciones.tiene_derechohabientes} onChange={valor => setCondiciones(previas => ({ ...previas, tiene_derechohabientes: valor }))} />
            <TresEstados name="alta-con_discapacidad" label="¿Discapacidad del servidor o de alguno de sus hijos?" error={erroresPersonaCampo.con_discapacidad}
              ayuda={puedeDiscapacidad ? 'Directiva, punto 1 k.' : 'Solo administración de la ORH puede registrarlo.'} disabled={!puedeDiscapacidad}
              value={condiciones.con_discapacidad} onChange={valor => setCondiciones(previas => ({ ...previas, con_discapacidad: valor }))} />
          </Grupo>
        </>}
        {paso === 2 && <>
          <p className="text-xs text-slate-500">Dónde trabajará y en qué puesto. Tipo de personal: <strong>{tiposPersonal[tipo]}</strong> (paso 1). Todo se puede corregir después en la sección 02 mientras el vínculo esté abierto.</p>
          <Grupo titulo="A. Unidad y puesto" ayuda="Escribe para buscar. Si no está en la lista, puedes agregarlo.">
            <CamposUbicacionPuesto draft={vinculo} poner={ponerVinculo} errors={erroresDe(errores, 'vinculo')} opciones={opciones.data?.data} tipoPersonal={tipo} nombre="alta-vinculo" />
          </Grupo>
          <Grupo titulo={`B. Datos del puesto · ${tiposPersonal[tipo]?.toLowerCase() || ''}`}
            ayuda={tipo === 'docente' ? 'Condición, categoría, dedicación (tiempo completo, parcial o exclusiva), función previa y RENACYT.' : 'Grupo ocupacional, nivel de carrera, cargo estructural y si es de confianza.'}>
            {conflictosPuesto.length > 0 && <div role="alert" className={`col-span-full flex flex-wrap items-center justify-between gap-2 rounded-lg px-3 py-2.5 text-xs leading-relaxed ${estilosMensaje.info}`}>
              <span><strong>No coincide con el puesto.</strong> {mensajeConflicto}</span>
              <button type="button" className={botonSecundario} onClick={ajustarPerfilAlPuesto}>Ajustar al puesto</button>
            </div>}
            {!conflictosPuesto.length && notaPuesto && <p role="status" className={`col-span-full rounded-lg px-3 py-2 text-xs ${estilosMensaje.ok}`}>{notaPuesto}</p>}
            <CamposDatosPuesto docente={tipo === 'docente'} draft={datosPuestoVisibles} set={setCampoPuesto} codigoLey={codigoLey} escalas={escalas}
              errors={{ ...erroresDe(errores, 'datos_puesto'), ...Object.fromEntries(conflictosPuesto.map(campo => [campo, ['No coincide con el puesto elegido.']])) }} />
          </Grupo>
          <Grupo titulo="C. Condiciones del puesto" ayuda="Si no lo sabes, deja «Sin responder».">
            <CondicionesPuesto draft={vinculo} poner={ponerVinculo} errors={erroresDe(errores, 'vinculo')} nombre="alta-vinculo" />
          </Grupo>
        </>}
        {paso === 3 && <>
          <p className="text-xs text-slate-500">Cómo se vincula la persona con la universidad. Se registra junto con ella y abre su legajo; la ley es la del paso 1.</p>
          <Grupo titulo="A. Tipo de vínculo" ayuda="Carrera, plazo determinado o designado, y su modalidad (p. ej. CAS). La sugerencia sale de los datos del puesto; puedes elegir otro.">
            <CampoTipoVinculo draft={vinculo} poner={ponerVinculo} errors={erroresDe(errores, 'vinculo')} sugerencia={sugerencia} nombre="alta-vinculo" opciones={opciones.data?.data} tipoPersonal={tipo} />
          </Grupo>
          <Grupo titulo="B. Ingreso" ayuda={vinculo.regimen_laboral === 'designado' ? 'Resolución de designación.' : 'Proceso de selección y fecha de emisión.'}>
            <CamposDocumentoIngreso draft={vinculo} poner={ponerVinculo} errors={erroresDe(errores, 'vinculo')} opciones={opciones.data?.data} numeroLegajo={opciones.data?.data?.siguiente_legajo || ''} nombre="alta-vinculo" />
          </Grupo>
          <Grupo titulo="C. Vigencia">
            <CamposVigencia draft={vinculo} poner={ponerVinculo} errors={erroresDe(errores, 'vinculo')} opciones={opciones.data?.data} nombre="alta-vinculo" />
          </Grupo>
        </>}
        {paso === 4 && <ResumenRegistro nuevoIngreso={nuevoIngreso} persona={personaDelPaso(draft, leyElegida)} ley={(catalogo || []).find(ley => ley.id === leyElegida)?.nombre}
          vinculo={vinculo} datosPuesto={puestos[tipo]} opciones={opciones.data?.data} onIr={irA} />}
      </fieldset>
    </div>
    <div className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-slate-100 bg-white px-4 py-3 md:px-6">
      <small className="text-xs text-slate-500">Paso {paso} de {PASOS.length} · * Campos obligatorios</small>
      <div className="flex flex-wrap items-center gap-2">
        <button type="button" onClick={onCancel} disabled={saving} className="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-xs font-semibold text-slate-600 hover:bg-teal-50">Cancelar</button>
        {paso > 1 && <button type="button" onClick={() => { setAvisos([]); irA(paso - 1) }} disabled={saving} className="rounded-lg border border-slate-200 bg-white px-4 py-2.5 text-xs font-semibold text-slate-600 hover:bg-teal-50">Atrás</button>}
        <button type="submit" disabled={saving || bloqueado || (paso === PASOS.length && !puedeRegistrar)} className="inline-flex items-center gap-2 rounded-lg bg-teal-700 px-4.5 py-2.5 text-xs font-semibold text-white hover:bg-teal-800">
          {paso < PASOS.length ? <>Continuar <Icon name="arrow" size={15} /></> : <><Icon name="plus" size={16} /> {saving ? 'Registrando…' : 'Registrar personal'}</>}
        </button>
      </div>
    </div>
  </form>
}
