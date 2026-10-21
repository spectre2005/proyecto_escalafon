import { useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import Icon from '../../../components/ui/Icon'
import TresEstados from '../../../components/ui/TresEstados'
import UbigeoFields from '../../../components/ui/UbigeoFields'
import { advanceOnEnter } from '../../../shared/forms/advanceOnEnter'
import { CampoTipoVinculo, CamposDatosPuesto, CamposDocumentoIngreso, CamposVinculoBasicos, CondicionesPuesto } from '../../legajos/components/seccion02/CamposVinculo'
import { datosPuestoInicial, vinculoInicial } from '../../legajos/components/seccion02/borradoresVinculo'
import { completarDesdePuesto, equivalenciaPuesto } from '../../legajos/config/puestosSugeridos'
import { categoriasDocente, condicionesDocente } from '../../legajos/config/seccion02'
import { leyesDelTipo, leyInicial } from '../utils/leyes'
import { columns } from '../utils/usuariosCsv'
import { updateUserDraft } from '../utils/usuariosEmail'
import { useDniPersonal } from '../hooks/useDniPersonal'
import { ubicacionDelRegistro } from '../utils/identidadDni'
import { armarRegistro, erroresDe, erroresPersona, PASOS, personaDelPaso, personaExistente, primerPasoConError, rutaFicha, sugerenciaTipoVinculo } from '../utils/registroPasos'

// Registro manual en tres pasos dentro de la ventana «Agregar personal»: persona, primer vínculo laboral y datos del
// puesto. Los valores se conservan al avanzar y retroceder; cada paso se valida antes de continuar y todo se envía en una
// sola operación al final (onGuardar → POST /usuarios/con-vinculo, transaccional).

// Sin selección inicial: el tipo de personal lo elige el usuario. La ley se toma sola solo si su tipo tiene una única ley.
const personaVacia = { ...Object.fromEntries(columns.map(key => [key, ''])), tipo_personal: '', regimen_normativo_id: '' }
const condicionesVacias = { tiene_derechohabientes: null, con_discapacidad: null }
// Datos del puesto de cada tipo por separado: cambiar el tipo de personal no borra lo ya escrito para el otro.
const puestosVacios = () => ({ docente: datosPuestoInicial(true), administrativo: datosPuestoInicial(false) })
const camposNombre = [
  ['nombres', 'Nombres', true], ['apellido_paterno', 'Apellido paterno', true], ['apellido_materno', 'Apellido materno', false],
]
const largo = { numero_doc_identidad: 8, telefono: 9, nombres: 120, email_institucional: 255 }
const tiposPersonal = { docente: 'Docente', administrativo: 'Administrativo' }

function Grupo({ titulo, ayuda, children }) {
  return <fieldset className="grid min-w-0 gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
    <legend className="mb-2 text-[12px] font-semibold text-[#243f43]">{titulo}{ayuda && <span className="ml-2 font-normal text-[#7b9181]">{ayuda}</span>}</legend>
    {children}
  </fieldset>
}

function Progreso({ paso, onIr, disabled }) {
  return <ol className="flex items-center gap-2 sm:gap-3" aria-label="Progreso del registro">
    {PASOS.map(({ numero, titulo, resumen }) => {
      const hecho = numero < paso, actual = numero === paso
      const contenido = <>
        <span className={`grid size-7 shrink-0 place-items-center rounded-full text-[11px] font-semibold ${actual ? 'bg-[#26776d] text-white' : hecho ? 'bg-[#e4f2e9] text-[#2c7650]' : 'bg-[#eef2f0] text-[#8a9b93]'}`}>{hecho ? <Icon name="check" size={14} /> : numero}</span>
        <span className={`min-w-0 text-left ${actual ? '' : 'max-sm:hidden'}`}><span className={`block truncate text-[11px] font-semibold ${actual ? 'text-[#243f43]' : 'text-[#7b9181]'}`}>{titulo}</span><span className="hidden truncate text-[10px] text-[#93a399] lg:block">{resumen}</span></span>
      </>
      // En pantallas pequeñas solo el paso actual muestra su título; los demás, su número.
      return <li key={numero} aria-current={actual ? 'step' : undefined} className={`flex min-w-0 items-center gap-2 ${actual ? 'flex-1' : 'flex-none sm:flex-1'}`}>
        {hecho ? <button type="button" disabled={disabled} onClick={() => onIr(numero)} className="flex min-w-0 items-center gap-2 rounded-lg hover:bg-[#edf6f2]" aria-label={`Volver al paso ${numero}: ${titulo}`}>{contenido}</button>
          : <span className="flex min-w-0 items-center gap-2">{contenido}</span>}
        {numero < PASOS.length && <span aria-hidden="true" className="hidden h-px flex-1 bg-[#e0e9e4] sm:block" />}
      </li>
    })}
  </ol>
}

// Persona ya registrada con ese DNI: no se crea otra. Con permiso para ver legajos, se ofrece abrir su ficha (el registro de
// otro vínculo para un reingreso o una renovación sigue pendiente de confirmar con la universidad).
function PersonaRegistrada({ persona, puedeVerFicha }) {
  return <div role="alert" className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-[#f1d9a6] bg-[#fff8e8] px-4 py-3 text-xs leading-relaxed text-[#7a5a17]">
    <span><strong>{persona.nombre || 'Esta persona'}</strong> ya está registrada con ese DNI. No se creará otro registro.{puedeVerFicha ? ' Revisa su ficha para continuar con su legajo.' : ''}</span>
    {puedeVerFicha && persona.id && <Link to={rutaFicha(persona.id)} className="inline-flex items-center gap-1.5 rounded-lg border border-[#e3c47f] bg-white px-3 py-1.5 font-semibold text-[#7a5a17] hover:bg-[#fff1cf]">Ir a su ficha <Icon name="arrow" size={14} /></Link>}
  </div>
}

export default function RegistroManual({ usuarios, opciones, dominioCorreo = '', puedeDiscapacidad, puedeRegistrar, puedeVerFicha = false, saving, bloqueado, mensaje, onGuardar, onCancel }) {
  const formRef = useRef(null)
  const contenidoRef = useRef(null)
  const [paso, setPaso] = useState(1)
  const [draft, setDraft] = useState(personaVacia)
  const [condiciones, setCondiciones] = useState(condicionesVacias)
  const [ubicacionManual, setUbicacionManual] = useState(null)
  const [vinculo, setVinculo] = useState(vinculoInicial)
  const [puestos, setPuestos] = useState(puestosVacios)
  const [avisos, setAvisos] = useState([])
  const [errores, setErrores] = useState({})
  const [notaPuesto, setNotaPuesto] = useState('')
  // Persona existente informada por el backend al guardar (la lista local puede no estar al día).
  const [existenteBackend, setExistenteBackend] = useState(null)
  const catalogo = opciones.data?.data.regimenes
  const tipo = draft.tipo_personal
  const leyElegida = draft.regimen_normativo_id || leyInicial(catalogo, tipo)
  const erroresPersonaCampo = erroresDe(errores, 'usuarios.0')
  const existenteConocida = personaExistente(draft, usuarios) || (existenteBackend?.dni === draft.numero_doc_identidad.trim() ? existenteBackend.persona : null)
  const consultaDni = useDniPersonal(draft.numero_doc_identidad, setDraft, dominioCorreo, puedeRegistrar && !bloqueado && !existenteConocida)
  const { value: ubicacion, id: ubigeoId } = ubicacionDelRegistro(draft.numero_doc_identidad, ubicacionManual, consultaDni.estado)
  const existente = existenteConocida || (consultaDni.estado.tipo === 'existente' ? consultaDni.estado.persona : null)
  const sugerencia = tipo ? sugerenciaTipoVinculo({ tipoPersonal: tipo, datosPuesto: puestos[tipo], codigoLey: (catalogo || []).find(ley => ley.id === leyElegida)?.codigo }) : null

  const ponerVinculo = key => valor => {
    setVinculo(previo => ({ ...previo, [key]: valor }))
    if (key === 'nombre_puesto') completarPerfil(valor)
  }
  const setCampoVinculo = key => event => ponerVinculo(key)(event.target.value)
  const setCampoPuesto = key => event => {
    const valor = event.target.type === 'checkbox' ? event.target.checked : event.target.value
    setPuestos(previos => ({ ...previos, [tipo]: { ...previos[tipo], [key]: valor } }))
  }

  // Puesto docente con equivalencia inequívoca (p. ej. «Profesor ordinario principal»): completa condición y categoría
  // solo si están vacías; si el usuario ya eligió otra cosa, no se cambia y se avisa.
  function completarPerfil(puesto) {
    const { datos, completados, distintos } = completarDesdePuesto(puestos.docente, equivalenciaPuesto(tipo, puesto))
    const nombres = { condicion_docente: ['condición', condicionesDocente], categoria: ['categoría', categoriasDocente] }
    const texto = campos => campos.map(campo => `${nombres[campo][0]} «${nombres[campo][1][datos[campo]] || datos[campo]}»`).join(' y ')
    if (completados.length) {
      setPuestos(previos => ({ ...previos, docente: datos }))
      setNotaPuesto(`Se completó desde el puesto: ${texto(completados)}. Puedes cambiarlo.`)
    } else {
      setNotaPuesto(distintos.length ? 'El puesto elegido no coincide con la condición o la categoría ya indicadas en el paso 3: revísalas.' : '')
    }
  }

  function irA(numero) {
    setPaso(numero)
    contenidoRef.current?.scrollTo({ top: 0 })
  }

  // Validación del paso: los controles visibles (obligatorios, formatos y fechas) y, en el paso 1, las reglas del alta.
  function pasoValido() {
    if (existente) return false
    if (!formRef.current.reportValidity()) return false
    const lista = paso === 1 ? erroresPersona(personaDelPaso(draft, leyElegida), { catalogo, usuarios, ubicacion, ubigeoId }) : []
    setAvisos(lista)
    return !lista.length
  }

  async function enviar(event) {
    event.preventDefault()
    if (saving || bloqueado || consultaDni.consultando || !pasoValido()) return
    if (paso < PASOS.length) return irA(paso + 1)
    const persona = personaDelPaso(draft, leyElegida)
    const pendientes = erroresPersona(persona, { catalogo, usuarios, ubicacion, ubigeoId })
    if (pendientes.length) { setAvisos(pendientes); return irA(1) }
    setErrores({})
    const resultado = await onGuardar(armarRegistro({ persona, catalogo, ubigeoId, condiciones, puedeDiscapacidad, vinculo, puestos }))
    if (!resultado.ok) {
      setExistenteBackend(resultado.existente ? { dni: persona.numero_doc_identidad, persona: resultado.existente } : null)
      setErrores(resultado.errors || {})
      const destino = primerPasoConError(resultado.errors)
      if (destino) irA(destino)
    }
  }

  const campo = (key, label, requerido, clase) => {
    const numerico = ['numero_doc_identidad', 'telefono'].includes(key)
    return <label key={key} className={clase}>
      {label}{requerido && ' *'}
      <input name={key} value={draft[key]} type={key === 'email_institucional' ? 'email' : 'text'} inputMode={numerico ? 'numeric' : undefined}
        disabled={consultaDni.consultando && ['nombres', 'apellido_paterno', 'apellido_materno'].includes(key)}
        placeholder={key === 'email_institucional' ? (dominioCorreo ? `nombre-apellido@${dominioCorreo}` : 'correo@dominio') : undefined} maxLength={largo[key] || 80} required={requerido}
        pattern={key === 'numero_doc_identidad' ? '[0-9]{8}' : key === 'telefono' ? '[0-9]{9}' : undefined}
        title={key === 'numero_doc_identidad' ? 'El DNI debe contener 8 dígitos.' : key === 'telefono' ? 'El teléfono debe contener 9 dígitos.' : undefined}
        aria-invalid={erroresPersonaCampo[key] ? true : undefined}
        onChange={event => {
          const valor = numerico ? event.target.value.replace(/\D/g, '').slice(0, largo[key]) : event.target.value
          if (key === 'numero_doc_identidad') {
            if (valor !== draft.numero_doc_identidad) setUbicacionManual(null)
            consultaDni.cambiarDni(valor)
          }
          else setDraft(actual => updateUserDraft(actual, key, valor, dominioCorreo))
        }} />
      {key === 'email_institucional' && <small className="text-[10px] text-[#8b9c91]">{dominioCorreo ? 'Se completa con el primer nombre y el apellido paterno. Puedes editarlo.' : 'Escríbelo completo. Para sugerirlo, configura el dominio del correo del personal en Configuración.'}</small>}
      {erroresPersonaCampo[key] && <span className="text-rose-700">{[].concat(erroresPersonaCampo[key]).join(' ')}</span>}
    </label>
  }

  // El DNI repetido se muestra con su enlace (PersonaRegistrada), no en la lista general.
  const general = [...avisos, ...[].concat(errores.usuarios || [])].filter(texto => !(existente && /ya está registrada con ese DNI/.test(texto)))
  return <form ref={formRef} onSubmit={enviar} onKeyDown={advanceOnEnter} aria-busy={saving || consultaDni.consultando} aria-labelledby="registro-paso-titulo"
    className="flex min-h-0 flex-1 flex-col bg-white [&_label]:flex [&_label]:min-w-0 [&_label]:flex-col [&_label]:gap-1.5 [&_label]:text-[11px] [&_label]:text-[#557566] [&_input:not([type=file]):not([type=checkbox])]:py-2.5 [&_select]:py-2.5">
    <div className="shrink-0 border-b border-[#e9efec] px-4 py-3 md:px-6">
      <Progreso paso={paso} onIr={irA} disabled={saving} />
    </div>
    <div ref={contenidoRef} className="min-h-0 flex-1 overflow-y-auto px-4 py-4 md:px-6">
      <h3 id="registro-paso-titulo" className="sr-only">Paso {paso} de {PASOS.length}: {PASOS[paso - 1].titulo}</h3>
      {mensaje?.error && <p role="alert" className="mb-4 rounded-lg bg-[#fff0ed] px-4 py-3 text-xs leading-relaxed text-[#ac4d3a]">{mensaje.text}</p>}
      {paso === 1 && existente && <PersonaRegistrada persona={existente} puedeVerFicha={puedeVerFicha} />}
      {general.length > 0 && <ul role="alert" className="mb-4 list-disc rounded-lg bg-[#fff0ed] px-8 py-3 text-xs leading-relaxed text-[#ac4d3a]">{general.map(texto => <li key={texto}>{texto}</li>)}</ul>}
      {!puedeRegistrar && <p role="alert" className="mb-4 rounded-lg bg-[#fff0ed] px-4 py-3 text-xs text-[#ac4d3a]">Tu rol no puede abrir legajos o registrar vínculos: no puedes registrar personal manualmente. La importación sigue disponible.</p>}
      <fieldset disabled={saving || bloqueado} className="grid min-w-0 gap-5">
        {paso === 1 && <>
          <Grupo titulo="Identificación y contacto">
            {campo('numero_doc_identidad', 'DNI', true)}
            {!existente && <div className={`self-end rounded-lg border px-3 py-2.5 text-[11px] lg:col-span-2 ${consultaDni.estado.tipo === 'error' ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-teal-100 bg-teal-50 text-teal-900'}`}>
              <p role="status" aria-live="polite">{consultaDni.consultando ? 'Buscando nombres y apellidos por DNI…'
                : consultaDni.estado.tipo === 'completado' ? `${consultaDni.estado.origen === 'base_datos' ? 'Nombres recuperados de la base de datos.' : 'Nombres obtenidos de Factiliza.'} Revísalos antes de continuar.`
                  : consultaDni.estado.tipo === 'error' ? consultaDni.estado.mensaje : 'Ingresa los 8 dígitos del DNI para completar nombres y apellidos. También puedes ingresarlos manualmente.'}</p>
              {consultaDni.estado.tipo === 'error' && <button type="button" disabled={consultaDni.consultando} onClick={consultaDni.reintentar} className="mt-2 rounded-lg border border-amber-300 bg-white px-3 py-1.5 font-semibold hover:bg-amber-100">Reintentar consulta</button>}
            </div>}
            <div className="col-span-full grid min-w-0 gap-x-4 gap-y-3 sm:grid-cols-3">
              {camposNombre.map(([key, label, requerido]) => campo(key, label, requerido))}
            </div>
            <UbigeoFields value={ubicacion} onChange={(siguiente, id) => setUbicacionManual({ dni: draft.numero_doc_identidad, value: siguiente, id })} disabled={saving} />
            {consultaDni.estado.tipo === 'completado' && !consultaDni.estado.ubicacion && <p role="status" className="col-span-full text-[11px] text-[#7b9181]">No se obtuvo una ubicación que coincida con el catálogo. Puedes seleccionar región, provincia y distrito manualmente.</p>}
            <div className="col-span-full grid min-w-0 gap-x-4 gap-y-3 sm:grid-cols-3">
              {campo('email_institucional', 'Correo institucional', true, 'sm:col-span-2')}
              {campo('telefono', 'Teléfono', false)}
            </div>
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
          <Grupo titulo="Condiciones de la persona" ayuda="Si no lo sabes, deja «Sin responder».">
            <TresEstados name="alta-tiene_derechohabientes" label="¿Tiene derechohabientes?" ayuda="Cónyuge, concubino/a o hijos." error={erroresPersonaCampo.tiene_derechohabientes}
              value={condiciones.tiene_derechohabientes} onChange={valor => setCondiciones(previas => ({ ...previas, tiene_derechohabientes: valor }))} />
            <TresEstados name="alta-con_discapacidad" label="¿Discapacidad del servidor o de alguno de sus hijos?" error={erroresPersonaCampo.con_discapacidad}
              ayuda={puedeDiscapacidad ? 'Directiva, punto 1 k.' : 'Solo administración de la ORH puede registrarlo.'} disabled={!puedeDiscapacidad}
              value={condiciones.con_discapacidad} onChange={valor => setCondiciones(previas => ({ ...previas, con_discapacidad: valor }))} />
          </Grupo>
        </>}
        {paso === 2 && <>
          <p className="text-[11px] text-[#7b9181]">Resolución o contrato con el que la persona está vinculada. Se registra junto con ella y abre su legajo. Tipo de personal: <strong>{tiposPersonal[tipo]}</strong>; la ley es la del paso 1.</p>
          <Grupo titulo="A. Información del vínculo" ayuda="Las sugerencias de puesto son provisionales.">
            <CamposVinculoBasicos draft={vinculo} set={setCampoVinculo} poner={ponerVinculo} errors={erroresDe(errores, 'vinculo')} opciones={opciones.data?.data} tipoPersonal={tipo} conTipo={false} nombre="alta-vinculo" />
            {notaPuesto && <p role="status" className="col-span-full rounded-lg bg-[#eaf4ee] px-3 py-2 text-[11px] text-[#2c7650]">{notaPuesto}</p>}
          </Grupo>
          <Grupo titulo="B. Documento de ingreso">
            <CamposDocumentoIngreso draft={vinculo} set={setCampoVinculo} errors={erroresDe(errores, 'vinculo')} />
          </Grupo>
        </>}
        {paso === 3 && <>
          <Grupo titulo={`Datos del puesto · ${tiposPersonal[tipo]?.toLowerCase() || ''}`} ayuda={`Puesto: ${vinculo.nombre_puesto || 'sin indicar'}. Se pueden corregir después en la sección 02 mientras el vínculo esté abierto.`}>
            <CamposDatosPuesto docente={tipo === 'docente'} draft={puestos[tipo]} set={setCampoPuesto} errors={erroresDe(errores, 'datos_puesto')} />
            {notaPuesto && tipo === 'docente' && <p role="status" className="col-span-full rounded-lg bg-[#eaf4ee] px-3 py-2 text-[11px] text-[#2c7650]">{notaPuesto}</p>}
          </Grupo>
          <Grupo titulo="Tipo de vínculo" ayuda="Carrera, plazo determinado o designado. La sugerencia sale de los datos anteriores; puedes elegir otro.">
            <CampoTipoVinculo draft={vinculo} set={setCampoVinculo} poner={ponerVinculo} errors={erroresDe(errores, 'vinculo')} sugerencia={sugerencia} nombre="alta-vinculo" />
          </Grupo>
          <Grupo titulo="Condiciones del puesto" ayuda="Si no lo sabes, deja «Sin responder».">
            <CondicionesPuesto draft={vinculo} poner={ponerVinculo} errors={erroresDe(errores, 'vinculo')} nombre="alta-vinculo" />
          </Grupo>
        </>}
      </fieldset>
    </div>
    <div className="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-[#e9efec] bg-white px-4 py-3 md:px-6">
      <small className="text-[10px] text-[#8b9c91]">Paso {paso} de {PASOS.length} · * Campos obligatorios</small>
      <div className="flex flex-wrap items-center gap-2">
        <button type="button" onClick={onCancel} disabled={saving} className="rounded-lg border border-[#dbe6e1] bg-white px-4 py-2.5 text-xs font-semibold text-[#4f6d63] hover:bg-[#edf6f2]">Cancelar</button>
        {paso > 1 && <button type="button" onClick={() => { setAvisos([]); irA(paso - 1) }} disabled={saving} className="rounded-lg border border-[#dbe6e1] bg-white px-4 py-2.5 text-xs font-semibold text-[#4f6d63] hover:bg-[#edf6f2]">Atrás</button>}
        <button type="submit" disabled={saving || bloqueado || consultaDni.consultando || Boolean(existente) || (paso === PASOS.length && !puedeRegistrar)} className="inline-flex items-center gap-2 rounded-lg bg-[#26776d] px-4.5 py-2.5 text-xs font-semibold text-white hover:bg-[#1b625a] disabled:cursor-not-allowed disabled:opacity-50">
          {paso < PASOS.length ? <>Continuar <Icon name="arrow" size={15} /></> : <><Icon name="plus" size={16} /> {saving ? 'Registrando…' : 'Registrar personal'}</>}
        </button>
      </div>
    </div>
  </form>
}
