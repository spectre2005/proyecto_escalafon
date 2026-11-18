import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import Icon from '../../../components/ui/Icon'
import TresEstados from '../../../components/ui/TresEstados'
import UbigeoFields from '../../../components/ui/UbigeoFields'
import { advanceOnEnter } from '../../../shared/forms/advanceOnEnter'
import { CampoTipoVinculo, CamposDatosPuesto, CamposDocumentoIngreso, CamposUbicacionPuesto, CamposVigencia, CondicionesPuesto } from '../../legajos/components/seccion02/CamposVinculo'
import { datosPuestoInicial, vinculoInicial } from '../../legajos/components/seccion02/borradoresVinculo'
import { ajustarAPuesto, diferenciasConPuesto, equivalenciaPuesto, sincronizarConPuesto } from '../../legajos/config/puestosSugeridos'
import { categoriasDocente, condicionesDocente, nivelTrasCambiarGrupo, opcionesNivelCarrera } from '../../legajos/config/seccion02'
import { leyesDelTipo, leyInicial } from '../utils/leyes'
import { columns, hoyLima } from '../utils/usuariosCsv'
import { updateUserDraft } from '../utils/usuariosEmail'
import { consultarIdentidad, estadoConsultaIdentidad } from '../api/usuariosApi'
import { usePuede } from '../../auth/session'
import { AVISO_SIMULACION, aplicarIdentidad, camposPorReemplazar, consultarAlCompletar, crearConsultaIdentidad, domicilioTrasCambioDni, ESPERA_CONSULTA_MS, mensajeErrorConsulta, modoConsulta, mensajeUbicacion, NO_DISPONIBLE, UBICACION_VACIA, ubicacionManual, ubicacionTrasCambioDni, ubicacionTrasConsulta, valoresIdentidad } from '../utils/consultaIdentidad'
import ResumenLateral from './ResumenLateral'
import ResumenRegistro from './ResumenRegistro'
import TarjetasOpcion from './TarjetasOpcion'
import { armarRegistro, condicionesSinResponder, DESCRIPCION_LEY, erroresDe, erroresPersona, PASOS, personaDelPaso, personaExistente, primerPasoConError, rutaFicha, sugerenciaTipoVinculo, tituloLey } from '../utils/registroPasos'

// Registro manual en tres pasos dentro de la ventana «Agregar personal»: persona (el DNI primero; tipo y ley en tarjetas),
// puesto y vínculo juntos, y revisión. Lo opcional va plegado y, en pantallas anchas, un resumen a la derecha muestra lo
// completado. Los valores se conservan al avanzar y retroceder; cada paso se valida antes de continuar y todo se envía en
// una sola operación al final (onGuardar → POST /usuarios/con-vinculo, transaccional).

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
const largo = { numero_doc_identidad: 8, telefono: 9, nombres: 120, email_institucional: 255, domicilio: 250 }
const opcionesTipo = [
  { valor: 'docente', titulo: 'Docente', descripcion: 'Ordinario, contratado o extraordinario · Ley 30220' },
  { valor: 'administrativo', titulo: 'Administrativo', descripcion: 'Profesional, técnico o auxiliar' },
]
// Campos de los bloques plegables: si alguno trae un error, el bloque se abre solo.
const enMasDatos = ['telefono', 'fecha_nacimiento', 'domicilio', 'ubigeo_id', 'tiene_derechohabientes', 'con_discapacidad']
const etiquetas = { ...Object.fromEntries(camposPersona.map(([key, label]) => [key, label.toLowerCase()])), domicilio: 'domicilio' }
const identidadInicial = { cargando: false, mensaje: null, origen: null, pendiente: null, ubicacion: null }
const estilosMensaje = { ok: 'bg-teal-50 text-teal-800', info: 'border border-amber-200 bg-amber-50 text-amber-800', error: 'bg-rose-50 text-rose-700' }
const botonSecundario = 'rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-teal-50'

