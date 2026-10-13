import { useEffect, useRef, useState } from 'react'
import { Link, useLocation, useParams } from 'react-router-dom'
import { activarInstitucion, aprovisionarInstitucion, editarInstitucion, mensajeError, sesionTerminada, suspenderInstitucion, verInstitucion } from '../api'
import { Aviso, Credenciales, EstadoInstitucion, SinAprovisionar } from '../componentes'
import AdministradoresInstitucion from './AdministradoresInstitucion'
import { AVISO_SUSPENSION, accionesDisponibles, cambios, etiquetaAccion, fecha } from '../estado'
import { useSesionPlataforma } from '../sesion'

const entrada = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-800 disabled:bg-slate-50 disabled:text-slate-500'

function Dato({ etiqueta, children }) {
  return <div><dt className="text-xs text-slate-500">{etiqueta}</dt><dd className="mt-1 break-all text-sm text-slate-800">{children || '—'}</dd></div>
}

/** Confirmación de suspender o activar. Suspender exige motivo; los datos y archivos de la institución se conservan. */
function ConfirmarEstado({ accion, institucion, onConfirmar, onCerrar }) {
  const ref = useRef(null)
  const [motivo, setMotivo] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  useEffect(() => { ref.current?.showModal() }, [])
  const suspender = accion === 'suspender'
  async function confirmar(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    try {
      await onConfirmar(motivo.trim())
    } catch (failure) {
      setError(mensajeError(failure))
      setBusy(false)
    }
  }
  return <dialog ref={ref} onClose={onCerrar} aria-labelledby="titulo-confirmar" className="m-auto w-full max-w-md rounded-2xl p-0 backdrop:bg-slate-900/40">
    <form onSubmit={confirmar} aria-busy={busy} className="space-y-4 p-6">
      <h2 id="titulo-confirmar" className="text-lg font-bold text-slate-900">{suspender ? 'Suspender' : accionesDisponibles(institucion).textoActivar} «{institucion.nombre}»</h2>
      <p className="text-sm text-slate-600">{suspender ? 'Su base de datos, sus archivos y sus datos se conservan: nada se borra.' : 'La institución quedará activa. Solo es posible porque su aprovisionamiento está verificado.'}</p>
      {suspender && !institucion.suspension_bloquea_acceso && <Aviso tipo="info">{AVISO_SUSPENSION}</Aviso>}
      <Aviso>{error}</Aviso>
      <label className="grid gap-2 text-xs font-medium text-slate-600">{suspender ? 'Motivo *' : 'Motivo (opcional)'}<textarea required={suspender} minLength={suspender ? 5 : undefined} maxLength={500} rows={3} value={motivo} onChange={event => setMotivo(event.target.value)} disabled={busy} className={entrada} /></label>
      <div className="flex justify-end gap-3"><button type="button" onClick={() => ref.current.close()} disabled={busy} className="rounded-xl border border-slate-200 px-4 py-2 text-sm">Cancelar</button><button type="submit" disabled={busy} className={`rounded-xl px-4 py-2 text-sm font-semibold text-white ${suspender ? 'bg-rose-700' : 'bg-teal-700'}`}>{busy ? 'Guardando…' : suspender ? 'Suspender' : 'Confirmar'}</button></div>
    </form>
  </dialog>
}

/**
 * Aprovisionar: pide el primer administrador (jefe de la ORH) y crea la base, los catálogos y la carpeta. Toma unos
 * segundos; mientras tanto no se puede cerrar. Un 500 trae un mensaje propio (se retiró lo creado).
 */
