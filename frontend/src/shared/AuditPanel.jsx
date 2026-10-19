import { useEffect, useState } from 'react'
import { apiErrorMessage } from '../axios'
import { todayLima } from './dates'
import { QueryStatus, WorkflowPagination } from './WorkflowControls'

const control = 'rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800'
export default function AuditPanel({ load, download, plataforma = false }) {
  const [source, setSource] = useState('/auditoria')
  const [draft, setDraft] = useState(() => ({ desde: todayLima(new Date(Date.now() - 30 * 86400000)), hasta: todayLima(), actor: '', accion: '', entidad: '', institucion: '' }))
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
  const campo = (name, label, type = 'text') => <label className="grid gap-1 text-sm">{label}<input type={type} name={name} value={draft[name]} maxLength={150} max={type === 'date' ? todayLima() : undefined} className={control} onChange={event => setDraft(current => ({ ...current, [name]: event.target.value }))} /></label>
  return <div className="space-y-5"><header><h1 className="text-2xl font-bold text-slate-900">{plataforma ? 'Auditoría de plataforma' : 'Auditoría institucional'}</h1><p className="mt-2 text-sm text-slate-600">Consulta operaciones y responsables; fechas en hora de Lima. El CSV incluye todos los resultados de los filtros aplicados.</p></header>{!plataforma && <label className="grid max-w-sm gap-1 text-sm">Registro<select className={control} value={source} onChange={event => { setSource(event.target.value); setPage(1) }}><option value="/auditoria">Operaciones y cambios</option><option value="/auditoria/accesos">Accesos y entregas de legajos</option></select></label>}
    <form className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" onSubmit={event => { event.preventDefault(); setFilters({ ...draft }); setPage(1) }}>{campo('desde', 'Desde', 'date')}{campo('hasta', 'Hasta', 'date')}{campo('actor', 'Actor: nombre o correo')}{campo('accion', 'Acción')}{plataforma ? campo('institucion', 'Institución: nombre o identificador') : source === '/auditoria' && campo('entidad', 'Entidad del registro')}<div className="flex items-end gap-3"><button className={control}>Aplicar filtros</button><button type="button" className={control} onClick={() => setAttempt(value => value + 1)}>Actualizar</button></div></form>
    <QueryStatus request={{ ...state, retry: () => setAttempt(value => value + 1) }} />{error && <p role="alert" className="text-sm text-rose-800">{error}</p>}{state.data && <><div className="flex flex-wrap items-center justify-between gap-3"><p role="status" className="text-sm">{state.data.total} registro(s)</p><button type="button" disabled={busy} onClick={exportar} className={control}>{busy ? 'Preparando CSV…' : 'Exportar resultados CSV'}</button></div><div className="space-y-3">{state.data.data.map(event => <article key={event.id} className="space-y-2 rounded-2xl border border-slate-200 bg-white p-4"><h2 className="font-semibold text-slate-900">{event.accion}</h2><p className="text-sm">{event.actor} · {event.fecha ? new Date(event.fecha).toLocaleString('es-PE', { timeZone: 'America/Lima' }) : 'Sin fecha'}</p><p className="break-all text-xs text-slate-600">{event.institucion || event.legajo || event.entidad || 'Plataforma'}{event.registro_id && ` · Registro ${event.registro_id}`}{event.autorizacion_id && ` · Autorización ${event.autorizacion_id}`}{event.ip && ` · IP ${event.ip}`}</p>{event.motivo && <p className="text-sm">{event.motivo}</p>}{(event.antes || event.despues) && <details><summary className="cursor-pointer text-sm font-semibold text-teal-800">Ver cambios registrados</summary><div className="mt-3 grid gap-3 lg:grid-cols-2"><div><h3 className="text-xs font-semibold">Antes</h3><pre className="max-h-72 overflow-auto whitespace-pre-wrap break-all rounded-xl bg-slate-50 p-3 text-xs">{JSON.stringify(event.antes, null, 2)}</pre></div><div><h3 className="text-xs font-semibold">Después</h3><pre className="max-h-72 overflow-auto whitespace-pre-wrap break-all rounded-xl bg-slate-50 p-3 text-xs">{JSON.stringify(event.despues, null, 2)}</pre></div></div></details>}</article>)}</div>{!state.data.data.length && <p className="rounded-2xl border bg-white p-6 text-sm">No hay registros para estos filtros.</p>}<WorkflowPagination data={state.data} setPage={setPage} loading={state.loading} /></>}
  </div>
}