const nombresPerfil = { condicion_docente: ['condición', condicionesDocente], categoria: ['categoría', categoriasDocente] }
// «condición «Ordinario» y categoría «Asociado»» con los valores de `datos`.
const textoCampos = (campos, datos) => campos.map(campo => `${nombresPerfil[campo][0]} «${nombresPerfil[campo][1][datos[campo]] || datos[campo]}»`).join(' y ')

// Bloque de un paso: tarjeta con título, una línea de ayuda y sus campos en rejilla.
function Seccion({ titulo, ayuda, children, columnas = 'sm:grid-cols-2 lg:grid-cols-3' }) {
  return <section className="grid min-w-0 gap-4 rounded-2xl border border-slate-200 bg-white p-5 sm:p-6">
    <div>
      <h4 className="text-[17px] font-semibold text-slate-800">{titulo}</h4>
      {ayuda && <p className="mt-1 text-sm text-slate-500">{ayuda}</p>}
    </div>
    <div className={`grid min-w-0 gap-x-4 gap-y-4 ${columnas}`}>{children}</div>
  </section>
}

// Lo opcional, plegado: el resumen dice qué contiene para que se sepa si hace falta abrirlo.
function Plegable({ titulo, detalle, abierto, onCambiar, children }) {
  return <details open={abierto} onToggle={event => onCambiar(event.currentTarget.open)} className="group min-w-0 rounded-2xl border border-slate-200 bg-white">
    <summary className="flex cursor-pointer list-none items-center justify-between gap-3 px-5 py-4 sm:px-6">
      <span className="text-[15px] font-semibold text-slate-700">{titulo} <span className="font-normal text-slate-500">({detalle})</span></span>
      <span aria-hidden="true" className="text-slate-500 transition-transform group-open:rotate-90"><Icon name="arrow" size={16} /></span>
    </summary>
    <div className="grid min-w-0 gap-x-4 gap-y-4 border-t border-slate-100 px-5 py-5 sm:grid-cols-2 sm:px-6 lg:grid-cols-3">{children}</div>
  </details>
}