function Aprovisionar({ institucion, onAprovisionada, onSesionTerminada, onCerrar }) {
  const ref = useRef(null)
  const [datos, setDatos] = useState({ nombres: '', apellido_paterno: '', email: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  useEffect(() => { ref.current?.showModal() }, [])
  async function confirmar(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    try {
      onAprovisionada(await aprovisionarInstitucion(institucion.id, { ...datos, email: datos.email.trim() }))
    } catch (failure) {
      setError(failure.response?.status === 500 && failure.response.data?.message ? failure.response.data.message : mensajeError(failure))
      setBusy(false)
      if (sesionTerminada(failure)) onSesionTerminada()
    }
  }
  const campo = (nombre, etiqueta, props = {}) => <label className="grid gap-2 text-xs font-medium text-slate-600">{etiqueta}<input value={datos[nombre]} onChange={event => setDatos({ ...datos, [nombre]: event.target.value })} disabled={busy} required className={entrada} {...props} /></label>
  return <dialog ref={ref} onClose={onCerrar} onCancel={event => busy && event.preventDefault()} aria-labelledby="titulo-aprovisionar" className="m-auto w-full max-w-md rounded-2xl p-0 backdrop:bg-slate-900/40">
    <form onSubmit={confirmar} aria-busy={busy} className="space-y-4 p-6">
      <h2 id="titulo-aprovisionar" className="text-lg font-bold text-slate-900">Aprovisionar «{institucion.nombre}»</h2>
      <p className="text-sm text-slate-600">Se crea la base <strong>{institucion.base_datos}</strong> con los catálogos (sin datos de ejemplo), la carpeta <strong>{institucion.almacenamiento}</strong> y su primer administrador. La institución no se activa todavía.</p>
      <Aviso>{error}</Aviso>
      {campo('nombres', 'Nombres del primer administrador *', { minLength: 2, maxLength: 100, autoComplete: 'off' })}
      {campo('apellido_paterno', 'Apellido paterno *', { minLength: 2, maxLength: 100, autoComplete: 'off' })}
      {campo('email', 'Correo de acceso *', { type: 'email', maxLength: 255, autoComplete: 'off' })}
      <div className="flex justify-end gap-3"><button type="button" onClick={() => ref.current.close()} disabled={busy} className="rounded-xl border border-slate-200 px-4 py-2 text-sm">Cancelar</button><button type="submit" disabled={busy} className="rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white">{busy ? 'Creando base y catálogos…' : 'Aprovisionar'}</button></div>
    </form>
  </dialog>
}

export default function InstitucionDetallePage() {
  const { id } = useParams()
  const { state } = useLocation()
  const { terminada } = useSesionPlataforma()
  const [estado, setEstado] = useState({ loading: true })
  const [intento, setIntento] = useState(0)
  const [borrador, setBorrador] = useState(null)
  const [guardando, setGuardando] = useState(false)
  const [error, setError] = useState('')
  const [aviso, setAviso] = useState(state?.aviso || '')
  const [confirmar, setConfirmar] = useState(null)
  const [credenciales, setCredenciales] = useState(null)
  useEffect(() => {
    const controller = new AbortController()
    verInstitucion(id, controller.signal).then(data => { setEstado({ data }); document.title = `${data.nombre} · Plataforma` }).catch(failure => {
      if (controller.signal.aborted) return
      if (sesionTerminada(failure)) terminada()
      else setEstado({ error: mensajeError(failure) })
    })
    return () => controller.abort()
  }, [id, intento, terminada])
  const recargar = () => setIntento(valor => valor + 1)
  const institucion = estado.data
  if (estado.loading) return <p role="status" className="text-sm text-slate-500">Cargando…</p>
  if (estado.error) return <div className="space-y-3"><Link to="/plataforma/instituciones" className="text-sm text-teal-700 underline">Volver a instituciones</Link><Aviso>{estado.error}</Aviso><button type="button" onClick={() => { setEstado({ loading: true }); recargar() }} className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm">Reintentar</button></div>
  const acciones = accionesDisponibles(institucion)
  const editable = institucion.ubicacion_editable
  async function guardar(event) {
    event.preventDefault()
    const datos = cambios(institucion, borrador)
    if (Object.keys(datos).length === 0) { setBorrador(null); return }
    setGuardando(true)
    setError('')
    try {
      await editarInstitucion(institucion.id, datos)
      setBorrador(null)
      setAviso('Datos guardados.')
      recargar()
    } catch (failure) {
      if (sesionTerminada(failure)) terminada()
      else setError(mensajeError(failure))
    } finally {
      setGuardando(false)
    }
  }
  async function cambiarEstado(motivo) {
    try {
      if (confirmar === 'suspender') await suspenderInstitucion(institucion.id, motivo)
      else await activarInstitucion(institucion.id, motivo)
    } catch (failure) {
      if (sesionTerminada(failure)) terminada()
      throw failure
    }
    setAviso(confirmar === 'suspender' ? 'Institución suspendida. Sus datos se conservan.' : 'Institución activa.')
    setConfirmar(null)
    recargar()
  }
  const campo = (nombre, etiqueta, props = {}) => <label className="grid gap-2 text-xs font-medium text-slate-600">{etiqueta}<input value={borrador[nombre] ?? ''} onChange={event => setBorrador({ ...borrador, [nombre]: event.target.value })} disabled={guardando || (nombre !== 'nombre' && !editable)} className={entrada} {...props} /></label>
  return <section aria-labelledby="titulo-institucion">
    <Link to="/plataforma/instituciones" className="text-sm text-teal-700 underline">Volver a instituciones</Link>
    <div className="mt-3 flex flex-wrap items-start justify-between gap-4">
      <div><h1 id="titulo-institucion" className="text-2xl font-bold text-slate-900">{institucion.nombre}</h1><div className="mt-2 flex flex-wrap gap-2"><EstadoInstitucion estado={institucion.estado} /><SinAprovisionar institucion={institucion} /></div></div>
      <div className="grid justify-items-end gap-2">
        <div className="flex flex-wrap justify-end gap-2">
          {acciones.aprovisionar && <button type="button" onClick={() => { setAviso(''); setConfirmar('aprovisionar') }} className="rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white">Aprovisionar</button>}
          {!borrador && <button type="button" onClick={() => { setAviso(''); setError(''); setBorrador({ nombre: institucion.nombre, slug: institucion.slug, base_datos: institucion.base_datos, almacenamiento: institucion.almacenamiento }) }} className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm">Editar datos</button>}
          <button type="button" disabled={!acciones.activar} title={institucion.motivo_no_activable || ''} onClick={() => { setAviso(''); setConfirmar('activar') }} className="rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white">{acciones.textoActivar}</button>
          <button type="button" disabled={!acciones.suspender} title={institucion.motivo_no_suspendible || ''} onClick={() => { setAviso(''); setConfirmar('suspender') }} className="rounded-xl border border-rose-200 bg-white px-4 py-2 text-sm font-semibold text-rose-700">Suspender</button>
        </div>
        {!institucion.suspension_bloquea_acceso && <p role="note" className="max-w-xs rounded-lg bg-amber-50 px-3 py-2 text-right text-xs text-amber-900">{AVISO_SUSPENSION}</p>}
      </div>
    </div>
    <div className="mt-5 space-y-3">
      <Aviso tipo="exito">{aviso}</Aviso>
      {credenciales && <Credenciales titulo="Primer administrador creado." credenciales={credenciales} onCerrar={() => setCredenciales(null)} />}
      {!institucion.aprovisionada && <Aviso tipo="info">{institucion.motivo_no_activable} Usa «Aprovisionar» para crear su base, sus catálogos, su primer administrador y su carpeta.</Aviso>}
      {institucion.estado === 'suspendida' && <Aviso tipo="info">Suspendida {fecha(institucion.suspendida_en)}{institucion.motivo_suspension ? `: ${institucion.motivo_suspension}` : ''}. Sus datos y archivos se conservan.</Aviso>}
    </div>
    {borrador ? <form onSubmit={guardar} aria-busy={guardando} className="mt-6 space-y-4 rounded-2xl border border-slate-200 bg-white p-6">
      <Aviso>{error}</Aviso>
      {campo('nombre', 'Nombre *', { required: true, minLength: 3, maxLength: 150 })}
      {campo('slug', 'Identificador *', { required: true, maxLength: 40 })}
      {campo('base_datos', 'Base de datos *', { required: true, maxLength: 63 })}
      {campo('almacenamiento', 'Almacenamiento *', { required: true, maxLength: 120 })}
      {!editable && <p className="text-xs text-slate-500">Ya aprovisionada o en operación: el identificador, la base de datos y el almacenamiento no se cambian desde el panel.</p>}
      <div className="flex justify-end gap-3"><button type="button" onClick={() => { setBorrador(null); setError('') }} disabled={guardando} className="rounded-xl border border-slate-200 px-4 py-2 text-sm">Cancelar</button><button type="submit" disabled={guardando} className="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">{guardando ? 'Guardando…' : 'Guardar'}</button></div>
    </form> : <dl className="mt-6 grid gap-5 rounded-2xl border border-slate-200 bg-white p-6 sm:grid-cols-2">
      <Dato etiqueta="Identificador">{institucion.slug}</Dato>
      <Dato etiqueta="Base de datos">{institucion.base_datos}</Dato>
      <Dato etiqueta="Almacenamiento">{institucion.almacenamiento}</Dato>
      <Dato etiqueta="Aprovisionamiento verificado">{institucion.aprovisionada ? fecha(institucion.aprovisionada_en) : 'Pendiente'}</Dato>
      <Dato etiqueta="Registrada">{fecha(institucion.created_at)}</Dato>
      <Dato etiqueta="Última modificación">{fecha(institucion.updated_at)}</Dato>
    </dl>}
    {institucion.aprovisionada && <AdministradoresInstitucion institucion={institucion} onCambio={recargar} />}
    <div className="mt-6 rounded-2xl border border-slate-200 bg-white p-6">
      <h2 className="font-semibold text-slate-800">Auditoría</h2>
      {institucion.auditoria.length === 0 ? <p className="mt-3 text-sm text-slate-500">Sin operaciones registradas.</p> : <ol className="mt-3 divide-y divide-slate-100">
        {institucion.auditoria.map(evento => <li key={evento.id} className="flex flex-wrap justify-between gap-2 py-3 text-sm"><span><strong className="text-slate-700">{etiquetaAccion(evento.accion)}</strong>{evento.datos_despues?.email && <span className="text-slate-500"> ({evento.datos_despues.email})</span>}{evento.motivo && <span className="text-slate-500"> — {evento.motivo}</span>}</span><span className="text-xs text-slate-500">{evento.administrador || 'Sistema'} · {fecha(evento.created_at)}</span></li>)}
      </ol>}
    </div>
    {confirmar === 'aprovisionar' && <Aprovisionar institucion={institucion} onCerrar={() => setConfirmar(null)} onSesionTerminada={terminada} onAprovisionada={data => {
      setCredenciales(data.administrador_inicial)
      setAviso('Institución aprovisionada. Ya se puede activar.')
      setConfirmar(null)
      recargar()
    }} />}
    {confirmar && confirmar !== 'aprovisionar' && <ConfirmarEstado accion={confirmar} institucion={institucion} onConfirmar={cambiarEstado} onCerrar={() => setConfirmar(null)} />}
  </section>
}
