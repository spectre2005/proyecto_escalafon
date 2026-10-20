import { useState } from 'react'
import { Link } from 'react-router-dom'
import api, { apiErrorMessage } from '../../axios'
import { usePuede } from '../auth/session'
import { useManualQuery } from '../../shared/queries/useManualQuery'
import Icon from '../../components/ui/Icon'
import LegajoSearch from '../../shared/LegajoSearch'
import DocumentoModal from '../legajos/components/documentos/DocumentoModal'
import { DocumentPicker, QueryStatus, WorkflowField, WorkflowHistory, WorkflowPagination } from '../../shared/WorkflowControls'
import { formatDate, todayLima } from '../../shared/dates'

const panel = 'rounded-2xl border border-slate-200 bg-white p-5'
const button = 'rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-teal-800 disabled:opacity-50'

function NuevoCaso({ onCreated, onExisting }) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [legajo, setLegajo] = useState(null)
  const [tipo, setTipo] = useState('')
  const tipos = useManualQuery(open && legajo?.estado_legajo === 'activo' ? `/fiscalizacion/tipos?legajo_id=${encodeURIComponent(legajo.id)}` : null)
  const tipoDisponible = Boolean(tipos.data?.data.some(item => item.id === tipo))
  const coincidenciasUrl = open && legajo?.estado_legajo === 'activo' && tipoDisponible
    ? `/fiscalizacion/casos?${new URLSearchParams({ estado: 'abierto', legajo_id: legajo.id, tipo_documento_id: tipo })}` : null
  const existentes = useManualQuery(coincidenciasUrl)
  const [confirmacion, setConfirmacion] = useState('')
  const seleccion = `${legajo?.id}:${tipo}`
  const independiente = confirmacion === seleccion
  const requiereConfirmacion = existentes.data?.total > 0 && !independiente
  const [inicio, setInicio] = useState(todayLima())
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function submit(event) {
    event.preventDefault()
    if (busy || !coincidenciasUrl || existentes.loading || existentes.error || requiereConfirmacion) return
    setBusy(true); setError('')
    try {
      const actuales = await api.get(coincidenciasUrl)
      if (actuales.data.total > 0 && !independiente) {
        existentes.retry()
        setError('Ya existe una fiscalización abierta. Revisa el caso existente antes de continuar.')
        return
      }
      const response = await api.post('/fiscalizacion/casos', { ...Object.fromEntries(new FormData(event.currentTarget)), legajo_id: legajo.id })
      setOpen(false); setLegajo(null); setSearch(''); setTipo(''); setInicio(todayLima()); onCreated(response.data.data.id)
    } catch (failure) { setError(failure.response?.data?.errors?.tipo_documento_id?.[0] || apiErrorMessage(failure)) } finally { setBusy(false) }
  }
  return <section className={panel}><div className="flex flex-wrap items-center justify-between gap-3"><div><h2 className="font-semibold text-slate-900">Nuevo caso de fiscalización</h2><p className="mt-1 text-sm text-slate-500">Selecciona un legajo activo y define el alcance y las fechas.</p></div><button type="button" disabled={busy} className={button} aria-haspopup="dialog" aria-expanded={open} onClick={() => { setOpen(true); setError(''); setTipo(''); setConfirmacion('') }}>+ Abrir fiscalización</button></div>{open && <DocumentoModal titulo="Nuevo caso de fiscalización" busy={busy} onCancel={() => setOpen(false)}><form className="mt-5" onSubmit={submit} aria-busy={busy}><fieldset disabled={busy} className="space-y-5">
    <section className="rounded-xl border border-slate-200 p-4"><h3 className="mb-3 text-sm font-semibold text-teal-900">1. Persona y legajo</h3><LegajoSearch search={search} legajo={legajo} onSearch={setSearch} onSelect={value => { setLegajo(value); setTipo(''); setConfirmacion('') }} onClear={() => { setLegajo(null); setSearch(''); setTipo(''); setConfirmacion('') }} />{legajo && legajo.estado_legajo !== 'activo' && <p role="alert" className="mt-3 text-sm text-rose-800">Selecciona un legajo activo para abrir una fiscalización.</p>}</section>
    <section className="space-y-3 rounded-xl border border-slate-200 p-4"><h3 className="text-sm font-semibold text-teal-900">2. Documento a verificar</h3>{!legajo ? <p className="text-sm text-slate-500">Selecciona primero el legajo.</p> : <><QueryStatus request={tipos} />{tipos.data && <>{tipos.data.data.length ? <WorkflowField name="tipo_documento_id" label="Tipo de documento cargado" value={tipo} onChange={event => { setTipo(event.target.value); setConfirmacion('') }}><option value="">Seleccionar tipo documental</option>{tipos.data.data.map(item => <option key={item.id} value={item.id}>{item.nombre} · {item.cantidad} documento(s)</option>)}</WorkflowField> : <p role="status" className="text-sm text-slate-600">Este legajo no tiene documentos disponibles para fiscalizar. Carga primero el PDF desde su ficha.</p>}<div className="flex flex-wrap items-center justify-between gap-3 text-xs"><Link to={`/legajos/${legajo.usuario_id}`} target="_blank" rel="noopener noreferrer" className="font-semibold text-teal-800 underline">Abrir ficha del legajo ↗</Link><button type="button" className={button} onClick={() => { setTipo(''); tipos.retry() }}>Actualizar documentos</button></div><p className="text-xs text-slate-500">Al registrar la comunicación elegirás el archivo de este tipo y su oficio de consulta.</p></>}</>}</section>
    {coincidenciasUrl && <div className="space-y-3">
      <QueryStatus request={existentes} />
      {existentes.data?.total > 0 && <section aria-label="Fiscalizaciones abiertas del mismo tipo" className="space-y-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
        <div role="status"><h3 className="text-sm font-semibold text-amber-950">Ya hay {existentes.data.total} caso(s) abierto(s) para este legajo y tipo documental</h3><p className="mt-1 text-xs leading-relaxed text-amber-900">Si verificarás el mismo certificado, continúa en un caso existente. Allí puedes añadir comunicaciones o ampliar el plazo. Un nuevo caso corresponde a una verificación independiente.</p></div>
        <ul className="space-y-2">{existentes.data.data.map(caso => <li key={caso.id} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-white p-3"><div className="text-xs text-slate-700"><p className="font-semibold">Caso {caso.id.slice(0, 8)}</p><p className="mt-1">Inicio: {formatDate(caso.fecha_inicio)} · Límite: {formatDate(caso.fecha_limite)}</p></div><button type="button" className={button} onClick={() => { setOpen(false); onExisting(caso.id) }}>Abrir caso existente <Icon name="arrow" size={16} /></button></li>)}</ul>
        {existentes.data.total > existentes.data.data.length && <p className="text-xs text-amber-900">Se muestran los primeros {existentes.data.data.length} casos por fecha límite. Puedes consultar los demás en el listado de fiscalizaciones.</p>}
        <label className="flex items-start gap-2 text-sm text-amber-950"><input type="checkbox" checked={independiente} onChange={event => setConfirmacion(event.target.checked ? seleccion : '')} className="mt-1 accent-teal-700" />He revisado los casos abiertos y necesito registrar una verificación independiente.</label>
      </section>}
    </div>}
    <section className="space-y-3 rounded-xl border border-slate-200 p-4"><h3 className="text-sm font-semibold text-teal-900">3. Fechas y plazo</h3><div className="grid gap-4 md:grid-cols-3"><WorkflowField name="fecha_vinculacion" label="Fecha de vinculación" type="date" max={inicio} /><WorkflowField name="fecha_inicio" label="Inicio de fiscalización" type="date" value={inicio} onChange={event => setInicio(event.target.value)} max={todayLima()} /><WorkflowField name="fecha_limite" label="Fecha límite" type="date" min={inicio} /></div><p className="text-xs text-slate-500">La vinculación no puede ser posterior al inicio. La fecha límite debe ser igual o posterior al inicio. Las fechas se introducen explícitamente.</p></section>
    {error && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm text-rose-800">{error}</p>}
    <div className="space-y-4 border-t border-slate-200 pt-5">
      <div className="flex items-start gap-3 rounded-xl border border-teal-100 bg-teal-50 p-4">
        <Icon name="arrow" size={18} className="mt-0.5 shrink-0 text-teal-700" />
        <div><p className="text-sm font-semibold text-teal-900">Continúa con la verificación</p><p id="nuevo-caso-ayuda" className="mt-1 text-xs leading-relaxed text-teal-800">Al crear el caso se abrirá su seguimiento para registrar el oficio de consulta, la respuesta de la entidad y el informe final.</p></div>
      </div>
      {!tipoDisponible && <p role="status" className="text-xs text-slate-600">Selecciona un legajo activo y un tipo de documento disponible. Completa también las fechas para registrar el caso.</p>}
      <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
        <button type="button" disabled={busy} className={button} onClick={() => setOpen(false)}>Cancelar</button>
        <button type="submit" aria-describedby="nuevo-caso-ayuda" disabled={busy || !tipoDisponible || existentes.loading || Boolean(existentes.error) || !existentes.data || requiereConfirmacion} className="inline-flex items-center justify-center gap-2 rounded-xl border border-teal-700 bg-teal-700 px-5 py-3 text-sm font-semibold text-white hover:bg-teal-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 disabled:cursor-not-allowed disabled:border-slate-200 disabled:bg-slate-200 disabled:text-slate-600">
          {busy ? 'Creando caso…' : 'Crear caso y continuar'}<Icon name="arrow" size={16} />
        </button>
      </div>
    </div>
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
  const [documentosVersion, setDocumentosVersion] = useState(0)
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
  const docsReady = action === 'comunicar' ? docs.documento_id && docs.oficio_documento_id && docs.documento_id !== docs.oficio_documento_id : action === 'responder' ? docs.documento_respuesta_id : action === 'cerrar' ? docs.informe_documento_id : true
  const documento = (key, label) => <DocumentPicker key={`${record.id}-${key}-${documentosVersion}-${key === 'oficio_documento_id' ? docs.documento_id : ''}`} legajo={record.legajo_id} tipoDocumento={key === 'documento_id' ? record.tipo_documento_id : undefined} excluirDocumento={key === 'oficio_documento_id' ? docs.documento_id : undefined} disabled={key === 'oficio_documento_id' && !docs.documento_id} value={docs[key] || ''} onChange={id => setDocs(current => ({ ...current, [key]: id, ...(key === 'documento_id' ? { oficio_documento_id: '' } : {}) }))} label={label} />
  return <div className="space-y-4">
    {initial.version !== record.version && <p role="status" className="text-sm text-amber-900">El caso cambió. <button className={button} onClick={reload}>Actualizar caso</button></p>}
    {error && <p role="alert" className="text-sm text-rose-800">{error} <button type="button" className={button} onClick={reload}>Actualizar caso</button></p>}{success && <p role="status" className="text-sm text-teal-800">{success}</p>}
    <div className="grid gap-3 rounded-xl bg-slate-50 p-4 sm:grid-cols-3"><p className="text-sm">Estado<br /><strong>{record.estado}</strong></p><p className="text-sm">Fecha límite<br /><strong>{formatDate(record.fecha_limite)}</strong></p><p className="text-sm">Comunicaciones pendientes<br /><strong>{pendientes.length}</strong></p></div><p className="text-sm font-semibold text-teal-900">{record.tipo_documento?.nombre || record.tipo_muestra}</p><p className="text-sm">Resultado final: {record.resultado || 'Aún no registrado'}</p><Link to={`/legajos/${record.usuario_id}`} className="inline-block text-sm font-semibold text-teal-800 underline">Abrir legajo para cargar los PDF de sustento →</Link><p className="text-xs text-slate-500">Primero registra una comunicación con su oficio; después incorpora la respuesta y, al finalizar, el informe. Los PDF deben estar cargados en este legajo.</p>
    <h3 className="text-sm font-semibold text-slate-800">Comunicaciones y respuestas</h3>{!record.solicitudes_fiscalizacion?.length && <p className="rounded-xl border border-dashed border-slate-200 p-4 text-sm text-slate-500">Todavía no hay comunicaciones. Selecciona «Registrar comunicación y oficio» para iniciar el seguimiento.</p>}<ul className="space-y-3 text-sm">{record.solicitudes_fiscalizacion?.map(item => <li key={item.id} className="rounded-xl border border-slate-200 p-3"><p className="font-semibold">{item.entidad_consultada} · {item.numero_oficio}</p><p>Envío: {formatDate(item.fecha_envio)} · Respuesta: {formatDate(item.fecha_respuesta)} · {item.resultado}</p><p>{item.documento?.tipo_documento?.nombre} · {item.descripcion_hallazgo}</p><WorkflowHistory entity="solicitudes_fiscalizacion" id={item.id} /></li>)}</ul>
    {record.estado !== 'cancelado' && <form className="rounded-xl border border-slate-200 bg-white p-4 sm:p-5" key={record.version} onSubmit={submit} aria-busy={busy}><fieldset disabled={busy} className="min-w-0 space-y-5 [&_fieldset]:min-w-0 [&_select]:min-w-0 [&_select]:max-w-full">
      <label className="grid gap-2 text-sm sm:max-w-md">¿Qué deseas registrar?<select required value={action} onChange={event => { setAction(event.target.value); setDocs({}) }} className="rounded-xl border border-slate-300 bg-white p-3"><option value="">Seleccionar acción</option>{record.estado === 'cerrado' ? <option value="reabrir">Reabrir con motivo</option> : <><option value="comunicar">Registrar comunicación y oficio</option>{pendientes.length > 0 && <option value="responder">Registrar respuesta y evidencia</option>}<option value="cerrar">Registrar informe y cerrar</option><option value="prorrogar">Ampliar plazo</option><option value="cancelar">Cancelar caso</option></>}</select></label>
      {action === 'comunicar' && <>
        <section className="space-y-3 rounded-xl bg-slate-50 p-4">
          <div><h3 className="text-sm font-semibold text-slate-900">1. Documentos de la comunicación</h3><p className="mt-1 text-xs leading-relaxed text-slate-600">Primero selecciona el certificado que verificarás. Después elige otro PDF: el oficio dirigido a la entidad que lo emitió, solicitando confirmar su autenticidad.</p></div>
          <div className="grid min-w-0 gap-4 md:grid-cols-2">{documento('documento_id', 'Documento que se fiscaliza')}{documento('oficio_documento_id', 'Oficio de consulta')}</div>
          <p className="text-xs leading-relaxed text-slate-600">¿No encuentras el oficio? Cárgalo en la sección 12, «Otros documentos institucionales», del mismo legajo, con un nombre claro y su número. Después pulsa «Actualizar documentos».</p>
          <div className="flex flex-wrap gap-3"><Link to={`/legajos/${record.usuario_id}?categoria=seccion-12`} target="_blank" rel="noopener noreferrer" className="text-xs font-semibold text-teal-800 underline">Cargar oficio en el legajo ↗</Link><button type="button" className={button} onClick={() => { setDocs({}); setDocumentosVersion(value => value + 1) }}>Actualizar documentos</button></div>
        </section>
        <section className="space-y-3 rounded-xl bg-slate-50 p-4">
          <div><h3 className="text-sm font-semibold text-slate-900">2. Destinatario y envío</h3><p className="mt-1 text-xs text-slate-600">Registra los datos del envío realizado fuera del sistema.</p></div>
          <div className="grid min-w-0 gap-4 sm:grid-cols-2">
            <WorkflowField name="entidad_consultada" label="Entidad consultada" maxLength={200} placeholder="Ej.: Universidad que emitió el certificado" />
            <div><WorkflowField name="medio" label="Medio de comunicación" maxLength={30} list="fiscalizacion-medios" placeholder="Ej.: Mesa de partes" /><datalist id="fiscalizacion-medios"><option value="Mesa de partes" /><option value="Correo electrónico" /><option value="Oficio físico" /><option value="Plataforma virtual" /></datalist></div>
            <WorkflowField name="numero_oficio" label="Número de oficio" maxLength={40} placeholder="Ej.: OF-025-2026-ORH" />
            <WorkflowField name="fecha_envio" label="Fecha de envío" type="date" defaultValue={todayLima()} min={record.fecha_inicio?.slice(0, 10)} max={todayLima()} />
          </div>
        </section>
      </>}
      {action === 'responder' && <section className="space-y-4 rounded-xl bg-slate-50 p-4">
        <h3 className="text-sm font-semibold text-slate-900">Respuesta de la entidad</h3>
        <WorkflowField name="solicitud_id" label="Comunicación pendiente"><option value="">Seleccionar comunicación</option>{pendientes.map(item => <option key={item.id} value={item.id}>{item.numero_oficio} · {item.entidad_consultada}</option>)}</WorkflowField>
        {documento('documento_respuesta_id', 'PDF de la respuesta cargado en el legajo')}
        <div className="grid gap-4 sm:grid-cols-2"><WorkflowField name="fecha_respuesta" label="Fecha de respuesta" type="date" defaultValue={todayLima()} max={todayLima()} /><WorkflowField name="resultado" label="Resultado de la comunicación"><option value="conforme">Conforme</option><option value="observado">Observado</option><option value="falso">Falso</option></WorkflowField></div>
        <WorkflowField name="descripcion_hallazgo" label="Descripción del hallazgo o verificación" type="textarea" maxLength={5000} placeholder="Describe lo que confirmó la entidad y la evidencia recibida." />
      </section>}
      {action === 'cerrar' && <section className="space-y-4 rounded-xl bg-slate-50 p-4">
        <h3 className="text-sm font-semibold text-slate-900">Informe y cierre del caso</h3>
        {documento('informe_documento_id', 'Informe final cargado en el legajo')}
        <div className="sm:max-w-xs"><WorkflowField name="fecha_fin" label="Fecha de cierre" type="date" defaultValue={todayLima()} min={record.fecha_inicio?.slice(0, 10)} max={todayLima()} /></div>
        <WorkflowField name="conclusiones" label="Conclusiones sustentadas" type="textarea" maxLength={5000} />
      </section>}
      {action === 'prorrogar' && <div className="sm:max-w-xs"><WorkflowField name="fecha_limite" label="Nueva fecha límite" type="date" min={todayLima()} /></div>}
      {['reabrir', 'prorrogar', 'cancelar'].includes(action) && <WorkflowField name="motivo" label="Motivo" type="textarea" maxLength={1000} />}
      {action && <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 pt-4"><p className="text-xs text-slate-500">La operación quedará registrada en el historial del caso.</p><button type="submit" className="inline-flex items-center justify-center gap-2 rounded-xl bg-teal-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-600" disabled={busy || !docsReady}>{busy ? 'Guardando…' : ({ comunicar: 'Guardar comunicación', responder: 'Guardar respuesta', cerrar: 'Guardar informe y cerrar', prorrogar: 'Guardar ampliación', cancelar: 'Confirmar cancelación', reabrir: 'Reabrir caso' }[action])}<Icon name="check" size={16} /></button></div>}
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
    <NuevoCaso onExisting={id => { setNotice('Continúa el seguimiento en el caso existente.'); setSelected(id) }} onCreated={id => { setNotice('Caso registrado. Continúa con la comunicación y su oficio.'); setSelected(id); request.retry() }} />
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
