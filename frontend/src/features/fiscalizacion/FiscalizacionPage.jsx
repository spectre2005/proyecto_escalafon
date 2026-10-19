import { useState } from 'react'
import { Link } from 'react-router-dom'
import api, { apiErrorMessage } from '../../axios'
import { usePuede } from '../auth/session'
import { useManualQuery } from '../../shared/queries/useManualQuery'
import LegajoSearch from '../../shared/LegajoSearch'
import DocumentoModal from '../legajos/components/documentos/DocumentoModal'
import { DocumentPicker, QueryStatus, WorkflowField, WorkflowHistory, WorkflowPagination } from '../../shared/WorkflowControls'
import { formatDate, todayLima } from '../../shared/dates'

const panel = 'rounded-2xl border border-slate-200 bg-white p-5'
const button = 'rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-teal-800 disabled:opacity-50'

function NuevoCaso({ onCreated }) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [legajo, setLegajo] = useState(null)
  const [tipo, setTipo] = useState('')
  const tipos = useManualQuery(open && legajo?.estado_legajo === 'activo' ? `/fiscalizacion/tipos?legajo_id=${encodeURIComponent(legajo.id)}` : null)
  const tipoDisponible = Boolean(tipos.data?.data.some(item => item.id === tipo))
  const [inicio, setInicio] = useState(todayLima())
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function submit(event) {
    event.preventDefault()
    if (busy || !legajo || legajo.estado_legajo !== 'activo' || !tipoDisponible) return
    setBusy(true); setError('')
    try {
      const response = await api.post('/fiscalizacion/casos', { ...Object.fromEntries(new FormData(event.currentTarget)), legajo_id: legajo.id })
      setOpen(false); setLegajo(null); setSearch(''); setTipo(''); setInicio(todayLima()); onCreated(response.data.data.id)
    } catch (failure) { setError(failure.response?.data?.errors?.tipo_documento_id?.[0] || apiErrorMessage(failure)) } finally { setBusy(false) }
  }
  return <section className={panel}><div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="font-semibold text-slate-900">Nuevo caso de fiscalización</h2><p className="mt-1 text-sm text-slate-500">Selecciona un legajo activo y define el alcance y las fechas.</p></div><button type="button" disabled={busy} className={button} aria-haspopup="dialog" aria-expanded={open} onClick={() => { setOpen(true); setError(''); setTipo('') }}>+ Abrir fiscalización</button></div>{open && <DocumentoModal titulo="Nuevo caso de fiscalización" busy={busy} onCancel={() => setOpen(false)}><form className="mt-5" onSubmit={submit} aria-busy={busy}><fieldset disabled={busy} className="space-y-5">
    <section className="rounded-xl border border-slate-200 p-4"><h3 className="mb-3 text-sm font-semibold text-teal-900">1. Persona y legajo</h3><LegajoSearch search={search} legajo={legajo} onSearch={setSearch} onSelect={value => { setLegajo(value); setTipo('') }} onClear={() => { setLegajo(null); setSearch(''); setTipo('') }} />{legajo && legajo.estado_legajo !== 'activo' && <p role="alert" className="mt-3 text-sm text-rose-800">Selecciona un legajo activo para abrir una fiscalización.</p>}</section>
    <section className="space-y-3 rounded-xl border border-slate-200 p-4"><h3 className="text-sm font-semibold text-teal-900">2. Documento a verificar</h3>{!legajo ? <p className="text-sm text-slate-500">Selecciona primero el legajo.</p> : <><QueryStatus request={tipos} />{tipos.data && <>{tipos.data.data.length ? <WorkflowField name="tipo_documento_id" label="Tipo de documento cargado" value={tipo} onChange={event => setTipo(event.target.value)}><option value="">Seleccionar tipo documental</option>{tipos.data.data.map(item => <option key={item.id} value={item.id}>{item.nombre} · {item.cantidad} documento(s)</option>)}</WorkflowField> : <p role="status" className="text-sm text-slate-600">Este legajo no tiene documentos disponibles para fiscalizar. Carga primero el PDF desde su ficha.</p>}<div className="flex flex-wrap items-center justify-between gap-3 text-xs"><Link to={`/legajos/${legajo.usuario_id}`} target="_blank" rel="noopener noreferrer" className="font-semibold text-teal-800 underline">Abrir ficha del legajo ↗</Link><button type="button" className={button} onClick={() => { setTipo(''); tipos.retry() }}>Actualizar documentos</button></div><p className="text-xs text-slate-500">Al registrar la comunicación elegirás el archivo de este tipo y su oficio de consulta.</p></>}</>}</section>
    <section className="space-y-3 rounded-xl border border-slate-200 p-4"><h3 className="text-sm font-semibold text-teal-900">3. Fechas y plazo</h3><div className="grid gap-4 md:grid-cols-3"><WorkflowField name="fecha_vinculacion" label="Fecha de vinculación" type="date" max={inicio} /><WorkflowField name="fecha_inicio" label="Inicio de fiscalización" type="date" value={inicio} onChange={event => setInicio(event.target.value)} max={todayLima()} /><WorkflowField name="fecha_limite" label="Fecha límite" type="date" min={inicio} /></div><p className="text-xs text-slate-500">La vinculación no puede ser posterior al inicio. La fecha límite debe ser igual o posterior al inicio. Las fechas se introducen explícitamente.</p></section>
    {error && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm text-rose-800">{error}</p>}<div className="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4"><button disabled={busy || !legajo || legajo.estado_legajo !== 'activo' || !tipoDisponible} className={`${button} bg-teal-700 text-white`}>{busy ? 'Registrando…' : 'Registrar caso y continuar'}</button><p className="text-xs text-slate-500">Después podrás registrar comunicaciones, respuestas e informe final.</p></div>
  </fieldset></form></DocumentoModal>}</section>
}

