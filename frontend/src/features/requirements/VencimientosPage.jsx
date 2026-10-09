import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import api, { apiErrorMessage } from '../../axios'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'
import { usePuede } from '../auth/session'

const card = 'rounded-2xl border border-slate-200 bg-white p-5'
const control = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:ring-2 focus:ring-teal-600 disabled:opacity-40'
const button = 'rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-40'
const primary = 'rounded-xl bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-40'
const colors = { verde: 'bg-emerald-50 text-emerald-800', amarillo: 'bg-amber-50 text-amber-800', rojo: 'bg-rose-50 text-rose-800', neutro: 'bg-slate-100 text-slate-700' }
const categories = { auxiliar: 'Auxiliar', asociado: 'Asociado', principal: 'Principal' }
const states = { vigente: 'Vigente', por_vencer: 'Por vencer', vencido: 'Pendiente de renovación', revision: 'Requiere revisión de datos' }
const renewalStates = { borrador: 'Borrador', en_revision: 'En revisión', observada: 'Observada', aprobada: 'Aprobada' }
const errorText = e => e.response?.data?.errors ? apiErrorMessage(e) : e.response?.data?.message || apiErrorMessage(e)
const dateText = value => value ? value.slice(0, 10).split('-').reverse().join('/') : 'Sin fecha acreditada'
const remainingText = n => n == null ? 'Sin calcular' : n === 0 ? 'Vence hoy' : n < 0 ? `Vencido hace ${-n} días` : `Vence en ${n} días`

function Field({ label, children }) {
  return <label className="block text-xs font-medium text-slate-600"><span className="mb-2 block">{label}</span>{children}</label>
}

