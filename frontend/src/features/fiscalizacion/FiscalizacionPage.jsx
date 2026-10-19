import { useState } from 'react'
import { Link } from 'react-router-dom'
import api, { apiErrorMessage } from '../../axios'
import { usePuede } from '../auth/session'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'
import { DocumentPicker, LegajoPicker, QueryStatus, WorkflowField, WorkflowHistory, WorkflowPagination } from '../../shared/WorkflowControls'
import { formatDate, todayLima } from '../../shared/dates'

const panel = 'rounded-2xl border border-slate-200 bg-white p-5'
const button = 'rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-teal-800 disabled:opacity-50'

function NuevoCaso({ onCreated }) {
  const [open, setOpen] = useState(false)
  const [legajo, setLegajo] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState(false)
  async function submit(event) {
    event.preventDefault()
    if (busy || !legajo) return
    setBusy(true); setError('')
    try {
      await api.post('/fiscalizacion/casos', { ...Object.fromEntries(new FormData(event.currentTarget)), legajo_id: legajo.id })
      setOpen(false); setLegajo(null); setSuccess(true); onCreated()
    } catch (failure) { setError(apiErrorMessage(failure)) } finally { setBusy(false) }
  }
  return <section className={`${panel} mb-5`}><button type="button" className={button} aria-expanded={open} onClick={() => { setOpen(!open); setSuccess(false) }}>Abrir fiscalización</button>{success && <p role="status" className="mt-3 text-sm text-teal-800">Caso registrado.</p>}{open && <form className="mt-5" onSubmit={submit} aria-busy={busy}><fieldset disabled={busy} className="space-y-4"><LegajoPicker value={legajo} onChange={setLegajo} />
    <WorkflowField name="tipo_muestra" label="Tipo o criterio de muestra" maxLength={25} />
    <div className="grid gap-4 sm:grid-cols-3"><WorkflowField name="fecha_vinculacion" label="Fecha de vinculación" type="date" max={todayLima()} /><WorkflowField name="fecha_inicio" label="Inicio de fiscalización" type="date" defaultValue={todayLima()} max={todayLima()} /><WorkflowField name="fecha_limite" label="Fecha límite explícita" type="date" /></div>
    {error && <p role="alert" className="text-sm text-rose-800">{error}</p>}<button disabled={busy || !legajo || legajo.estado_legajo !== 'activo'} className={button}>{busy ? 'Guardando…' : 'Registrar caso'}</button>{legajo && legajo.estado_legajo !== 'activo' && <p className="text-sm text-rose-800">Selecciona un legajo activo.</p>}
  </fieldset></form>}</section>
}

function Caso({ id, onChanged }) {
  const request = useLiveQuery(`/fiscalizacion/casos/${id}`)
  return <div className="mt-4"><QueryStatus request={request} />{request.data && <CasoActions initial={request.data.data} onChanged={() => { request.retry(); onChanged() }} />}</div>
}

