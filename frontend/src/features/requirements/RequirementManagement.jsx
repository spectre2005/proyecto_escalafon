import { useState } from 'react'
import api, { apiErrorMessage } from '../../axios'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'

const control = 'w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800'
const button = 'rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-teal-800 hover:bg-teal-50 disabled:opacity-50'
const nombre = person => [person.nombres, person.apellido_paterno, person.apellido_materno].filter(Boolean).join(' ')
const hoy = () => new Intl.DateTimeFormat('sv-SE', { timeZone: 'America/Lima' }).format(new Date())
const fecha = value => value ? value.slice(0, 10).split('-').reverse().join('/') : 'Sin fecha'

function EstadoConsulta({ request }) {
  return <>{request.error && <p role="alert">{apiErrorMessage(request.error)} <button type="button" className={button} onClick={request.retry}>Reintentar</button></p>}{request.loading && !request.data && <p role="status">Cargando…</p>}</>
}

function Paginas({ data, setPage, loading }) {
  if (!data || data.last_page <= 1) return null
  return <nav aria-label="Paginación del seguimiento" className="my-3 flex flex-wrap items-center gap-2"><button type="button" className={button} disabled={loading || data.current_page <= 1} onClick={() => setPage(data.current_page - 1)}>Anterior</button><span>{data.current_page} / {data.last_page}</span><button type="button" className={button} disabled={loading || data.current_page >= data.last_page} onClick={() => setPage(data.current_page + 1)}>Siguiente</button></nav>
}

function Personas({ term, onSelect }) {
  const [page, setPage] = useState(1)
  const request = useLiveQuery(`/requerimientos/personas?q=${encodeURIComponent(term)}&page=${page}`)
  return <div className="my-3"><EstadoConsulta request={request} />{request.data && <><ul className="max-h-64 space-y-2 overflow-auto">{request.data.data.map(person => <li key={person.id}><button type="button" className={button} onClick={() => onSelect(person)}>{nombre(person)} · {person.numero_doc_identidad}</button></li>)}</ul>{!request.data.data.length && <p>No se encontraron personas.</p>}<Paginas data={request.data} setPage={setPage} loading={request.loading} /></>}</div>
}

export function NewRequirement({ onCreated }) {
  const [open, setOpen] = useState(false)
  const [success, setSuccess] = useState(false)
  return <section className="mb-6 rounded-2xl border border-slate-200 bg-white p-5">
    <button type="button" className={button} aria-expanded={open} onClick={() => { setOpen(!open); setSuccess(false) }}>{open ? 'Cerrar formulario' : 'Nuevo requerimiento'}</button>
    {success && <p role="status" className="mt-3 text-teal-800">Requerimiento registrado.</p>}
    {open && <CreateForm onCreated={() => { setOpen(false); setSuccess(true); onCreated() }} />}
  </section>
}

function CreateForm({ onCreated }) {
  const types = useLiveQuery('/requerimientos/tipos')
  const [search, setSearch] = useState('')
  const [person, setPerson] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function submit(event) {
    event.preventDefault()
    if (busy || !person) return
    const datos = Object.fromEntries(new FormData(event.currentTarget))
    datos.usuario_id = person.id
    datos.tipo_documento_id ||= null
    setBusy(true)
    setError('')
    try { await api.post('/requerimientos', datos); onCreated() } catch (failure) { setError(apiErrorMessage(failure)) } finally { setBusy(false) }
  }
  return <form onSubmit={submit} aria-busy={busy} className="mt-5 space-y-4">
    <h2 className="font-semibold text-slate-900">Registrar solicitud documental</h2>
    <p className="text-sm text-slate-600">Selecciona a la persona y una fecha límite explícita. No se calculan feriados ni días hábiles.</p>
    {error && <p role="alert" className="text-rose-800">{error}</p>}
    <fieldset disabled={busy} className="space-y-4">
      <label className="grid gap-2 text-sm">Buscar persona por nombre o documento<input className={control} value={search} maxLength={120} onChange={event => { setSearch(event.target.value); setPerson(null) }} /></label>
      {person ? <p className="text-sm font-medium text-teal-800">Destinatario: {nombre(person)}</p> : search.trim().length >= 2 ? <Personas key={search.trim()} term={search.trim()} onSelect={setPerson} /> : <p className="text-sm text-slate-500">Escribe al menos dos caracteres para buscar.</p>}
      <EstadoConsulta request={types} />
      <label className="grid gap-2 text-sm">Tipo documental<select name="tipo_documento_id" className={control}><option value="">No especificado</option>{types.data?.data.map(type => <option key={type.id} value={type.id}>{type.nombre}</option>)}</select></label>
      <label className="grid gap-2 text-sm">Origen<input name="origen" required maxLength={15} defaultValue="legajo" className={control} /></label>
      <label className="grid gap-2 text-sm">Descripción<textarea name="descripcion" required maxLength={300} rows={3} className={control} /></label>
      <div className="grid gap-4 sm:grid-cols-2"><label className="grid gap-2 text-sm">Fecha de solicitud<input name="fecha_requerimiento" required type="date" defaultValue={hoy()} max={hoy()} className={control} /></label><label className="grid gap-2 text-sm">Fecha límite<input name="fecha_limite" required type="date" className={control} /></label></div>
      <button className={button} disabled={!person || busy || !types.data}>{busy ? 'Guardando…' : 'Registrar requerimiento'}</button>
    </fieldset>
  </form>
}

