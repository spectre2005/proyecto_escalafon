import { useEffect, useState } from 'react'
import { apiErrorMessage } from '../axios'
import { todayLima } from './dates'
import DocumentoModal from '../features/legajos/components/documentos/DocumentoModal'
import { auditChanges, auditLabel, auditValue } from './auditPresentation'
import { QueryStatus, WorkflowPagination } from './WorkflowControls'

const button = 'rounded-xl border border-slate-300 px-3 py-2 text-sm font-semibold text-teal-800 hover:bg-teal-50 disabled:opacity-50'
const panel = 'rounded-2xl border border-slate-200 bg-white'
const auditDate = value => value ? new Date(value).toLocaleString('es-PE', { timeZone: 'America/Lima' }) : 'Sin fecha'

const control = 'rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800'
export default function AuditPanel({ load, download, plataforma = false }) {
  const [source, setSource] = useState('/auditoria')
  const [selected, setSelected] = useState(null)
  const [draft, setDraft] = useState(() => ({ desde: todayLima(new Date(Date.now() - 29 * 86400000)), hasta: todayLima(), actor: '', accion: '', entidad: '', institucion: '' }))
  const [filters, setFilters] = useState(draft)
  const [page, setPage] = useState(1)
  const [attempt, setAttempt] = useState(0)
  const [result, setState] = useState({ loading: true })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const params = new URLSearchParams(Object.entries({ ...filters, page }).filter(([, value]) => value !== '')).toString()
  const queryKey = `${source}?${params}&intento=${attempt}`
  const state = result.key === queryKey ? result : { loading: true }
  useEffect(() => {
    const controller = new AbortController()
    load(`${source}?${params}`, controller.signal).then(data => { if (!controller.signal.aborted) setState({ key: queryKey, data }) }).catch(failure => {
      if (!controller.signal.aborted) setState({ key: queryKey, error: failure })
    })
    return () => controller.abort()
  }, [source, params, queryKey, load])
  async function exportar() {
    if (busy) return
    setBusy(true)
    setError('')
    try {
      const response = await download(`${source}/exportar?${params}`)
      const url = URL.createObjectURL(response.data)
      const link = document.createElement('a')
      link.href = url
      link.download = plataforma ? 'auditoria-plataforma.csv' : source.endsWith('accesos') ? 'accesos-legajo.csv' : 'auditoria-institucional.csv'
      document.body.append(link)
      link.click()
      link.remove()
      setTimeout(() => URL.revokeObjectURL(url), 1000)
    } catch (failure) {
      if (failure.response?.data instanceof Blob) {
        try { failure.response.data = JSON.parse(await failure.response.data.text()) } catch { /* Usar el mensaje general. */ }
      }
      setError(apiErrorMessage(failure))
    } finally { setBusy(false) }
  }
  const dirty = JSON.stringify(draft) !== JSON.stringify(filters)
  function periodo(days) {
    const hasta = todayLima()
    const mediodia = Date.parse(`${hasta}T12:00:00-05:00`)
    const next = { ...draft, desde: todayLima(new Date(mediodia - Math.max(0, days - 1) * 86400000)), hasta }
    setDraft(next); setFilters(next); setPage(1)
  }
  function limpiar() {
    const next = { desde: todayLima(new Date(Date.now() - 29 * 86400000)), hasta: todayLima(), actor: '', accion: '', entidad: '', institucion: '' }
    setDraft(next); setFilters(next); setPage(1)
  }
  function cambiarRegistro(value) {
    setSource(value); setPage(1); setSelected(null)
    if (value.endsWith('accesos')) {
      setDraft(current => ({ ...current, entidad: '' }))
      setFilters(current => ({ ...current, entidad: '' }))
    }
  }
  const campo = (name, label, type = 'text') => <label className="grid gap-1 text-sm">{label}<input type={type} name={name} value={draft[name]} maxLength={150} max={type === 'date' ? name === 'desde' && draft.hasta ? draft.hasta : todayLima() : undefined} min={name === 'hasta' ? draft.desde || undefined : undefined} className={control} onChange={event => setDraft(current => ({ ...current, [name]: event.target.value }))} /></label>
  return <div className="space-y-5"><header><p className="text-xs font-semibold uppercase tracking-widest text-teal-700">Trazabilidad y control</p><h1 className="mt-1 text-2xl font-bold text-slate-900">{plataforma ? 'Auditoría de plataforma' : 'Auditoría institucional'}</h1><p className="mt-2 text-sm text-slate-600">Consulta quién realizó cada operación y revisa los cambios. Fechas en hora de Lima.</p></header>
    {!plataforma && <nav aria-label="Tipo de registro" className="flex flex-wrap gap-2 border-b border-slate-200 pb-3">{[['/auditoria', 'Operaciones y cambios'], ['/auditoria/accesos', 'Accesos y entregas']].map(([value, label]) => <button key={value} type="button" aria-pressed={source === value} className={`${button} ${source === value ? 'border-teal-600 bg-teal-50' : ''}`} onClick={() => cambiarRegistro(value)}>{label}</button>)}</nav>}
    <section className={`${panel} p-4 sm:p-5`} aria-label="Filtros de auditoría"><div className="mb-4 flex flex-wrap items-center justify-between gap-3"><h2 className="text-sm font-semibold text-slate-800">Buscar actividad</h2><div className="flex flex-wrap items-center gap-2"><span className="text-xs text-slate-500">Periodo:</span>{[[0, 'Hoy'], [7, '7 días'], [30, '30 días']].map(([days, label]) => <button type="button" key={days} className={button} onClick={() => periodo(days)}>{label}</button>)}</div></div>
      <form className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" onSubmit={event => { event.preventDefault(); setFilters({ ...draft }); setPage(1) }}>{campo('desde', 'Desde', 'date')}{campo('hasta', 'Hasta', 'date')}{campo('actor', 'Responsable: nombre o correo')}{campo('accion', 'Acción (parte del nombre)')}{plataforma ? campo('institucion', 'Institución: nombre o identificador') : source === '/auditoria' && campo('entidad', 'Entidad del registro')}<div className="flex flex-wrap items-end gap-2"><button className="rounded-xl bg-teal-700 px-3 py-2 text-sm font-semibold text-white hover:bg-teal-800">Buscar</button><button type="button" className={button} onClick={limpiar}>Restablecer</button><button type="button" className={button} onClick={() => setAttempt(value => value + 1)}>Actualizar</button></div></form>
      {dirty && <p role="status" className="mt-3 text-xs text-amber-900">Tienes filtros sin aplicar. Pulsa Buscar para actualizar los resultados y la exportación.</p>}
    </section>
    <QueryStatus request={{ ...state, retry: () => setAttempt(value => value + 1) }} />{error && <p role="alert" className="text-sm text-rose-800">{error}</p>}
    {state.data && <><div className="flex flex-wrap items-center justify-between gap-3"><div><p role="status" className="text-sm font-semibold text-slate-800">{state.data.total} registro(s) encontrado(s)</p><p className="mt-1 text-xs text-slate-500">Más recientes primero · CSV de todos los resultados filtrados</p></div><button type="button" disabled={busy || dirty} onClick={exportar} className={button}>{busy ? 'Preparando CSV…' : 'Exportar CSV'}</button></div>
      <div className={`${panel} divide-y divide-slate-100 overflow-hidden`}>{state.data.data.map(event => <article key={event.id} className="flex flex-wrap items-center justify-between gap-4 p-4 sm:px-5"><div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><h2 className="break-words text-sm font-semibold text-slate-900">{auditLabel(event.accion)}</h2><span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{auditLabel(event.institucion || event.legajo || event.entidad || 'Plataforma')}</span></div><p className="mt-2 text-sm text-slate-700">{event.actor || 'Sistema'}</p><p className="mt-1 text-xs text-slate-500">{auditDate(event.fecha)}</p>{event.motivo && <p className="mt-2 line-clamp-2 text-xs text-slate-600">{event.motivo}</p>}</div><button type="button" className={button} onClick={() => setSelected(event)}>Ver detalle →</button></article>)}{!state.data.data.length && <div className="p-6 text-sm text-slate-600"><p className="font-semibold">No hay actividad para estos filtros.</p><p className="mt-2">Amplía el periodo o revisa el responsable y la acción.</p></div>}</div><WorkflowPagination data={state.data} setPage={setPage} loading={state.loading} /></>}
    {selected && <AuditDetail event={selected} onClose={() => setSelected(null)} />}
  </div>
}