function CasoActions({ initial, onChanged }) {
  const [record, setRecord] = useState(initial)
  const [action, setAction] = useState('')
  const [docs, setDocs] = useState({})
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  async function reload() {
    try { setRecord((await api.get(`/fiscalizacion/casos/${record.id}`)).data.data); setAction(''); setDocs({}); setError(''); onChanged() } catch (failure) { setError(apiErrorMessage(failure)) }
  }
  async function submit(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true); setError(''); setSuccess('')
    try {
      await api.post(`/fiscalizacion/casos/${record.id}/acciones`, { ...Object.fromEntries(new FormData(event.currentTarget)), ...docs, accion: action, version: record.version })
      await reload(); setSuccess('Operación registrada.')
    } catch (failure) { setError(apiErrorMessage(failure)) } finally { setBusy(false) }
  }
  const pendientes = record.solicitudes_fiscalizacion?.filter(item => item.resultado === 'pendiente') || []
  const docsReady = action === 'comunicar' ? docs.documento_id && docs.oficio_documento_id : action === 'responder' ? docs.documento_respuesta_id : action === 'cerrar' ? docs.informe_documento_id : true
  const documento = (key, label) => <DocumentPicker key={`${record.id}-${key}`} legajo={record.legajo_id} value={docs[key] || ''} onChange={id => setDocs(current => ({ ...current, [key]: id }))} label={label} />
  return <div className="space-y-4">
    {initial.version !== record.version && <p role="status" className="text-sm text-amber-900">El caso cambió. <button className={button} onClick={reload}>Actualizar caso</button></p>}
    {error && <p role="alert" className="text-sm text-rose-800">{error} <button type="button" className={button} onClick={reload}>Actualizar caso</button></p>}{success && <p role="status" className="text-sm text-teal-800">{success}</p>}
    <p className="text-sm">Estado: {record.estado} · Resultado: {record.resultado || 'Sin resultado final'}</p>
    <ul className="space-y-3 text-sm">{record.solicitudes_fiscalizacion?.map(item => <li key={item.id} className="rounded-xl border border-slate-200 p-3"><p className="font-semibold">{item.entidad_consultada} · {item.numero_oficio}</p><p>Envío: {formatDate(item.fecha_envio)} · Respuesta: {formatDate(item.fecha_respuesta)} · {item.resultado}</p><p>{item.documento?.tipo_documento?.nombre} · {item.descripcion_hallazgo}</p><WorkflowHistory entity="solicitudes_fiscalizacion" id={item.id} /></li>)}</ul>
    {record.estado !== 'cancelado' && <form key={record.version} onSubmit={submit} aria-busy={busy}><fieldset disabled={busy} className="space-y-4">
      <label className="grid gap-2 text-sm">Acción<select required value={action} onChange={event => { setAction(event.target.value); setDocs({}) }} className="rounded-xl border border-slate-300 bg-white p-3"><option value="">Seleccionar acción</option>{record.estado === 'cerrado' ? <option value="reabrir">Reabrir con motivo</option> : <><option value="comunicar">Registrar comunicación y oficio</option>{pendientes.length > 0 && <option value="responder">Registrar respuesta y evidencia</option>}<option value="cerrar">Registrar informe y cerrar</option><option value="prorrogar">Ampliar plazo</option><option value="cancelar">Cancelar caso</option></>}</select></label>
      {action === 'comunicar' && <>{documento('documento_id', 'Documento que se fiscaliza')}{documento('oficio_documento_id', 'Oficio de consulta ya cargado en el legajo')}<WorkflowField name="entidad_consultada" label="Entidad consultada" maxLength={200} /><WorkflowField name="medio" label="Medio de comunicación" maxLength={30} /><WorkflowField name="numero_oficio" label="Número de oficio" maxLength={40} /><WorkflowField name="fecha_envio" label="Fecha de envío" type="date" defaultValue={todayLima()} max={todayLima()} /></>}
      {action === 'responder' && <><WorkflowField name="solicitud_id" label="Comunicación pendiente"><option value="">Seleccionar comunicación</option>{pendientes.map(item => <option key={item.id} value={item.id}>{item.numero_oficio} · {item.entidad_consultada}</option>)}</WorkflowField>{documento('documento_respuesta_id', 'Documento de respuesta ya cargado')}<WorkflowField name="fecha_respuesta" label="Fecha de respuesta" type="date" defaultValue={todayLima()} max={todayLima()} /><WorkflowField name="resultado" label="Resultado de la comunicación"><option value="conforme">Conforme</option><option value="observado">Observado</option><option value="falso">Falso</option></WorkflowField><WorkflowField name="descripcion_hallazgo" label="Descripción del hallazgo o verificación" type="textarea" maxLength={5000} /></>}
      {action === 'cerrar' && <>{documento('informe_documento_id', 'Informe final ya cargado')}<WorkflowField name="fecha_fin" label="Fecha de cierre" type="date" defaultValue={todayLima()} max={todayLima()} /><WorkflowField name="conclusiones" label="Conclusiones sustentadas" type="textarea" maxLength={5000} /></>}
      {action === 'prorrogar' && <WorkflowField name="fecha_limite" label="Nueva fecha límite" type="date" min={todayLima()} />}
      {['reabrir', 'prorrogar', 'cancelar'].includes(action) && <WorkflowField name="motivo" label="Motivo" type="textarea" maxLength={1000} />}
      {action && <button className={button} disabled={busy || !docsReady}>{busy ? 'Guardando…' : 'Registrar operación'}</button>}
    </fieldset></form>}
    <WorkflowHistory entity="procesos_fiscalizacion" id={record.id} />
  </div>
}