export function RequirementManagement({ record, onChanged }) {
  const [open, setOpen] = useState(false)
  return <details className="mt-3 min-w-64" onToggle={event => setOpen(event.currentTarget.open)}><summary className="cursor-pointer text-xs font-semibold text-teal-800">Gestionar e historial</summary>{open && <ManagementForm initial={record} onChanged={onChanged} />}</details>
}

function DocumentOptions({ id, selected, onSelect }) {
  const [page, setPage] = useState(1)
  const request = useLiveQuery(`/requerimientos/${id}/documentos?page=${page}`)
  return <div><EstadoConsulta request={request} />{request.data && <>
    <label className="grid gap-2">Documento de respuesta<select className={control} required value={selected} onChange={event => onSelect(event.target.value)}><option value="">Seleccionar documento</option>{request.data.data.map(doc => <option key={doc.id} value={doc.id}>{doc.tipo_documento?.nombre} · {doc.num_resolucion || 'Sin número'} · {fecha(doc.fecha_emision)} · {doc.estado}</option>)}</select></label>
    {!request.data.data.length && <p className="mt-2">No hay documentos admisibles. Carga el documento desde la ficha del legajo y reintenta.</p>}
    <Paginas data={request.data} setPage={value => { setPage(value); onSelect('') }} loading={request.loading} />
  </>}</div>
}

function ManagementForm({ initial, onChanged }) {
  const [record, setRecord] = useState(initial)
  const [action, setAction] = useState('')
  const [document, setDocument] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const closed = !!record.cerrado_en || ['cumplido', 'cancelado'].includes(record.estado)
  async function submit(event) {
    event.preventDefault()
    if (busy) return
    const datos = { ...Object.fromEntries(new FormData(event.currentTarget)), accion: action, version: record.version }
    if (action === 'responder') datos.documento_id = document
    setBusy(true); setError(''); setSuccess('')
    try {
      const response = await api.post(`/requerimientos/${record.id}/acciones`, datos)
      setRecord(response.data.data); setAction(''); setDocument(''); setSuccess('Operación registrada.'); onChanged()
    } catch (failure) { setError(apiErrorMessage(failure)) } finally { setBusy(false) }
  }
  async function refresh() {
    try {
      const response = await api.get(`/requerimientos/${record.id}`)
      setRecord(response.data.data); setAction(''); setDocument(''); setError(''); onChanged()
    } catch (failure) { setError(apiErrorMessage(failure)) }
  }
  return <div className="mt-3 space-y-4 text-xs">
    <p>Estado: <strong>{record.estado}</strong> · Plazo efectivo: {fecha(record.prorroga_hasta || record.fecha_limite)}</p>
    {error && <p role="alert" className="text-rose-800">{error} <button type="button" className={button} onClick={refresh}>Actualizar requerimiento</button></p>}
    {success && <p role="status" className="text-teal-800">{success}</p>}
    {!closed && <form key={record.version} onSubmit={submit} aria-busy={busy}><fieldset disabled={busy} className="space-y-3">
      <label className="grid gap-2">Acción<select className={control} required value={action} onChange={event => { setAction(event.target.value); setError('') }}><option value="">Seleccionar acción</option>
        {record.estado !== 'en_revision' && <option value="responder">Presentar respuesta / subsanar</option>}
        {record.estado === 'en_revision' && <><option value="cumplir">Aceptar respuesta y cumplir</option><option value="observar">Observar respuesta</option></>}
        {!record.documento_id && ['abierto', 'pendiente', 'vencido', 'prorrogado'].includes(record.estado) && <option value="editar">Corregir descripción</option>}
        <option value="prorrogar">Conceder prórroga</option><option value="cancelar">Cancelar requerimiento</option>
      </select></label>
      {action === 'editar' && <label className="grid gap-2">Descripción<textarea required name="descripcion" defaultValue={record.descripcion} maxLength={300} className={control} /></label>}
      {action === 'responder' && <DocumentOptions id={record.id} selected={document} onSelect={setDocument} />}
      {action === 'prorrogar' && <label className="grid gap-2">Nueva fecha límite<input required type="date" name="prorroga_hasta" min={hoy()} className={control} /></label>}
      {['editar', 'observar', 'prorrogar', 'cancelar'].includes(action) && <label className="grid gap-2">Motivo<textarea required name="motivo" maxLength={1000} className={control} /></label>}
      {action && <button className={button} disabled={busy || (action === 'responder' && !document)}>{busy ? 'Guardando…' : 'Registrar acción'}</button>}
    </fieldset></form>}
    <History id={record.id} />
  </div>
}

function History({ id }) {
  const [page, setPage] = useState(1)
  const request = useLiveQuery(`/requerimientos/${id}/historial?page=${page}`)
  return <section aria-label="Historial del requerimiento"><h3 className="font-semibold">Historial</h3><EstadoConsulta request={request} />{request.data && <><ul className="mt-2 space-y-3">{request.data.data.map(event => <li key={event.id} className="border-l-2 border-teal-200 pl-3"><p className="font-medium">{event.accion.replace('requerimiento.', '')} · {fecha(event.fecha)}</p><p>{event.actor ? nombre(event.actor) : 'Actor no disponible'}</p>{event.despues?.motivo && <p className="whitespace-pre-wrap">{event.despues.motivo}</p>}<p>{event.antes?.estado || 'Nuevo'} → {event.despues?.estado} · Límite: {fecha(event.despues?.prorroga_hasta || event.despues?.fecha_limite)}</p></li>)}</ul>{!request.data.data.length && <p>Registro anterior a este historial; no se reconstruyen operaciones históricas.</p>}<Paginas data={request.data} setPage={setPage} loading={request.loading} /></>}</section>
}