// Los pasos como tarjetas: el actual con borde, los hechos con su marca (se puede volver a ellos) y los siguientes, atenuados.
function Progreso({ paso, onIr, disabled }) {
  return <ol className="grid gap-2 sm:grid-cols-3" aria-label="Progreso del registro">
    {PASOS.map(({ numero, titulo, resumen }) => {
      const hecho = numero < paso, actual = numero === paso
      const contenido = <>
        <span className={`grid size-7 shrink-0 place-items-center rounded-full text-[13px] font-bold ${actual ? 'bg-teal-700 text-white' : hecho ? 'bg-teal-100 text-teal-700' : 'bg-slate-100 text-slate-600'}`}>{hecho ? <Icon name="check" size={15} /> : numero}</span>
        <span className="min-w-0 text-left">
          <span className={`block truncate text-sm font-semibold ${actual ? 'text-slate-800' : hecho ? 'text-teal-900' : 'text-slate-600'}`}>{titulo}</span>
          <span className={`block truncate text-xs ${hecho ? 'text-teal-800' : 'text-slate-500'}`}>{resumen}</span>
        </span>
      </>
      const caja = `flex w-full min-w-0 items-center gap-2.5 rounded-xl bg-white px-4 py-3 ${actual ? 'border-2 border-teal-700' : hecho ? 'border border-teal-200 hover:bg-teal-50' : 'border border-slate-200'}`
      // En pantallas pequeñas solo se muestra el paso actual.
      return <li key={numero} aria-current={actual ? 'step' : undefined} className={actual ? '' : 'max-sm:hidden'}>
        {hecho ? <button type="button" disabled={disabled} onClick={() => onIr(numero)} className={caja} aria-label={`Volver al paso ${numero}: ${titulo}`}>{contenido}</button>
          : <span className={caja}>{contenido}</span>}
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
  // Bloques plegables: «Más datos» de la persona (paso 1) y condiciones del puesto (paso 2).
  const [masDatos, setMasDatos] = useState(false)
  const [verCondiciones, setVerCondiciones] = useState(false)
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
    // Un campo con error dentro de un bloque plegado: se abre para que el navegador pueda señalarlo.
    const invalido = formRef.current.querySelector('input:invalid, select:invalid, textarea:invalid')
    const plegado = invalido?.closest('details')
    if (plegado && !plegado.open) plegado.open = true
    if (!formRef.current.reportValidity()) return false
    const lista = paso === 1 ? erroresPersona(personaDelPaso(draft, leyElegida), { catalogo, usuarios, ubicacion, ubigeoId, nuevoIngreso })
      : paso === 2 ? [!vinculo.regimen_laboral && 'Elige el tipo de vínculo: carrera, plazo determinado o designado.',
        conflictosPuesto.length && `${mensajeConflicto} Ajusta la condición o la categoría, o elige otro puesto, para continuar.`].filter(Boolean) : []
    if (paso === 1 && Object.values(ubicacion).some(Boolean) && !ubigeoId) setMasDatos(true)
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
    return <label key={key} className={key === 'email_institucional' ? 'sm:col-span-2 lg:col-span-3' : undefined}>
      {label}{requerido && ' *'}
      <input name={key} value={draft[key]} className={key === 'numero_doc_identidad' ? 'text-xl! font-semibold tracking-wider border-2! border-teal-700!' : identidad.origen && draft[key] && identidad.origen.valores[key] === draft[key] ? 'bg-teal-50!' : undefined} type={key === 'email_institucional' ? 'email' : key === 'fecha_nacimiento' ? 'date' : 'text'} max={key === 'fecha_nacimiento' ? hoyLima() : undefined} inputMode={numerico ? 'numeric' : undefined}
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
      {identidad.origen?.simulada && draft[key] && identidad.origen.valores[key] === draft[key] && <small className="text-xs font-semibold text-amber-800">Dato simulado (ficticio)</small>}
      {erroresPersonaCampo[key] && <span className="text-rose-700">{[].concat(erroresPersonaCampo[key]).join(' ')}</span>}
    </label>
  }

  // El DNI con su botón de consulta debajo (fuera del label: un botón dentro de un label recibiría sus clics).
  const campoDni = () => <div key="numero_doc_identidad" className="flex min-w-0 flex-col gap-1.5 sm:col-span-2 lg:col-span-3 lg:max-w-xs">
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
  const leyes = leyesDelTipo(catalogo, tipo).map(ley => ({ valor: ley.id, titulo: tituloLey(ley), descripcion: DESCRIPCION_LEY[ley.codigo] || ley.nombre }))
  const nombreLey = (catalogo || []).find(ley => ley.id === leyElegida)?.nombre
  const erroresVinculo = erroresDe(errores, 'vinculo')
  const sinResponder = condicionesSinResponder({ vinculo, condiciones, puedeDiscapacidad })
  const condicionesPuestoPendientes = condicionesSinResponder({ vinculo, condiciones: {} }).length
  const nivelVisible = tipo === 'administrativo' ? opcionesNivelCarrera(escalas, codigoLey, datosPuestoVisibles.grupo_ocupacional, datosPuestoVisibles.nivel_carrera)[datosPuestoVisibles.nivel_carrera] || '' : ''
  const masDatosAbierto = masDatos || enMasDatos.some(clave => erroresPersonaCampo[clave])
  const condicionesAbiertas = verCondiciones || ['obligado_djbr', 'exige_colegiatura', 'exige_estudios_basicos', 'tiene_periodo_prueba'].some(clave => erroresVinculo[clave])
  const detalleMasDatos = ['teléfono', puedeSensibles && 'nacimiento', 'ubicación', puedeSensibles && 'domicilio', 'familia y discapacidad'].filter(Boolean).join(', ')
  const siguiente = { 1: 'Continuar con el puesto', 2: 'Revisar' }[paso]

  return <form ref={formRef} onSubmit={enviar} onKeyDown={advanceOnEnter} aria-busy={saving} aria-labelledby="registro-paso-titulo"
    className="flex min-h-0 flex-1 flex-col bg-slate-50 [&_label]:flex [&_label]:min-w-0 [&_label]:flex-col [&_label]:gap-1.5 [&_label]:text-[13px] [&_label]:font-semibold [&_label]:text-slate-700 [&_input:not([type=file]):not([type=checkbox]):not([type=radio])]:rounded-xl [&_input:not([type=file]):not([type=checkbox]):not([type=radio])]:border-slate-300 [&_input:not([type=file]):not([type=checkbox]):not([type=radio])]:bg-white [&_input:not([type=file]):not([type=checkbox]):not([type=radio])]:py-2.5 [&_input:not([type=file]):not([type=checkbox]):not([type=radio])]:text-[15px] [&_input:not([type=file]):not([type=checkbox]):not([type=radio])]:font-normal [&_select:not([multiple])]:rounded-xl [&_select:not([multiple])]:border-slate-300 [&_select:not([multiple])]:bg-white [&_select:not([multiple])]:py-2.5 [&_select:not([multiple])]:text-[15px] [&_select:not([multiple])]:font-normal [&_small]:font-normal">
    <div className="shrink-0 border-b border-slate-200 bg-white px-4 py-4 md:px-6">
      <Progreso paso={paso} onIr={irA} disabled={saving} />
    </div>
    <div ref={contenidoRef} className="min-h-0 flex-1 overflow-y-auto px-4 py-5 md:px-6">
      <h3 id="registro-paso-titulo" className="sr-only">Paso {paso} de {PASOS.length}: {PASOS[paso - 1].titulo}</h3>
      {mensaje?.error && <p role="alert" className="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-xs leading-relaxed text-rose-700">{mensaje.text}</p>}
      {paso === 1 && existente && <PersonaRegistrada persona={existente} puedeVerFicha={puedeVerFicha} puedeRegistrar={puedeRegistrar} nuevoIngreso={nuevoIngreso && !!existente.tieneLegajoActivo} onNuevoIngreso={valor => { setNuevoIngreso(valor); setAvisos([]) }} />}
      {general.length > 0 && <ul role="alert" className="mb-4 list-disc rounded-xl bg-rose-50 px-8 py-3 text-xs leading-relaxed text-rose-700">{general.map(texto => <li key={texto}>{texto}</li>)}</ul>}
      {!puedeRegistrar && <p role="alert" className="mb-4 rounded-xl bg-rose-50 px-4 py-3 text-xs text-rose-700">Tu rol no puede abrir legajos o registrar vínculos: no puedes registrar personal manualmente. La importación sigue disponible.</p>}
      <div className={paso < PASOS.length ? 'grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_18.5rem]' : ''}>
        <fieldset disabled={saving || bloqueado} className="grid min-w-0 gap-5">
          {paso === 1 && <>
            <Seccion titulo="Empieza por el DNI" ayuda={modoIdentidad === 'boton' ? 'Con el DNI se completan nombres y apellidos; puedes corregirlos.' : 'Escribe los datos tal como figuran en su documento de identidad.'}>
              {campoDni()}
              {campo('nombres', 'Nombres', true)}
              {campo('apellido_paterno', 'Apellido paterno', true)}
              {campo('apellido_materno', 'Apellido materno', false)}
              {campo('email_institucional', 'Correo electrónico', true)}
              {avisoIdentidad}
            </Seccion>
            <Seccion titulo="¿Qué tipo de personal es?" ayuda="De esto dependen los documentos que se exigirán en su legajo." columnas="">
              <TarjetasOpcion id="alta-tipo" etiqueta="Tipo de personal *" opciones={opcionesTipo} valor={tipo} error={erroresPersonaCampo.tipo_personal}
                onElegir={valor => { if (valor !== tipo) setDraft({ ...draft, tipo_personal: valor, regimen_normativo_id: '' }) }} />
              <TarjetasOpcion id="alta-ley" etiqueta="Ley o régimen *" opciones={leyes} valor={leyElegida} error={erroresPersonaCampo.regimen_normativo_id} columnas="grid-cols-2 lg:grid-cols-4" conMarca={false}
                vacio={!catalogo ? 'Cargando leyes…' : tipo ? 'No hay leyes activas para este tipo de personal.' : 'Primero elige el tipo de personal.'}
                onElegir={valor => setDraft({ ...draft, regimen_normativo_id: valor })} />
              {opciones.error && <p role="alert" className="text-xs text-rose-700">No se pudo cargar el catálogo de leyes. <button type="button" className="underline" onClick={opciones.retry}>Reintentar</button></p>}
            </Seccion>
            <Plegable titulo="Más datos" detalle={`opcional · ${detalleMasDatos}`} abierto={masDatosAbierto} onCambiar={setMasDatos}>
              {campo('telefono', 'Teléfono', false)}
              {puedeSensibles && campo('fecha_nacimiento', 'Fecha de nacimiento', false)}
              <div className="col-span-full grid gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                <UbigeoFields value={ubicacion} onChange={(nueva, id) => setUbicacionEstado(ubicacionManual(nueva, id))} disabled={saving} />
              </div>
              {ubicacionEstado.origen && ubicacionEstado.origen !== 'manual' && identidad.origen?.simulada && <small className="col-span-full text-xs font-semibold text-amber-800">Ubicación simulada (ficticia)</small>}
              {identidad.ubicacion && <p role="status" className={`col-span-full rounded-lg px-3 py-2 text-xs ${estilosMensaje.info}`}>{identidad.ubicacion}</p>}
              {puedeSensibles && <div className="col-span-full">{campo('domicilio', 'Domicilio', false)}</div>}
              <div className="col-span-full grid gap-3 sm:grid-cols-2">
                <TresEstados name="alta-tiene_derechohabientes" label="¿Tiene derechohabientes?" ayuda="Cónyuge, concubino/a o hijos." error={erroresPersonaCampo.tiene_derechohabientes}
                  value={condiciones.tiene_derechohabientes} onChange={valor => setCondiciones(previas => ({ ...previas, tiene_derechohabientes: valor }))} />
                <TresEstados name="alta-con_discapacidad" label="¿Discapacidad del servidor o de alguno de sus hijos?" error={erroresPersonaCampo.con_discapacidad}
                  ayuda={puedeDiscapacidad ? 'Si no lo sabes, deja «Sin responder».' : 'Solo administración de la ORH puede registrarlo.'} disabled={!puedeDiscapacidad}
                  value={condiciones.con_discapacidad} onChange={valor => setCondiciones(previas => ({ ...previas, con_discapacidad: valor }))} />
              </div>
            </Plegable>
          </>}
          {paso === 2 && <>
            <Seccion titulo="¿Dónde y en qué puesto trabajará?" ayuda="Escribe para buscar. Si no está en la lista, puedes agregarlo." columnas="sm:grid-cols-2">
              <CamposUbicacionPuesto draft={vinculo} poner={ponerVinculo} errors={erroresVinculo} opciones={opciones.data?.data} tipoPersonal={tipo} nombre="alta-vinculo" />
            </Seccion>
            <Seccion titulo={tipo === 'docente' ? 'Condición y categoría docente' : 'Grupo y nivel de carrera'}
              ayuda={tipo === 'docente' ? 'Condición, categoría, dedicación, función previa y RENACYT.' : 'Usa el nivel que figura en su resolución de ingreso. También el cargo estructural y si es de confianza.'}
              columnas={tipo === 'docente' ? 'sm:grid-cols-2 lg:grid-cols-3' : 'sm:grid-cols-2'}>
              {conflictosPuesto.length > 0 && <div role="alert" className={`col-span-full flex flex-wrap items-center justify-between gap-2 rounded-lg px-3 py-2.5 text-xs leading-relaxed ${estilosMensaje.info}`}>
                <span><strong>No coincide con el puesto.</strong> {mensajeConflicto}</span>
                <button type="button" className={botonSecundario} onClick={ajustarPerfilAlPuesto}>Ajustar al puesto</button>
              </div>}
              {!conflictosPuesto.length && notaPuesto && <p role="status" className={`col-span-full rounded-lg px-3 py-2 text-xs ${estilosMensaje.ok}`}>{notaPuesto}</p>}
              <CamposDatosPuesto docente={tipo === 'docente'} draft={datosPuestoVisibles} set={setCampoPuesto} codigoLey={codigoLey} escalas={escalas} grupoEnBotones
                errors={{ ...erroresDe(errores, 'datos_puesto'), ...Object.fromEntries(conflictosPuesto.map(campo => [campo, ['No coincide con el puesto elegido.']])) }} />
            </Seccion>
            <Seccion titulo="Vínculo laboral" ayuda={`Tipo de vínculo, documento de ingreso y fechas${nombreLey ? ` · ${nombreLey}` : ''}. La sugerencia sale de la ley y del puesto; puedes elegir otro.`} columnas="">
              <div className="grid min-w-0 gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
                <CampoTipoVinculo draft={vinculo} poner={ponerVinculo} errors={erroresVinculo} sugerencia={sugerencia} nombre="alta-vinculo" opciones={opciones.data?.data} tipoPersonal={tipo} enTarjetas />
              </div>
              <div className="grid min-w-0 gap-x-4 gap-y-3 border-t border-slate-100 pt-4 sm:grid-cols-2 lg:grid-cols-3">
                <CamposDocumentoIngreso draft={vinculo} poner={ponerVinculo} errors={erroresVinculo} opciones={opciones.data?.data} numeroLegajo={opciones.data?.data?.siguiente_legajo || ''} nombre="alta-vinculo" />
              </div>
              <div className="grid min-w-0 gap-x-4 gap-y-3 border-t border-slate-100 pt-4 sm:grid-cols-2 lg:grid-cols-3">
                <CamposVigencia draft={vinculo} poner={ponerVinculo} errors={erroresVinculo} opciones={opciones.data?.data} nombre="alta-vinculo" />
              </div>
            </Seccion>
            <Plegable titulo="Condiciones del puesto" detalle={condicionesPuestoPendientes ? `opcional · ${condicionesPuestoPendientes} sin responder` : 'opcional · todas respondidas'} abierto={condicionesAbiertas} onCambiar={setVerCondiciones}>
              <div className="col-span-full">
                <CondicionesPuesto draft={vinculo} poner={ponerVinculo} errors={erroresVinculo} nombre="alta-vinculo" />
              </div>
            </Plegable>
          </>}
          {paso === 3 && <ResumenRegistro nuevoIngreso={nuevoIngreso} persona={personaDelPaso(draft, leyElegida)} ley={nombreLey} nivel={nivelVisible}
            vinculo={vinculo} datosPuesto={datosPuestoVisibles} opciones={opciones.data?.data} sinResponder={sinResponder} onIr={irA} />}
        </fieldset>
        {paso < PASOS.length && <div className="hidden lg:sticky lg:top-0 lg:block">
          <ResumenLateral persona={personaDelPaso(draft, leyElegida)} ley={nombreLey} codigoLey={codigoLey} vinculo={vinculo} datosPuesto={datosPuestoVisibles}
            opciones={opciones.data?.data} escalas={escalas} sinResponder={sinResponder} />
        </div>}
      </div>
    </div>
    <div className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-white px-4 py-3.5 md:px-6">
      <small className="text-[13px] text-slate-500">Paso {paso} de {PASOS.length} · * obligatorio · se guarda solo al registrar</small>
      <div className="flex flex-wrap items-center gap-2">
        <button type="button" onClick={onCancel} disabled={saving} className="rounded-xl border border-slate-300 bg-white px-4.5 py-3 text-sm font-semibold text-slate-700 hover:bg-teal-50">Cancelar</button>
        {paso > 1 && <button type="button" onClick={() => { setAvisos([]); irA(paso - 1) }} disabled={saving} className="rounded-xl border border-slate-300 bg-white px-4.5 py-3 text-sm font-semibold text-slate-700 hover:bg-teal-50">Atrás</button>}
        <button type="submit" disabled={saving || bloqueado || (paso === PASOS.length && !puedeRegistrar)} className="inline-flex items-center gap-2 rounded-xl bg-teal-700 px-5.5 py-3 text-sm font-semibold text-white hover:bg-teal-800">
          {paso < PASOS.length ? <>{siguiente} <Icon name="arrow" size={15} /></> : <><Icon name="plus" size={16} /> {saving ? 'Registrando…' : 'Registrar personal'}</>}
        </button>
      </div>
    </div>
  </form>
}
