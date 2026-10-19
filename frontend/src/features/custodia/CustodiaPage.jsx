import { useState } from 'react'
import api, { apiErrorMessage } from '../../axios'
import { usePuede } from '../auth/session'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'
import { DocumentPicker, LegajoPicker, QueryStatus, WorkflowField, WorkflowHistory, WorkflowPagination } from '../../shared/WorkflowControls'
import { formatDate, todayLima } from '../../shared/dates'

const panel = 'rounded-2xl border border-slate-200 bg-white p-5'
const button = 'rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-teal-800 disabled:opacity-50'

function NuevaSolicitud({ onCreated }) {
  const [open, setOpen] = useState(false)
  const [legajo, setLegajo] = useState(null)
  const [documents, setDocuments] = useState([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState(false)
  async function submit(event) {
    event.preventDefault()
    if (busy || !legajo || !documents.length) return
    const form = new FormData(event.currentTarget)
    const datos = { ...Object.fromEntries(form), legajo_id: legajo.id, documentos_autorizados: documents, incluye_datos_sensibles: form.has('incluye_datos_sensibles'), compromiso_confidencialidad: form.has('compromiso_confidencialidad') }
    setBusy(true); setError('')
    try { await api.post('/custodia/solicitudes', datos); setOpen(false); setLegajo(null); setDocuments([]); setSuccess(true); onCreated() } catch (failure) { setError(apiErrorMessage(failure)) } finally { setBusy(false) }
  }
  return <section className={`${panel} mb-5`}><button className={button} type="button" aria-expanded={open} onClick={() => { setOpen(!open); setSuccess(false) }}>Nueva solicitud de acceso</button>{success && <p role="status" className="mt-3 text-sm text-teal-800">Solicitud registrada para decisión administrativa.</p>}{open && <form onSubmit={submit} aria-busy={busy} className="mt-5"><fieldset disabled={busy} className="space-y-4">
    <LegajoPicker value={legajo} onChange={value => { setLegajo(value); setDocuments([]) }} />
    {legajo && <DocumentPicker key={legajo.id} legajo={legajo.id} multiple value={documents} onChange={setDocuments} label="Documentos que forman el alcance solicitado" />}
    <div className="grid gap-4 sm:grid-cols-2"><WorkflowField name="solicitante_nombre" label="Nombre del solicitante" maxLength={200} /><WorkflowField name="solicitante_documento" label="Documento de identidad del solicitante" maxLength={20} /><WorkflowField name="entidad_procedencia" label="Entidad de procedencia" maxLength={200} required={false} /><WorkflowField name="tipo_solicitante" label="Tipo de solicitante"><option value="interno">Interno</option><option value="externo">Externo</option><option value="titular">Titular del legajo</option></WorkflowField><WorkflowField name="forma_entrega" label="Forma de entrega"><option value="consulta">Consulta</option><option value="copia">Copia</option><option value="prestamo">Préstamo de ejemplar autorizado</option></WorkflowField></div>
    <WorkflowField name="detalle_requerido" label="Detalle del acceso requerido" type="textarea" maxLength={3000} /><WorkflowField name="sustento" label="Sustento de la solicitud" type="textarea" maxLength={5000} />
    <label className="flex items-start gap-3 text-sm"><input type="checkbox" name="incluye_datos_sensibles" className="mt-1" />El alcance contiene datos sensibles o de salud (solo acceso del titular).</label>
    <label className="flex items-start gap-3 text-sm"><input type="checkbox" name="compromiso_confidencialidad" required className="mt-1" />Se ha registrado el compromiso de confidencialidad del solicitante.</label>
    {error && <p role="alert" className="text-sm text-rose-800">{error}</p>}<button className={button} disabled={busy || !legajo || !documents.length}>{busy ? 'Guardando…' : 'Registrar solicitud'}</button>
  </fieldset></form>}</section>
}

function Alcance({ record }) {
  const [page, setPage] = useState(1)
  const [busy, setBusy] = useState('')
  const [error, setError] = useState('')
  const request = useLiveQuery(`/custodia/solicitudes/${record.id}/alcance?page=${page}`)
  async function download(doc) {
    if (busy) return
    setBusy(doc.id); setError('')
    try {
      const response = await api.get(`/custodia/solicitudes/${record.id}/documentos/${doc.id}/archivo`, { responseType: 'blob' })
      const url = URL.createObjectURL(response.data)
      const link = window.document.createElement('a')
      link.href = url; link.download = 'documento-autorizado.pdf'; link.click()
      setTimeout(() => URL.revokeObjectURL(url), 1000)
    } catch (failure) {
      if (failure.response?.data instanceof Blob) {
        try { failure.response.data = JSON.parse(await failure.response.data.text()) } catch { /* Conserva el mensaje de conexión si no llegó JSON. */ }
      }
      setError(apiErrorMessage(failure))
    } finally { setBusy('') }
  }
  const vigente = record.estado === 'aprobada' && record.vigente_hasta?.slice(0, 10) >= todayLima()
  return <section className="space-y-3 rounded-xl border border-slate-200 p-4"><h3 className="font-semibold">Alcance documental</h3><QueryStatus request={request} />{error && <p role="alert" className="text-sm text-rose-800">{error}</p>}{request.data && <><ul className="space-y-3 text-sm">{request.data.data.map(doc => <li key={doc.id} className="flex flex-wrap items-center justify-between gap-3"><span>{doc.tipo_documento?.nombre} · {doc.num_resolucion || 'Sin número'} · {doc.estado}</span>{vigente && <button className={button} disabled={!!busy} onClick={() => download(doc)}>{busy === doc.id ? 'Preparando…' : 'Registrar entrega y descargar'}</button>}</li>)}</ul>{!request.data.data.length && <p>No hay documentos disponibles en el alcance.</p>}<WorkflowPagination data={request.data} setPage={setPage} loading={request.loading} /></>}</section>
}

function SolicitudActions({ initial, onChanged }) {
  const [record, setRecord] = useState(initial)
  const [action, setAction] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  async function reload() {
    try { setRecord((await api.get(`/custodia/solicitudes/${record.id}`)).data.data); setAction(''); setError(''); onChanged() } catch (failure) { setError(apiErrorMessage(failure)) }
  }
  async function submit(event) {
    event.preventDefault()
    if (busy) return
    const datos = Object.fromEntries(new FormData(event.currentTarget))
    const prestar = action === 'prestar'
    setBusy(true); setError(''); setSuccess('')
    try {
      await api.post(`/custodia/solicitudes/${record.id}/${prestar ? 'prestamos' : 'acciones'}`, prestar ? datos : { ...datos, accion: action, version: record.version })
      await reload(); setSuccess(prestar ? 'Préstamo registrado. Consulta su devolución en Préstamos.' : 'Decisión registrada.')
    } catch (failure) { setError(apiErrorMessage(failure)) } finally { setBusy(false) }
  }
  return <div className="mt-4 space-y-4 text-sm">
    <p>{record.detalle_requerido}</p><p>Sustento: {record.sustento}</p><p>{record.compromiso_confidencialidad ? 'Compromiso de confidencialidad registrado' : 'Sin compromiso registrado'} · {record.incluye_datos_sensibles ? 'Incluye datos sensibles' : 'Sin datos sensibles declarados'}</p>
    {initial.version !== record.version && <p role="status" className="text-amber-900">La solicitud cambió. <button className={button} onClick={reload}>Actualizar solicitud</button></p>}
    {error && <p role="alert" className="text-rose-800">{error} <button type="button" className={button} onClick={reload}>Actualizar solicitud</button></p>}{success && <p role="status" className="text-teal-800">{success}</p>}
    {['pendiente', 'aprobada'].includes(record.estado) && <form key={record.version} onSubmit={submit} aria-busy={busy}><fieldset disabled={busy} className="space-y-4"><label className="grid gap-2">Acción<select className="rounded-xl border border-slate-300 bg-white p-3" required value={action} onChange={event => setAction(event.target.value)}><option value="">Seleccionar acción</option>{record.estado === 'pendiente' ? <><option value="aprobar">Aprobar alcance</option><option value="rechazar">Rechazar solicitud</option></> : <><option value="revocar">Revocar autorización</option>{record.forma_entrega === 'prestamo' && <option value="prestar">Registrar préstamo</option>}</>}</select></label>
      {action === 'aprobar' && <WorkflowField name="vigente_hasta" label="Autorización vigente hasta" type="date" min={todayLima()} />}
      {['aprobar', 'rechazar', 'revocar'].includes(action) && <WorkflowField name="motivo" label="Motivo de la decisión" type="textarea" maxLength={1000} />}
      {action === 'prestar' && <><div className="grid gap-4 sm:grid-cols-2"><WorkflowField name="fecha_movimiento" label="Fecha de entrega" type="date" defaultValue={todayLima()} max={todayLima()} /><WorkflowField name="fecha_devolucion_prevista" label="Devolución prevista" type="date" max={record.vigente_hasta?.slice(0, 10)} /><WorkflowField name="entidad_destino" label="Responsable y ubicación de destino" maxLength={200} /><WorkflowField name="numero_documento_sustento" label="Documento de sustento" maxLength={60} /><WorkflowField name="numero_inventario" label="Inventario del ejemplar entregado" maxLength={40} /><WorkflowField name="formato_legajo" label="Formato del ejemplar"><option value="fisico">Físico</option><option value="mixto">Mixto</option></WorkflowField></div><WorkflowField name="observaciones" label="Detalle de la entrega" type="textarea" maxLength={3000} /></>}
      {action && <button className={button} disabled={busy}>{busy ? 'Guardando…' : 'Registrar operación'}</button>}
    </fieldset></form>}
    <Alcance record={record} /><WorkflowHistory entity="solicitudes_acceso_legajo" id={record.id} />
  </div>
}

function Solicitudes() {
  const [filters, setFilters] = useState({ q: '', estado: '', page: 1 })
  const [selected, setSelected] = useState('')
  const request = useLiveQuery(`/custodia/solicitudes?${new URLSearchParams(filters)}`)
  return <><NuevaSolicitud onCreated={request.retry} /><form onSubmit={event => { event.preventDefault(); setFilters({ ...Object.fromEntries(new FormData(event.currentTarget)), page: 1 }); setSelected('') }} className={`${panel} mb-5 flex flex-wrap items-end gap-4`}><WorkflowField name="q" label="Solicitante, documento o número de legajo" required={false} maxLength={120} /><WorkflowField name="estado" label="Estado" required={false}><option value="">Todos</option><option value="pendiente">Pendiente</option><option value="aprobada">Aprobada</option><option value="rechazada">Rechazada</option><option value="revocada">Revocada</option></WorkflowField><button className={button}>Aplicar filtros</button></form>
    <QueryStatus request={request} />{request.data && <section className={panel}><ul className="space-y-4">{request.data.data.map(record => <li key={record.id} className="rounded-xl border border-slate-200 p-4"><div className="flex flex-wrap items-start justify-between gap-3"><div><h2 className="font-semibold">{record.solicitante_nombre} · {record.legajo?.numero_legajo}</h2><p className="mt-2 text-sm">{record.estado} · {record.forma_entrega} · Hasta: {formatDate(record.vigente_hasta)}</p>{record.estado === 'aprobada' && record.vigente_hasta?.slice(0, 10) < todayLima() && <p className="mt-1 text-sm font-semibold text-rose-800">Autorización vencida</p>}</div><button className={button} aria-expanded={selected === record.id} onClick={() => setSelected(selected === record.id ? '' : record.id)}>{selected === record.id ? 'Cerrar' : 'Gestionar solicitud'}</button></div>{selected === record.id && <SolicitudActions initial={record} onChanged={request.retry} />}</li>)}</ul>{!request.data.data.length && <p>No hay solicitudes para estos filtros.</p>}<WorkflowPagination data={request.data} setPage={page => { setFilters({ ...filters, page }); setSelected('') }} loading={request.loading} /></section>}
  </>
}

function Devolucion({ record, onChanged }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function submit(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true); setError('')
    try { await api.post(`/custodia/movimientos/${record.id}/devolver`, { ...Object.fromEntries(new FormData(event.currentTarget)), version: record.version }); onChanged() } catch (failure) { setError(apiErrorMessage(failure)) } finally { setBusy(false) }
  }
  return <form onSubmit={submit} aria-busy={busy} className="mt-4"><fieldset disabled={busy} className="space-y-3"><WorkflowField name="fecha_retorno" label="Fecha de devolución efectiva" type="date" defaultValue={todayLima()} max={todayLima()} /><WorkflowField name="motivo" label="Recepción y observaciones de devolución" type="textarea" maxLength={1000} />{error && <p role="alert" className="text-sm text-rose-800">{error}</p>}<button className={button} disabled={busy}>{busy ? 'Guardando…' : 'Registrar devolución'}</button></fieldset></form>
}

function Prestamos() {
  const [mode, setMode] = useState('pendientes')
  const [page, setPage] = useState(1)
  const request = useLiveQuery(`/custodia/movimientos?page=${page}${mode === 'todos' ? '' : `&${mode}=1`}`)
  return <section className={panel}><div className="mb-5 flex flex-wrap gap-3">{[['pendientes', 'Pendientes'], ['atrasados', 'Atrasados'], ['todos', 'Todos']].map(([key, label]) => <button key={key} className={button} aria-pressed={mode === key} onClick={() => { setMode(key); setPage(1) }}>{label}</button>)}</div><QueryStatus request={request} />{request.data && <><ul className="space-y-4">{request.data.data.map(record => <li key={record.id} className="rounded-xl border border-slate-200 p-4"><h2 className="font-semibold">{record.legajo?.numero_legajo} · Inventario {record.numero_inventario}</h2><p className="mt-2 text-sm">Destino y responsable: {record.entidad_destino}</p><p className={`mt-1 text-sm ${!record.fecha_retorno && record.fecha_devolucion_prevista?.slice(0, 10) < todayLima() ? 'font-semibold text-rose-800' : ''}`}>Entrega: {formatDate(record.fecha_movimiento)} · Devolución prevista: {formatDate(record.fecha_devolucion_prevista)} · Devolución efectiva: {formatDate(record.fecha_retorno)}</p>{!record.fecha_retorno && !record.devuelto_en && <Devolucion record={record} onChanged={request.retry} />}<WorkflowHistory entity="movimientos_custodia_legajo" id={record.id} /></li>)}</ul>{!request.data.data.length && <p>No hay préstamos para este filtro.</p>}<WorkflowPagination data={request.data} setPage={setPage} loading={request.loading} /></>}</section>
}

function Panel() {
  const [tab, setTab] = useState('solicitudes')
  return <div><h1 className="text-3xl font-bold text-slate-900">Accesos y custodia</h1><p className="my-4 text-sm text-slate-600">Solicitudes con alcance documental, autorización administrativa y registro de entrega, préstamo y devolución. La administración realiza las entregas dentro de la vigencia aprobada.</p><div className="mb-5 flex gap-3"><button className={button} aria-pressed={tab === 'solicitudes'} onClick={() => setTab('solicitudes')}>Solicitudes y autorizaciones</button><button className={button} aria-pressed={tab === 'prestamos'} onClick={() => setTab('prestamos')}>Préstamos</button></div>{tab === 'solicitudes' ? <Solicitudes /> : <Prestamos />}</div>
}

export default function CustodiaPage() {
  const authorized = usePuede('sistema.administrar')
  return authorized ? <Panel /> : <p role="alert">No tienes permiso para administrar accesos y custodia.</p>
}