function Listado() {
  const [filters, setFilters] = useState({ q: '', estado: '', atrasado: '', page: 1 })
  const [selected, setSelected] = useState('')
  const params = new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== ''))
  const request = useLiveQuery(`/fiscalizacion/casos?${params}`)
  return <div><h1 className="text-3xl font-bold text-slate-900">Fiscalización</h1><p className="my-4 text-sm text-slate-600">Apertura, comunicaciones, respuestas e informe final. Registra las fechas explícitamente. Las comunicaciones registradas se envían por el medio indicado fuera de este panel.</p><NuevoCaso onCreated={request.retry} />
    <form className={`${panel} mb-5 flex flex-wrap items-end gap-4`} onSubmit={event => { event.preventDefault(); setFilters({ ...Object.fromEntries(new FormData(event.currentTarget)), page: 1 }); setSelected('') }}><WorkflowField name="q" label="Nombre de la persona" required={false} maxLength={120} /><WorkflowField name="estado" label="Estado" required={false}><option value="">Todos</option><option value="abierto">Abierto</option><option value="cerrado">Cerrado</option><option value="cancelado">Cancelado</option></WorkflowField><WorkflowField name="atrasado" label="Plazo" required={false}><option value="">Todos</option><option value="1">Solo atrasados</option></WorkflowField><button className={button}>Aplicar filtros</button></form>
    <QueryStatus request={request} />{request.data && <section className={panel}><ul className="space-y-5">{request.data.data.map(record => <li key={record.id} className="rounded-xl border border-slate-200 p-4"><div className="flex flex-wrap justify-between gap-3"><div><h2 className="font-semibold">{[record.usuario?.nombres, record.usuario?.apellido_paterno, record.usuario?.apellido_materno].filter(Boolean).join(' ')} · {record.legajo?.numero_legajo}</h2><p className={`mt-2 text-sm ${record.estado === 'abierto' && record.fecha_limite.slice(0, 10) < todayLima() ? 'font-semibold text-rose-800' : 'text-slate-600'}`}>{record.estado} · Fecha límite: {formatDate(record.fecha_limite)} · {record.tipo_muestra}</p></div><div className="flex items-center gap-3"><Link to={`/legajos/${record.usuario_id}`} className="text-sm font-semibold text-teal-800 underline">Ver legajo</Link><button className={button} aria-expanded={selected === record.id} onClick={() => setSelected(selected === record.id ? '' : record.id)}>{selected === record.id ? 'Cerrar detalle' : 'Gestionar caso'}</button></div></div>{selected === record.id && <Caso id={record.id} onChanged={request.retry} />}</li>)}</ul>{!request.data.data.length && <p>No hay casos para estos filtros.</p>}<WorkflowPagination data={request.data} loading={request.loading} setPage={page => { setFilters({ ...filters, page }); setSelected('') }} /></section>}
  </div>
}

export default function FiscalizacionPage() {
  const authorized = usePuede('sistema.administrar')
  return authorized ? <Listado /> : <p role="alert">No tienes permiso para gestionar fiscalización.</p>
}