function Policies() {
  const request = useLiveQuery('/vencimientos/opciones')
  const [draft, setDraft] = useState({ categoria: 'auxiliar', tipo_documento_id: '', modo: 'por_ciclo', antiguedad_maxima_dias: '' })
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const set = key => e => setDraft({ ...draft, [key]: e.target.value })
  async function submit(e) {
    e.preventDefault(); setBusy(true); setError(''); setMessage('')
    try {
      const result = await api.post('/vencimientos/politicas', { ...draft, antiguedad_maxima_dias: draft.antiguedad_maxima_dias ? Number(draft.antiguedad_maxima_dias) : null })
      setMessage(result.data.message); request.retry()
    } catch (e) { setError(errorText(e)) } finally { setBusy(false) }
  }
  return <details className={`${card} mb-6`}><summary className="cursor-pointer text-sm font-semibold text-teal-800">Configurar documentos exigibles por categoría</summary>
    <p className="mt-4 text-sm text-slate-500">Define los tipos que la institución exige renovar. Cada modificación conserva la versión anterior; los requisitos ya creados mantienen su regla. Un título permanente puede configurarse como «Solo si falta».</p>
    {request.error && <p role="alert" className="mt-3 text-rose-700">No se pudieron cargar las opciones. <button onClick={request.retry} className="underline">Reintentar</button></p>}
    <form onSubmit={submit} className="mt-5"><fieldset disabled={busy || !request.data} className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      <Field label="Categoría"><select className={control} value={draft.categoria} onChange={set('categoria')}>{Object.entries(categories).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></Field>
      <Field label="Tipo documental"><select required className={control} value={draft.tipo_documento_id} onChange={set('tipo_documento_id')}><option value="">Seleccionar tipo</option>{request.data?.data.tipos.map(t => <option key={t.id} value={t.id}>Sección {t.seccion_legajo_id} · {t.nombre}</option>)}</select></Field>
      <Field label="Cuándo solicitar"><select className={control} value={draft.modo} onChange={set('modo')}><option value="por_ciclo">En cada renovación del legajo</option><option value="si_falta">Solo si falta o no está aprobado</option><option value="por_vencimiento">Si vence o supera la antigüedad</option></select></Field>
      <Field label="Antigüedad máxima en días (opcional)"><input type="number" min={1} max={36500} className={control} value={draft.antiguedad_maxima_dias} onChange={set('antiguedad_maxima_dias')} /></Field>
      <button className={`${primary} justify-self-start`}>{busy ? 'Guardando…' : 'Guardar política'}</button>
    </fieldset></form>
    {message && <p role="status" className="mt-3 text-sm text-teal-800">{message}</p>}{error && <p role="alert" className="mt-3 text-sm text-rose-700">{error}</p>}
    <ul className="mt-5 space-y-2 text-sm text-slate-600">{request.data?.data.politicas.map(p => <li key={p.id}>{categories[p.categoria]} · {request.data.data.tipos.find(t => t.id === p.tipo_documento_id)?.nombre || 'Tipo actualmente restringido'} · {({ por_ciclo: 'Cada ciclo', si_falta: 'Si falta', por_vencimiento: 'Por vencimiento' })[p.modo]}{p.antiguedad_maxima_dias ? ` · máximo ${p.antiguedad_maxima_dias} días` : ''}</li>)}</ul>
    {request.data && !request.data.data.politicas.length && <p className="mt-4 text-sm text-slate-500">Todavía no se configuraron documentos exigibles.</p>}
  </details>
}

function Initialize({ data, onAction, busy }) {
  const options = useLiveQuery('/vencimientos/opciones')
  const [profile, setProfile] = useState(data.perfiles.length === 1 ? data.perfiles[0].id : '')
  const [document, setDocument] = useState('')
  const [boss, setBoss] = useState('')
  return <form onSubmit={e => { e.preventDefault(); onAction(`/legajos/${data.legajo_id}/inicializar`, { perfil_docente_id: profile, documento_id: document, jefe_inmediato_id: boss || null }) }} className="mt-5 rounded-xl bg-slate-50 p-4">
    <h3 className="font-semibold text-slate-800">Registrar fecha base acreditada</h3><p className="mt-2 text-sm text-slate-500">La fecha se toma de una resolución de incorporación o nombramiento aprobada. No se utiliza la fecha de apertura del legajo.</p>
    <fieldset disabled={busy} className="mt-4 grid gap-4 md:grid-cols-3">
      <Field label="Perfil docente aplicable"><select required className={control} value={profile} onChange={e => setProfile(e.target.value)}><option value="">Seleccionar perfil</option>{data.perfiles.map(p => <option key={p.id} value={p.id}>{categories[p.categoria]} · {p.puesto}</option>)}</select></Field>
      <Field label="Resolución aprobada"><select required className={control} value={document} onChange={e => setDocument(e.target.value)}><option value="">Seleccionar resolución</option>{data.resoluciones.map(d => <option key={d.id} value={d.id}>{d.numero} · {dateText(d.fecha)}</option>)}</select></Field>
      <Field label="Jefe inmediato (opcional)"><select className={control} value={boss} onChange={e => setBoss(e.target.value)}><option value="">Sin destinatario adicional</option>{options.data?.data.jefes.map(u => <option key={u.id} value={u.id}>{u.nombres} {u.apellido_paterno} {u.apellido_materno}</option>)}</select></Field>
      <button className={`${primary} justify-self-start`} disabled={!data.perfiles.length || !data.resoluciones.length}>Iniciar vigencia</button>
    </fieldset>
    {!data.resoluciones.length && <p className="mt-3 text-sm text-amber-800">Primero incorpora y recibe la resolución en la sección 02 o 07. Si acredita el perfil del puesto, también requiere fiscalización conforme.</p>}
  </form>
}

function Fiscalization({ item, data, documentId, busy, onAction }) {
  const [report, setReport] = useState('')
  const [start, setStart] = useState(data.hoy)
  const [conclusions, setConclusions] = useState('')
  const [confirmed, setConfirmed] = useState(false)
  return <form onSubmit={e => { e.preventDefault(); onAction(`/ciclos/${data.ciclo_id}/requisitos/${item.id}/fiscalizar`, { documento_id: documentId, informe_documento_id: report, fecha_inicio: start, conclusiones: conclusions, conformidad_confirmada: confirmed }) }} className="mt-4 rounded-xl bg-amber-50 p-4"><h5 className="text-sm font-semibold text-amber-900">Registrar fiscalización conforme</h5><p className="mt-2 text-xs text-amber-900">Registra el resultado de la comprobación realizada por ORH, con su informe de sustento. La carga y recepción del título no completan esta verificación.</p><fieldset disabled={busy} className="mt-4 grid gap-3 md:grid-cols-2"><Field label="Informe de verificación incorporado y aprobado"><select required className={control} value={report} onChange={e => setReport(e.target.value)}><option value="">Seleccionar informe de sustento</option>{data.informes?.filter(d => d.id !== documentId).map(d => <option key={d.id} value={d.id}>{d.nombre} · {dateText(d.fecha)} {d.numero}</option>)}</select></Field><Field label="Inicio de la verificación"><input required type="date" max={data.hoy} className={control} value={start} onChange={e => setStart(e.target.value)} /></Field><div className="md:col-span-2"><Field label="Conclusiones de la verificación"><textarea required minLength={10} maxLength={2000} rows={3} className={control} value={conclusions} onChange={e => setConclusions(e.target.value)} /></Field></div><label className="flex items-start gap-2 text-xs text-amber-900 md:col-span-2"><input required type="checkbox" checked={confirmed} onChange={e => setConfirmed(e.target.checked)} />Confirmo que se verificó la autenticidad y que el informe seleccionado acredita la conformidad.</label><button className={`${button} justify-self-start`} disabled={!confirmed}>Registrar conformidad con informe</button></fieldset></form>
}

function Requirement({ item, data, canUpload, canApprove, canFiscalize, busy, onAction, onDownload }) {
  const [pdf, setPdf] = useState(null)
  const [expiry, setExpiry] = useState('')
  const [document, setDocument] = useState(item.documento_id || '')
  const editable = ['borrador', 'observada'].includes(data.renovacion?.estado)
  const path = `/ciclos/${data.ciclo_id}/requisitos/${item.id}`
  const needsFiscalization = item.documentos.find(d => d.id === document)?.necesita_fiscalizacion
  function submit(e) {
    e.preventDefault()
    const form = new FormData(); form.append('pdf', pdf)
    if (expiry) form.append('fecha_vencimiento', expiry)
    onAction(`${path}/pdf`, form)
  }
  return <li className="rounded-xl border border-slate-200 p-4">
    <div className="flex flex-wrap items-start justify-between gap-3"><div><h4 className="text-sm font-semibold text-slate-800">{item.estado === 'cumplido' ? '✓ ' : '○ '}{item.nombre}</h4><p className="mt-1 text-xs text-slate-500">{item.motivo}{item.seccion ? ` · Sección ${item.seccion}` : ''}</p></div><span className={`rounded-full px-3 py-1 text-xs ${item.estado === 'cumplido' ? colors.verde : colors.amarillo}`}>{item.estado === 'cumplido' ? 'Evidencia vinculada' : item.presentacion ? 'PDF presentado' : 'Pendiente'}</span></div>
    {!item.habilitado && <p className="mt-3 text-sm text-rose-700">La sección o el tipo documental están deshabilitados. ORH debe resolver la configuración.</p>}
    {item.presentacion && <div className="mt-3 flex flex-wrap items-center gap-3 text-xs"><span className="break-all text-slate-600">{item.presentacion.nombre}</span><button disabled={busy} type="button" className="font-semibold text-teal-700 underline" onClick={() => onDownload(`/ciclos/${data.ciclo_id}/presentaciones/${item.presentacion.id}`, item.presentacion.nombre)}>Descargar presentación</button></div>}
    {item.permitido && item.habilitado && canUpload && editable && <form onSubmit={submit} className="mt-4"><fieldset disabled={busy} className="flex flex-wrap items-end gap-3"><Field label="Nuevo PDF"><input required type="file" accept="application/pdf,.pdf" className={`${control} max-w-sm`} onChange={e => setPdf(e.target.files[0] || null)} /></Field><Field label="Vencimiento del documento (si aplica)"><input type="date" className={control} value={expiry} onChange={e => setExpiry(e.target.value)} /></Field><button className={button} disabled={!pdf}>Presentar PDF</button></fieldset></form>}
    {item.permitido && item.habilitado && canApprove && <form onSubmit={e => { e.preventDefault(); onAction(`${path}/vincular`, { documento_id: document, fecha_vencimiento: expiry || null }) }} className="mt-4"><fieldset disabled={busy} className="flex flex-wrap items-end gap-3"><div className="min-w-60 flex-1"><Field label="Evidencia incorporada en el legajo"><select required className={control} value={document} onChange={e => setDocument(e.target.value)}><option value="">Seleccionar documento</option>{item.documentos.map(d => <option key={d.id} value={d.id} disabled={d.cargado_por_revisor}>{dateText(d.fecha)} {d.numero || 'Documento'}{d.cargado_por_revisor ? ' · requiere otro revisor' : d.necesita_fiscalizacion ? ' · falta fiscalización' : ''}</option>)}</select></Field></div><button className={button} disabled={needsFiscalization}>Vincular evidencia</button></fieldset><p className="mt-2 text-xs text-slate-500">Incorpora primero el PDF desde su sección y completa la recepción y revisión. Si hay una presentación, el archivo incorporado debe coincidir.</p></form>}
    {needsFiscalization && canFiscalize && <Fiscalization item={item} data={data} documentId={document} busy={busy} onAction={onAction} />}
  </li>
}

function Detail({ id, onClose }) {
  const request = useLiveQuery(`/vencimientos/legajos/${id}`)
  const manager = usePuede('vencimientos.gestionar')
  const notify = usePuede('vencimientos.notificar')
  const uploadOthers = usePuede('renovaciones.gestionar')
  const uploadOwn = usePuede('renovacion.propia')
  const approve = usePuede('renovaciones.aprobar')
  const fiscalize = usePuede('documento.fiscalizar')
  const ordinaryLegajo = usePuede('legajo.ver')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [reason, setReason] = useState('')
  async function action(path, payload = {}) {
    setBusy(true); setError(''); setMessage('')
    try { const result = await api.post(`/vencimientos${path}`, payload); setMessage(result.data.message || 'Registro guardado.'); request.retry() } catch (e) { setError(errorText(e)) } finally { setBusy(false) }
  }
  async function download(path, name) {
    setBusy(true); setError('')
    try {
      const response = await api.get(`/vencimientos${path}`, { responseType: 'blob' })
      const url = URL.createObjectURL(response.data); const a = document.createElement('a'); a.href = url; a.download = name; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000)
    } catch { setError('No se pudo descargar el PDF. Actualiza el detalle y comprueba tus permisos.') } finally { setBusy(false) }
  }
  async function certificate() {
    setBusy(true); setError('')
    try {
      const response = await api.get(`/vencimientos/legajos/${id}/constancia`)
      const { jsPDF } = await import('jspdf')
      const doc = new jsPDF(); doc.setFontSize(16); doc.text('Constancia de vigencia documental', 20, 25); doc.setFontSize(11)
      const text = [`Docente: ${request.data.data.nombre}`, `Legajo: ${response.data.data.numero_legajo}`, `Categoria: ${categories[request.data.data.categoria]}`, `Vencimiento: ${dateText(response.data.data.vigente_hasta)}`, `Fecha de consulta: ${dateText(response.data.data.fecha_consulta)}`]
      let y = 45
      for (const line of text) { const wrapped = doc.splitTextToSize(line, 170); doc.text(wrapped, 20, y); y += wrapped.length * 7 + 5 }
      doc.save(`vigencia-legajo-${response.data.data.numero_legajo}.pdf`)
    } catch (e) { setError(errorText(e)) } finally { setBusy(false) }
  }
  const data = request.data?.data
  const canUpload = uploadOthers || uploadOwn
  return <section className={`${card} mt-6`} aria-label="Detalle de vencimiento">
    <div className="flex justify-between gap-3"><h2 className="text-lg font-semibold text-slate-800">Detalle del requerimiento</h2><button type="button" className={button} onClick={onClose}>Cerrar</button></div>
    {request.error && <p role="alert" className="mt-4 text-rose-700">No se pudo actualizar el detalle. <button onClick={request.retry} className="underline">Reintentar</button></p>}{request.loading && !data && <p role="status" className="mt-4">Cargando detalle…</p>}
    {error && <p role="alert" className="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-800">{error}</p>}{message && <p role="status" className="mt-4 rounded-xl bg-teal-50 p-3 text-sm text-teal-800">{message}</p>}
    {data && <fieldset disabled={busy} className="mt-5 min-w-0 space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-4"><div><h3 className="text-xl font-semibold text-slate-800">{data.nombre}</h3><p className="mt-2 text-sm text-slate-500">{categories[data.categoria] || 'Categoría por revisar'} · Legajo {data.numero_legajo}</p></div><span className={`rounded-xl px-4 py-2 text-sm font-medium ${colors[data.color]}`}>{states[data.estado_vigencia]} · {remainingText(data.dias_restantes)}</span></div>
      <dl className="grid gap-4 text-sm text-slate-600 sm:grid-cols-3"><div><dt className="text-xs text-slate-400">Fecha base acreditada</dt><dd className="mt-1">{dateText(data.fecha_base)}</dd></div><div><dt className="text-xs text-slate-400">Vencimiento</dt><dd className="mt-1">{dateText(data.fecha_vencimiento)}</dd></div><div><dt className="text-xs text-slate-400">Renovación</dt><dd className="mt-1">{renewalStates[data.renovacion?.estado] || 'Sin iniciar'}</dd></div></dl>
      {data.mensaje_datos && <p className="rounded-xl bg-amber-50 p-4 text-sm text-amber-800">{data.mensaje_datos}</p>}
      {!data.ciclo_id && manager && <Initialize key={id} data={data} busy={busy} onAction={action} />}
      {data.ciclo_id && <>
        <p className="text-sm text-slate-500">La vigencia se reinicia después de aprobar todos los requisitos. Los PDFs anteriores permanecen en el historial.</p>
        <div className="flex flex-wrap gap-3">{canUpload && !data.renovacion && <button className={primary} onClick={() => action(`/ciclos/${data.ciclo_id}/preparar`)}>Registrar renovación</button>}{notify && <button className={button} onClick={() => action(`/ciclos/${data.ciclo_id}/notificar`)}>Notificar al docente</button>}<button className={button} onClick={certificate}>Descargar constancia de vigencia</button></div>
        {!!data.requisitos.length && <ul className="space-y-3">{data.requisitos.map(item => <Requirement key={`${item.id}:${item.documento_id}:${item.presentacion?.id}`} item={item} data={data} canUpload={canUpload} canApprove={approve} canFiscalize={fiscalize} busy={busy} onAction={action} onDownload={download} />)}</ul>}
        {!data.requisitos.length && <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-600">No hay documentos exigibles preparados. ORH debe configurar la política y preparar la renovación; no se reinicia la vigencia automáticamente.</p>}
        {data.renovacion?.observacion && <p className="rounded-xl bg-amber-50 p-4 text-sm text-amber-900">Observación: {data.renovacion.observacion}</p>}
        {canUpload && ['borrador', 'observada'].includes(data.renovacion?.estado) && <button className={primary} onClick={() => action(`/ciclos/${data.ciclo_id}/presentar`)}>Presentar para revisión</button>}
        {approve && data.renovacion?.estado === 'en_revision' && <div className="rounded-xl bg-slate-50 p-4"><div className="flex flex-wrap items-end gap-3"><div className="min-w-60 flex-1"><Field label="Motivo de observación"><textarea className={control} rows={2} maxLength={2000} value={reason} onChange={e => setReason(e.target.value)} /></Field></div><button className={button} disabled={!reason.trim()} onClick={() => action(`/ciclos/${data.ciclo_id}/revisar`, { accion: 'observar', motivo: reason })}>Observar</button><button className={primary} disabled={data.requisitos.some(r => r.estado !== 'cumplido') || !data.requisitos.length} onClick={() => action(`/ciclos/${data.ciclo_id}/revisar`, { accion: 'aprobar' })}>Aprobar renovación completa</button></div></div>}
        {!!data.avisos.length && <details><summary className="cursor-pointer text-sm font-semibold text-slate-700">Historial de notificaciones</summary><ul className="mt-3 space-y-3 text-sm text-slate-600">{data.avisos.map(a => <li key={a.id} className="rounded-xl bg-slate-50 p-3">Programado: {dateText(a.fecha_programada)} · {a.origen.replaceAll('_', ' ')}{a.envios.map(e => <p key={e.id} className="mt-1 text-xs">Correo: {e.estado} · {e.intentos} intentos{e.error ? ` · ${e.error}` : ''}</p>)}</li>)}</ul></details>}
      </>}
      <Link className="inline-block text-sm font-semibold text-teal-700 underline" to={ordinaryLegajo ? `/legajos/${data.usuario_id}` : '/mi-legajo'}>Abrir ficha del legajo</Link>
    </fieldset>}
  </section>
}

export default function VencimientosPage() {
  const view = usePuede('vencimientos.ver')
  const own = usePuede('renovacion.propia')
  const manager = usePuede('vencimientos.gestionar')
  const [params, setParams] = useSearchParams()
  const [selected, setSelected] = useState(null)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const request = useLiveQuery(view || own ? `/vencimientos?${params}` : null)
  function filter(key, value) { const next = new URLSearchParams(params); if (value) next.set(key, value); else next.delete(key); next.delete('page'); setParams(next, { replace: true }) }
  function range(kind) {
    const today = request.data?.hoy
    if (!today) return
    const from = new Date(`${today}T12:00:00Z`); const to = new Date(from)
    if (kind === 'semana') { from.setUTCDate(from.getUTCDate() - ((from.getUTCDay() + 6) % 7)); to.setTime(from.getTime()); to.setUTCDate(to.getUTCDate() + 6) } else to.setUTCDate(to.getUTCDate() + 30)
    const next = new URLSearchParams(params); next.set('desde', from.toISOString().slice(0, 10)); next.set('hasta', to.toISOString().slice(0, 10)); next.delete('page'); setParams(next)
  }
  async function process() {
    setBusy(true); setError(''); setMessage('')
    try { const result = await api.post('/vencimientos/procesar'); setMessage(result.data.message); request.retry() } catch (e) { setError(errorText(e)) } finally { setBusy(false) }
  }
  if (!view && !own) return <p role="alert">No tienes permiso para consultar vencimientos.</p>
  const result = request.data
  return <>
    <div className="mb-6 flex flex-wrap items-center justify-between gap-4"><div><p className="text-xs font-semibold uppercase tracking-widest text-teal-700">Requerimientos y vencimientos</p><h1 className="mt-2 text-3xl font-bold text-slate-800">Control de Vencimiento de Legajo</h1><p className="mt-3 text-sm text-slate-500">Auxiliar: 3 años · Asociado: 5 años · Principal: 7 años. Aviso a 7 días calendario.{!view && ' Se muestra únicamente tu legajo.'}</p></div>{manager && <button className={button} disabled={busy} onClick={process}>{busy ? 'Procesando…' : 'Procesar vencimientos ahora'}</button>}</div>
    {manager && <div className="mb-5 flex gap-3 text-sm"><span className="rounded-xl bg-teal-50 px-4 py-2 font-semibold text-teal-800">Vencimientos docentes</span><Link to="/requerimientos" className={button}>Requerimientos ordinarios</Link></div>}
    {message && <p role="status" className="mb-5 text-sm text-teal-800">{message}</p>}{error && <p role="alert" className="mb-5 text-sm text-rose-700">{error}</p>}
    {result?.correo_prueba && <p className="mb-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">El correo está en modo de prueba. Los avisos aparecen en el sistema; configura el servicio de correo institucional para entregarlos al destinatario.</p>}
    {result && <div className="mb-6 grid gap-4 sm:grid-cols-3">{[['Por vencer en 30 días', result.resumen.por_vencer, colors.amarillo], ['Pendientes de renovación', result.resumen.vencidos, colors.rojo], ['Revisión de datos', result.resumen.revision, colors.neutro]].map(([label, count, color]) => <div key={label} className={`${card} ${color}`}><p className="text-xs font-medium">{label}</p><p className="mt-2 text-3xl font-bold">{count}</p></div>)}</div>}
    {manager && <Policies />}
    <section className={`${card} mb-5`} aria-label="Filtros de vencimiento"><div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5"><Field label="Buscar docente o DNI"><input className={control} value={params.get('q') || ''} onChange={e => filter('q', e.target.value)} /></Field><Field label="Categoría"><select className={control} value={params.get('categoria') || ''} onChange={e => filter('categoria', e.target.value)}><option value="">Todas las categorías</option>{Object.entries(categories).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field><Field label="Estado"><select className={control} value={params.get('estado') || ''} onChange={e => filter('estado', e.target.value)}><option value="">Todos los estados</option>{Object.entries(states).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field><Field label="Vencimiento desde"><input type="date" className={control} value={params.get('desde') || ''} onChange={e => filter('desde', e.target.value)} /></Field><Field label="Vencimiento hasta"><input type="date" className={control} value={params.get('hasta') || ''} onChange={e => filter('hasta', e.target.value)} /></Field></div><div className="mt-4 flex flex-wrap items-center gap-3"><button className={button} disabled={!result} onClick={() => range('semana')}>Vencen esta semana</button><button className={button} disabled={!result} onClick={() => range('30')}>Hoy y próximos 30 días</button><button className={button} onClick={() => setParams({})}>Limpiar filtros</button><span className="text-xs text-slate-500">Verde: &gt;30 · Amarillo: 8–30 · Rojo: ≤7 y vencidos</span></div></section>
    {request.error && <p role="alert" className="mb-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-800">{errorText(request.error)} <button onClick={request.retry} className="underline">Reintentar</button></p>}{request.loading && !result && <p role="status" className={card}>Consultando vencimientos…</p>}
    {result && <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white" aria-label="Legajos docentes"><div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-slate-50 text-xs text-slate-500"><tr>{['Docente', 'Categoría', 'Vencimiento', 'Días restantes', 'Documentos', 'Acciones'].map(h => <th key={h} className="px-5 py-4 font-medium">{h}</th>)}</tr></thead><tbody>{result.data.map(row => <tr key={row.legajo_id} className="border-t border-slate-100 align-top"><td className="px-5 py-4 font-semibold text-slate-800">{row.nombre}<p className="mt-1 text-xs font-normal text-slate-500">{row.numero_legajo}</p></td><td className="px-5 py-4 text-slate-600">{categories[row.categoria] || 'Por revisar'}</td><td className="whitespace-nowrap px-5 py-4 text-slate-600">{dateText(row.fecha_vencimiento)}</td><td className="px-5 py-4"><span className={`inline-block rounded-xl px-3 py-2 text-xs font-medium ${colors[row.color]}`}>{remainingText(row.dias_restantes)}</span><p className="mt-1 text-xs text-slate-500">{states[row.estado_vigencia]}</p></td><td className="px-5 py-4 text-slate-600">{row.pendientes == null ? 'Por definir' : `${row.pendientes} pendientes`}{row.estado_renovacion && <p className="mt-1 text-xs">{renewalStates[row.estado_renovacion]}</p>}</td><td className="px-5 py-4"><button className={button} onClick={() => setSelected(row.legajo_id)} aria-pressed={selected === row.legajo_id}>Ver detalle</button></td></tr>)}</tbody></table></div>{!result.data.length && <p className="p-10 text-center text-sm text-slate-500">No hay legajos docentes para estos filtros.</p>}<div className="flex items-center justify-between gap-3 border-t border-slate-100 p-4 text-xs text-slate-500"><span>{result.total} legajos · Página {result.current_page} de {result.last_page} · Corte {dateText(result.hoy)}</span>{result.last_page > 1 && <div className="flex gap-2"><button className={button} disabled={result.current_page <= 1} onClick={() => { const p = new URLSearchParams(params); p.set('page', result.current_page - 1); setParams(p) }}>Anterior</button><button className={button} disabled={result.current_page >= result.last_page} onClick={() => { const p = new URLSearchParams(params); p.set('page', result.current_page + 1); setParams(p) }}>Siguiente</button></div>}</div></section>}
    {selected && <Detail key={selected} id={selected} onClose={() => setSelected(null)} />}
  </>
}