function AuditDetail({ event, onClose }) {
  const changes = auditChanges(event.antes, event.despues)
  return <DocumentoModal titulo="Detalle de auditoría" onCancel={onClose}><div className="space-y-5"><div className="rounded-xl border border-teal-100 bg-teal-50 p-4"><p className="font-semibold text-teal-900">{auditLabel(event.accion)}</p><p className="mt-1 text-sm text-slate-700">{event.actor || 'Sistema'} · {auditDate(event.fecha)}</p></div>
    <dl className="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 text-sm sm:grid-cols-2">{[['Acción registrada', event.accion], ['Entidad / legajo', event.institucion || event.legajo || event.entidad], ['ID del evento', event.id], ['ID del registro', event.registro_id], ['Autorización', event.autorizacion_id], ['IP de origen', event.ip]].filter(([, value]) => value !== undefined && value !== null && value !== '').map(([label, value]) => <div key={label}><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-1 break-all font-medium text-slate-800">{value}</dd></div>)}</dl>
    {event.motivo && <section><h3 className="text-sm font-semibold">Motivo</h3><p className="mt-2 whitespace-pre-wrap text-sm text-slate-700">{event.motivo}</p></section>}
    <section><h3 className="mb-3 text-sm font-semibold text-slate-800">Cambios registrados{changes.length > 0 && ` (${changes.length})`}</h3>{changes.length ? <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white"><table className="w-full table-fixed text-left text-xs"><caption className="sr-only">Comparación de valores anteriores y posteriores</caption><thead className="bg-slate-50 text-slate-600"><tr><th scope="col" className="w-1/4 p-3">Campo</th><th scope="col" className="p-3">Antes</th><th scope="col" className="p-3">Después</th></tr></thead><tbody>{changes.map(row => <tr key={row.key} className="border-t border-slate-100 align-top"><th scope="row" className="break-words p-3 font-medium text-slate-700">{auditLabel(row.key)}</th><td className="break-words whitespace-pre-wrap p-3 text-slate-600">{auditValue(row.before)}</td><td className="break-words whitespace-pre-wrap bg-teal-50/40 p-3 text-slate-800">{auditValue(row.after)}</td></tr>)}</tbody></table></div> : <p className="rounded-xl border border-dashed border-slate-200 p-4 text-sm text-slate-500">Este evento no contiene diferencias de campos para mostrar. Los accesos registran la operación y su responsable.</p>}</section>
    {(event.antes || event.despues) && <details className="rounded-xl border border-slate-200 bg-white p-4"><summary className="cursor-pointer text-xs font-semibold text-teal-800">Ver datos completos del registro</summary><div className="mt-3 grid gap-3 sm:grid-cols-2">{[['Antes', event.antes], ['Después', event.despues]].map(([label, value]) => <div key={label}><h3 className="mb-2 text-xs font-semibold">{label}</h3><pre className="max-h-64 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-slate-50 p-3 text-xs">{JSON.stringify(value ?? null, null, 2)}</pre></div>)}</div></details>}
  </div></DocumentoModal>
}