function Caso({ id, onChanged, onBusy }) {
  const request = useManualQuery(`/fiscalizacion/casos/${id}`)
  return <div className="mt-4"><QueryStatus request={request} />{request.data && <CasoActions initial={request.data.data} onBusy={onBusy} onChanged={() => { request.retry(); onChanged() }} />}</div>
}

function CasoActions({ initial, onChanged, onBusy }) {
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
    setBusy(true); onBusy(true); setError(''); setSuccess('')
    try {
      await api.post(`/fiscalizacion/casos/${record.id}/acciones`, { ...Object.fromEntries(new FormData(event.currentTarget)), ...docs, accion: action, version: record.version })
      await reload(); setSuccess('Operación registrada.')
    } catch (failure) { setError(apiErrorMessage(failure)) } finally { setBusy(false); onBusy(false) }
  }
  const pendientes = record.solicitudes_fiscalizacion?.filter(item => item.resultado === 'pendiente') || []
  const docsReady = action === 'comunicar' ? docs.documento_id && docs.oficio_documento_id : action === 'responder' ? docs.documento_respuesta_id : action === 'cerrar' ? docs.informe_documento_id : true
  const documento = (key, label) => <DocumentPicker key={`${record.id}-${key}`} legajo={record.legajo_id} tipoDocumento={key === 'documento_id' ? record.tipo_documento_id : undefined} value={docs[key] || ''} onChange={id => setDocs(current => ({ ...current, [key]: id }))} label={label} />
  return <div className="space-y-4">
    {initial.version !== record.version && <p role="status" className="text-sm text-amber-900">El caso cambió. <button className={button} onClick={reload}>Actualizar caso</button></p>}
    {error && <p role="alert" className="text-sm text-rose-800">{error} <button type="button" className={button} onClick={reload}>Actualizar caso</button></p>}{success && <p role="status" className="text-sm text-teal-800">{success}</p>}
    <div className="grid gap-3 rounded-xl bg-slate-50 p-4 sm:grid-cols-3"><p className="text-sm">Estado<br /><strong>{record.estado}</strong></p><p className="text-sm">Fecha límite<br /><strong>{formatDate(record.fecha_limite)}</strong></p><p className="text-sm">Comunicaciones pendientes<br /><strong>{pendientes.length}</strong></p></div><p className="text-sm font-semibold text-teal-900">{record.tipo_documento?.nombre || record.tipo_muestra}</p><p className="text-sm">Resultado final: {record.resultado || 'Aún no registrado'}</p><Link to={`/legajos/${record.usuario_id}`} className="inline-block text-sm font-semibold text-teal-800 underline">Abrir legajo para cargar los PDF de sustento →</Link><p className="text-xs text-slate-500">Primero registra una comunicación con su oficio; después incorpora la respuesta y, al finalizar, el informe. Los PDF deben estar cargados en este legajo.</p>
    <h3 className="text-sm font-semibold text-slate-800">Comunicaciones y respuestas</h3>{!record.solicitudes_fiscalizacion?.length && <p className="rounded-xl border border-dashed border-slate-200 p-4 text-sm text-slate-500">Todavía no hay comunicaciones. Selecciona «Registrar comunicación y oficio» para iniciar el seguimiento.</p>}<ul className="space-y-3 text-sm">{record.solicitudes_fiscalizacion?.map(item => <li key={item.id} className="rounded-xl border border-slate-200 p-3"><p className="font-semibold">{item.entidad_consultada} · {item.numero_oficio}</p><p>Envío: {formatDate(item.fecha_envio)} · Respuesta: {formatDate(item.fecha_respuesta)} · {item.resultado}</p><p>{item.documento?.tipo_documento?.nombre} · {item.descripcion_hallazgo}</p><WorkflowHistory entity="solicitudes_fiscalizacion" id={item.id} /></li>)}</ul>
    {record.estado !== 'cancelado' && <form className="rounded-xl border border-teal-100 bg-teal-50/40 p-4" key={record.version} onSubmit={submit} aria-busy={busy}><fieldset disabled={busy} className="space-y-4">
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
  const [notice, setNotice] = useState('')
  const [caseBusy, setCaseBusy] = useState(false)
  const params = new URLSearchParams(Object.entries(filters).filter(([, value]) => value !== ''))
  const request = useManualQuery(`/fiscalizacion/casos?${params}`)
  return <div className="space-y-5"><header><p className="text-xs font-semibold uppercase tracking-widest text-teal-700">Verificación documental</p><h1 className="mt-1 text-3xl font-bold text-slate-900">Fiscalización</h1><p className="mt-3 text-sm text-slate-600">Verifica la autenticidad de los documentos y conserva las comunicaciones y evidencias de cada caso.</p></header>
    <ol className="grid gap-3 rounded-2xl border border-teal-100 bg-teal-50 p-4 text-sm text-teal-900 sm:grid-cols-3"><li><strong>1. Abrir caso</strong><p className="mt-1 text-xs">Selecciona legajo, criterio y plazo.</p></li><li><strong>2. Verificar documentos</strong><p className="mt-1 text-xs">Registra oficios, respuestas y hallazgos.</p></li><li><strong>3. Concluir</strong><p className="mt-1 text-xs">Asocia el informe final y cierra el caso.</p></li></ol>
    <p className="text-xs text-slate-500">El panel registra las comunicaciones. El envío a la entidad se realiza fuera del sistema por el medio indicado.</p>
    <NuevoCaso onCreated={id => { setNotice('Caso registrado. Continúa con la comunicación y su oficio.'); setSelected(id); request.retry() }} />
    {notice && <p role="status" className="rounded-xl bg-teal-50 p-3 text-sm text-teal-900">{notice}</p>}
    <section className={panel}><h2 className="mb-4 font-semibold text-slate-900">Buscar y dar seguimiento</h2><form key={JSON.stringify(filters)} className="grid items-end gap-3 md:grid-cols-[minmax(0,1fr)_auto_auto_auto]" onSubmit={event => { event.preventDefault(); setFilters({ ...Object.fromEntries(new FormData(event.currentTarget)), page: 1 }) }}><WorkflowField name="q" label="Nombre o apellidos de la persona" defaultValue={filters.q} required={false} maxLength={120} placeholder="Ej.: Ana Lucía Torres" /><WorkflowField name="estado" label="Estado" defaultValue={filters.estado} required={false}><option value="">Todos</option><option value="abierto">Abierto</option><option value="cerrado">Cerrado</option><option value="cancelado">Cancelado</option></WorkflowField><WorkflowField name="atrasado" label="Plazo" defaultValue={filters.atrasado} required={false}><option value="">Todos</option><option value="1">Solo atrasados</option></WorkflowField><button className={button}>Buscar casos</button></form><div className="mt-4 flex flex-wrap gap-2">{[['', '', 'Todos'], ['abierto', '', 'Abiertos'], ['abierto', '1', 'Atrasados'], ['cerrado', '', 'Cerrados']].map(([estado, atrasado, label]) => <button key={label} type="button" aria-pressed={filters.estado === estado && filters.atrasado === atrasado} className={`${button} ${filters.estado === estado && filters.atrasado === atrasado ? 'border-teal-400 bg-teal-50' : ''}`} onClick={() => setFilters(current => ({ ...current, estado, atrasado, page: 1 }))}>{label}</button>)}<button type="button" className={button} onClick={request.retry}>Actualizar</button><button type="button" className={button} onClick={() => setFilters({ q: '', estado: '', atrasado: '', page: 1 })}>Limpiar filtros</button></div></section>
    <QueryStatus request={request} />{request.data && <section aria-label="Casos encontrados" className="space-y-3"><p role="status" className="text-sm font-semibold text-slate-700">{request.data.total} caso(s) · Ordenados por fecha límite</p><ul className="space-y-3">{request.data.data.map(record => <li key={record.id} className={panel}><div className="flex flex-wrap items-center justify-between gap-4"><div><p className="text-xs font-semibold text-teal-800">{record.legajo?.numero_legajo}</p><h2 className="mt-1 font-semibold">{[record.usuario?.nombres, record.usuario?.apellido_paterno, record.usuario?.apellido_materno].filter(Boolean).join(' ')}</h2><p className="mt-1 text-sm text-slate-600">{record.tipo_documento?.nombre || record.tipo_muestra}</p><div className="mt-3 flex flex-wrap gap-2 text-xs"><span className={`rounded-full px-2 py-1 ${record.estado === 'cerrado' ? 'bg-teal-50 text-teal-900' : 'bg-slate-100 text-slate-700'}`}>{record.estado}</span><span className={`rounded-full px-2 py-1 ${record.estado === 'abierto' && record.fecha_limite.slice(0, 10) < todayLima() ? 'bg-rose-50 font-semibold text-rose-800' : 'bg-slate-50 text-slate-600'}`}>{record.estado === 'abierto' && record.fecha_limite.slice(0, 10) < todayLima() ? 'Atrasado · ' : 'Límite: '}{formatDate(record.fecha_limite)}</span></div></div><div className="flex flex-wrap gap-3"><Link to={`/legajos/${record.usuario_id}`} className={button}>Ver legajo</Link><button type="button" className={button} onClick={() => setSelected(record.id)}>Gestionar caso →</button></div></div></li>)}</ul>{!request.data.data.length && <div className={`${panel} text-sm text-slate-600`}><p className="font-semibold">No hay casos que coincidan.</p><p className="mt-2">Cambia los filtros o abre una fiscalización con un legajo activo.</p></div>}<WorkflowPagination data={request.data} loading={request.loading} setPage={page => setFilters(current => ({ ...current, page }))} /></section>}
    {selected && <DocumentoModal busy={caseBusy} titulo="Seguimiento de fiscalización" onCancel={() => setSelected('')}><Caso key={selected} id={selected} onBusy={setCaseBusy} onChanged={request.retry} /></DocumentoModal>}
  </div>
}

export default function FiscalizacionPage() {
  const authorized = usePuede('sistema.administrar')
  return authorized ? <Listado /> : <p role="alert">No tienes permiso para gestionar fiscalización.</p>
}
