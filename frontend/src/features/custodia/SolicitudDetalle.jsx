import { custodiaError } from './custodiaApi'
import { useEffect, useId, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import api from '../../axios'
import Icon from '../../components/ui/Icon'
import { useManualQuery } from '../../shared/queries/useManualQuery'
import { QueryStatus, WorkflowField, WorkflowHistory, WorkflowPagination } from '../../shared/WorkflowControls'
import { todayLima, formatDate } from '../../shared/dates'
import { formas, autorizacionVigente, estadoSolicitud, nombreTitular } from './custodiaUtils'
import { button, primary, Estado, EmptyState } from './CustodiaUI'

function Alcance({ record }) {
  const [page, setPage] = useState(1)
  const [busy, setBusy] = useState('')
  const [error, setError] = useState('')
  const request = useManualQuery(`/custodia/solicitudes/${record.id}/alcance?page=${page}`)
  async function download(doc) {
    if (busy) return
    setBusy(doc.id); setError('')
    try {
      const response = await api.get(`/custodia/solicitudes/${record.id}/documentos/${doc.id}/archivo`, { responseType: 'blob' })
      const url = URL.createObjectURL(response.data)
      const a = document.createElement('a'); a.href = url; a.download = 'documento-autorizado.pdf'; a.click()
      setTimeout(() => URL.revokeObjectURL(url), 1000)
    } catch (failure) {
      if (failure.response?.data instanceof Blob) {
        try { failure.response.data = JSON.parse(await failure.response.data.text()) } catch { /* Conservar el error de conexión. */ }
      }
      setError(custodiaError(failure))
    } finally { setBusy('') }
  }
  return <section className="space-y-3 rounded-xl border border-slate-200 p-4"><div><h3 className="text-sm font-semibold text-slate-900">Documentos autorizados en la solicitud</h3><p className="mt-1 text-xs text-slate-500">Cada entrega se registra en la bitácora. Solo se habilita con una autorización vigente.</p></div><QueryStatus request={request} />{error && <p role="alert" className="text-sm text-rose-800">{error}</p>}{request.data && <>{!request.data.data.length ? <EmptyState title="Sin documentos disponibles">El alcance puede incluir documentos retirados o que tu cuenta no puede consultar.</EmptyState> : <ul className="divide-y divide-slate-100">{request.data.data.map(doc => <li key={doc.id} className="flex flex-wrap items-center justify-between gap-3 py-3"><div className="min-w-0"><p className="text-sm font-semibold text-slate-800">{doc.tipo_documento?.nombre}</p><p className="mt-1 text-xs text-slate-500">{doc.nombre || doc.num_resolucion || 'Sin número'} · {doc.estado}</p>{doc.folio_inicio && <p className="mt-1 text-xs text-slate-500">Sección {doc.seccion_legajo_id} · Folios {doc.folio_inicio}–{doc.folio_fin}</p>}</div>{autorizacionVigente(record) && <button type="button" className={button} disabled={Boolean(busy)} onClick={() => download(doc)}><Icon name="file" size={16} />{busy === doc.id ? 'Preparando…' : 'Registrar entrega y descargar'}</button>}</li>)}</ul>}<WorkflowPagination data={request.data} setPage={setPage} loading={request.loading} /></>}</section>
}

function DecisionSolicitud({ initial, onChanged, onBusy }) {
  const [record, setRecord] = useState(initial)
  const [action, setAction] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [fechaEntrega, setFechaEntrega] = useState(todayLima)
  const labels = { aprobar: 'Confirmar aprobación', rechazar: 'Confirmar rechazo', revocar: 'Confirmar revocación', prestar: 'Registrar préstamo' }
  async function reload() {
    try { setRecord((await api.get(`/custodia/solicitudes/${record.id}`)).data.data); setAction(''); setError(''); onChanged() }
    catch (failure) { setError(custodiaError(failure)) }
  }
  async function submit(event) {
    event.preventDefault()
    if (busy) return
    const datos = Object.fromEntries(new FormData(event.currentTarget))
    setBusy(true); onBusy(true); setError(''); setSuccess('')
    try {
      const prestar = action === 'prestar'
      await api.post(`/custodia/solicitudes/${record.id}/${prestar ? 'prestamos' : 'acciones'}`, prestar ? datos : { ...datos, accion: action, version: record.version })
      setSuccess(prestar ? 'Préstamo registrado. Cierra este detalle y abre Préstamos para consultar la devolución.' : 'Decisión guardada. El estado y el historial se actualizaron.')
      await reload()
    } catch (failure) { setError(custodiaError(failure)) } finally { setBusy(false); onBusy(false) }
  }
  return <div className="space-y-5">
    <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-4"><div className="flex flex-wrap items-center justify-between gap-3"><div><p className="text-xs text-slate-500">Titular del expediente</p><h3 className="mt-1 font-semibold text-slate-900">{nombreTitular(record.legajo) || record.legajo?.numero_legajo}</h3><p className="mt-1 text-xs text-slate-500">{record.legajo?.numero_legajo} · {formas[record.forma_entrega]}</p></div><Estado value={estadoSolicitud(record)} /></div>{record.legajo?.usuario_id && <Link className="mt-3 inline-block text-xs font-semibold text-teal-800 underline" to={`/legajos/${record.legajo.usuario_id}`} target="_blank" rel="noopener noreferrer">Consultar ficha del legajo</Link>}</div>
    <dl className="grid gap-4 text-sm sm:grid-cols-2">{[['Solicitante', record.solicitante_nombre], ['Identificación', record.solicitante_documento], ['Procedencia', record.entidad_procedencia || 'No registrada'], ['Tipo de solicitante', { interno: 'Interno', externo: 'Externo', titular: 'Titular del legajo' }[record.tipo_solicitante]], ['Vigencia de la autorización', record.vigente_hasta ? `Hasta el ${formatDate(record.vigente_hasta)}` : record.estado === 'pendiente' ? 'Por definir al aprobar' : 'Sin vigencia registrada'], ['Alcance registrado', `${record.documentos_autorizados?.length || 0} documento(s)`]].map(([title, text]) => <div key={title}><dt className="text-xs text-slate-500">{title}</dt><dd className="mt-1 break-words font-medium text-slate-800">{text}</dd></div>)}</dl>
    <div className="grid gap-4 sm:grid-cols-2"><div><h3 className="text-xs font-semibold text-slate-500">Detalle del acceso requerido</h3><p className="mt-2 whitespace-pre-wrap break-words text-sm text-slate-700">{record.detalle_requerido}</p></div><div><h3 className="text-xs font-semibold text-slate-500">Sustento</h3><p className="mt-2 whitespace-pre-wrap break-words text-sm text-slate-700">{record.sustento}</p></div></div>
    <div className="flex flex-wrap gap-2 text-xs"><span className="rounded-lg bg-slate-100 px-3 py-2">{record.compromiso_confidencialidad ? 'Confidencialidad registrada' : 'Sin compromiso registrado'}</span><span className="rounded-lg bg-slate-100 px-3 py-2">{record.incluye_datos_sensibles ? 'Incluye datos sensibles: solo titular' : 'Sin datos sensibles declarados'}</span></div>
    {record.motivo_resolucion && <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-700"><strong>Motivo de la última decisión:</strong> {record.motivo_resolucion}</p>}
    {initial.version > record.version && <p role="status" className="text-sm text-amber-900">Existe una versión más reciente. Actualiza antes de decidir.</p>}
    {success && <p role="status" className="rounded-xl bg-teal-50 p-4 text-sm text-teal-800">{success}</p>}
    {error && <p role="alert" className="rounded-xl bg-rose-50 p-4 text-sm text-rose-800">{error} <button type="button" className="ml-2 font-semibold underline" disabled={busy} onClick={reload}>Actualizar solicitud</button></p>}
    {['pendiente', 'aprobada'].includes(record.estado) && <section className="space-y-4 rounded-xl border border-slate-200 p-4"><h3 className="text-sm font-semibold text-slate-900">Decisión administrativa</h3><div className="flex flex-wrap gap-2">{(record.estado === 'pendiente' ? [['aprobar', 'Aprobar alcance'], ['rechazar', 'Rechazar solicitud']] : [['revocar', 'Revocar autorización'], ...(record.forma_entrega === 'prestamo' && autorizacionVigente(record) ? [['prestar', 'Registrar préstamo']] : [])]).map(([key, text]) => <button key={key} type="button" aria-pressed={action === key} disabled={busy} className={action === key ? primary : button} onClick={() => { setAction(key); setError(''); setSuccess('') }}>{text}</button>)}</div>
      {record.estado === 'aprobada' && !autorizacionVigente(record) && <p className="text-sm text-amber-900">Esta autorización no está vigente. No se permiten entregas ni nuevos préstamos.</p>}
      {action && <form key={`${record.version}:${action}`} onSubmit={submit} aria-busy={busy}><fieldset disabled={busy} className="space-y-4 border-t border-slate-100 pt-4">
        {action === 'aprobar' && <WorkflowField name="vigente_hasta" label="Autorizar hasta" type="date" min={todayLima()} />}
        {['aprobar', 'rechazar', 'revocar'].includes(action) && <><p className="text-xs text-slate-500">{action === 'aprobar' ? 'La aprobación cubre únicamente los documentos incluidos en este alcance.' : action === 'revocar' ? 'La revocación impedirá nuevas entregas bajo esta autorización.' : 'La solicitud quedará rechazada y conservará su historial.'}</p><WorkflowField name="motivo" label="Motivo de la decisión" type="textarea" maxLength={1000} /></>}
        {action === 'prestar' && <><p className="rounded-lg bg-sky-50 p-3 text-xs text-sky-800">La devolución prevista debe estar dentro de la vigencia: {formatDate(record.vigente_hasta)}. Solo puede existir un préstamo pendiente por legajo.</p><div className="grid gap-4 sm:grid-cols-2"><WorkflowField name="fecha_movimiento" label="Fecha de entrega" type="date" value={fechaEntrega} onChange={event => setFechaEntrega(event.target.value)} min={record.fecha_resolucion ? new Intl.DateTimeFormat('sv-SE', { timeZone: 'America/Lima' }).format(new Date(record.fecha_resolucion)) : undefined} max={todayLima()} /><WorkflowField name="fecha_devolucion_prevista" label="Devolución prevista" type="date" min={fechaEntrega} max={record.vigente_hasta?.slice(0, 10)} /><WorkflowField name="entidad_destino" label="Responsable y ubicación de destino" maxLength={200} /><WorkflowField name="numero_documento_sustento" label="Documento de sustento" maxLength={60} /><WorkflowField name="numero_inventario" label="Inventario del ejemplar entregado" maxLength={40} /><WorkflowField name="formato_legajo" label="Formato del ejemplar"><option value="fisico">Físico</option><option value="mixto">Mixto</option></WorkflowField></div><WorkflowField name="observaciones" label="Detalle de la entrega" type="textarea" maxLength={3000} /></>}
        <div className="flex flex-wrap gap-3"><button className={primary} disabled={busy}>{busy ? 'Guardando…' : labels[action]}</button><button type="button" className={button} disabled={busy} onClick={() => setAction('')}>Cancelar operación</button></div>
      </fieldset></form>}
    </section>}
    <Alcance record={record} />
    <div className="rounded-xl border border-slate-200 px-4 pb-4"><WorkflowHistory entity="solicitudes_acceso_legajo" id={record.id} /></div>
  </div>
}

export default function SolicitudDetalle({ id, onClose, onChanged }) {
  const dialog = useRef(null)
  const titulo = useId()
  const [locked, setLocked] = useState(false)
  const request = useManualQuery(`/custodia/solicitudes/${id}`)
  useEffect(() => { if (!dialog.current.open) dialog.current.showModal() }, [])
  return <dialog ref={dialog} onClose={onClose} onCancel={event => { if (locked) event.preventDefault() }} aria-labelledby={titulo} className="m-auto max-h-[90dvh] w-[calc(100%_-_2rem)] max-w-4xl overflow-y-auto rounded-2xl border-0 bg-white p-0 shadow-xl backdrop:bg-slate-900/45"><div className="sticky top-0 z-10 flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-5 py-4 sm:px-6"><div><p className="text-[10px] font-semibold uppercase tracking-wider text-teal-700">Accesos y custodia</p><h2 id={titulo} className="mt-1 text-lg font-semibold text-slate-900">Detalle y autorización de solicitud</h2></div><button type="button" aria-label="Cerrar detalle de solicitud" disabled={locked} onClick={() => dialog.current.close()} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 disabled:opacity-40"><Icon name="close" /></button></div><div className="p-5 sm:p-6"><QueryStatus request={request} />{request.data && <DecisionSolicitud initial={request.data.data} onBusy={setLocked} onChanged={onChanged} />}</div></dialog>
}
