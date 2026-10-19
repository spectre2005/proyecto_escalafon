import { Link } from 'react-router-dom'
import { useState } from 'react'
import { apiErrorMessage } from '../axios'
import { useLiveQuery } from './queries/useLiveQuery'

const control = 'w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800'
const button = 'rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-teal-800 hover:bg-teal-50 disabled:opacity-50'

export function QueryStatus({ request }) {
  return <>{request.error && <p role="alert" className="my-3 text-sm text-rose-800">{apiErrorMessage(request.error)} <button type="button" className={button} onClick={request.retry}>Reintentar</button></p>}{request.loading && !request.data && <p role="status" className="my-3 text-sm">Cargando…</p>}</>
}

export function WorkflowPagination({ data, setPage, loading }) {
  if (!data || data.last_page <= 1) return null
  return <nav aria-label="Paginación de registros" className="my-4 flex flex-wrap items-center gap-3 text-sm"><button type="button" className={button} disabled={loading || data.current_page <= 1} onClick={() => setPage(data.current_page - 1)}>Anterior</button><span role="status">{data.current_page} / {data.last_page}</span><button type="button" className={button} disabled={loading || data.current_page >= data.last_page} onClick={() => setPage(data.current_page + 1)}>Siguiente</button></nav>
}

export function WorkflowField({ name, label, type = 'text', required = true, maxLength, defaultValue, children, ...rest }) {
  return <label className="grid gap-2 text-sm text-slate-700">{label}{children ? <select name={name} required={required} defaultValue={defaultValue} className={control} {...rest}>{children}</select> : type === 'textarea' ? <textarea name={name} required={required} maxLength={maxLength} defaultValue={defaultValue} className={control} rows={3} {...rest} /> : <input name={name} type={type} required={required} maxLength={maxLength} defaultValue={defaultValue} className={control} {...rest} />}</label>
}

export function LegajoPicker({ value, onChange }) {
  const [search, setSearch] = useState('')
  const [term, setTerm] = useState('')
  return <fieldset className="space-y-3 rounded-xl border border-slate-200 p-4"><legend className="px-2 text-sm font-semibold">Legajo</legend><div className="flex gap-2"><label className="flex-1"><span className="sr-only">Buscar legajo por persona, documento o número</span><input className={control} value={search} onChange={event => setSearch(event.target.value)} maxLength={120} placeholder="Persona, documento o número de legajo" /></label><button type="button" className={button} onClick={() => setTerm(search.trim())}>Buscar legajo</button></div>{value && <p className="text-sm text-teal-800">Seleccionado: {value.numero_legajo} · {[value.usuario?.nombres, value.usuario?.apellido_paterno, value.usuario?.apellido_materno].filter(Boolean).join(' ')}</p>}<LegajoOptions key={term} term={term} onChange={onChange} /></fieldset>
}

function LegajoOptions({ term, onChange }) {
  const [page, setPage] = useState(1)
  const request = useLiveQuery(`/operaciones/legajos?q=${encodeURIComponent(term)}&page=${page}`)
  return <div><QueryStatus request={request} />{request.data && <><ul className="max-h-48 space-y-2 overflow-auto">{request.data.data.map(legajo => <li key={legajo.id}><button type="button" className={button} onClick={() => onChange(legajo)}>{legajo.numero_legajo} · {[legajo.usuario?.nombres, legajo.usuario?.apellido_paterno].filter(Boolean).join(' ')} · {legajo.estado_legajo}</button></li>)}</ul>{!request.data.data.length && <p className="text-sm">No se encontraron legajos.</p>}<WorkflowPagination data={request.data} setPage={setPage} loading={request.loading} /></>}</div>
}

export function DocumentPicker({ legajo, value, onChange, label, multiple = false }) {
  const [page, setPage] = useState(1)
  const [names, setNames] = useState({})
  const request = useLiveQuery(`/operaciones/legajos/${legajo}/documentos?page=${page}`)
  function select(event) {
    const id = event.target.value
    const item = request.data?.data.find(doc => doc.id === id)
    if (!item) return
    setNames(current => ({ ...current, [id]: `${item.tipo_documento?.nombre || 'Documento'} · ${item.num_resolucion || 'Sin número'}` }))
    onChange(multiple ? [...new Set([...value, id])] : id)
  }
  return <fieldset className="space-y-2 rounded-xl border border-slate-200 p-3"><legend className="px-2 text-sm">{label}</legend><QueryStatus request={request} />{request.data && <>
    <select aria-label={label} className={control} value={multiple ? '' : value || ''} onChange={select}><option value="">Seleccionar documento</option>{!multiple && value && !request.data.data.some(doc => doc.id === value) && <option value={value}>{names[value] || 'Documento seleccionado'}</option>}{request.data.data.map(doc => <option key={doc.id} value={doc.id}>{doc.tipo_documento?.nombre} · {doc.num_resolucion || 'Sin número'} · {doc.estado}</option>)}</select>
    {multiple && value.length > 0 && <ul className="space-y-2 text-sm">{value.map(id => <li key={id}>{names[id] || 'Documento seleccionado'} <button type="button" className="font-semibold text-teal-800 underline" onClick={() => onChange(value.filter(item => item !== id))}>Quitar</button></li>)}</ul>}
    {!request.data.data.length && <p className="text-sm">No hay documentos disponibles. Puedes cargarlos desde la ficha del legajo.</p>}
    <WorkflowPagination data={request.data} setPage={setPage} loading={request.loading} />
  </>}</fieldset>
}

export function WorkflowHistory({ entity, id }) {
  const [open, setOpen] = useState(false)
  return <details className="mt-4" onToggle={event => setOpen(event.currentTarget.open)}><summary className="cursor-pointer text-sm font-semibold text-teal-800">Ver historial</summary>{open && <HistoryRecords entity={entity} id={id} />}</details>
}

function HistoryRecords({ entity, id }) {
  const [page, setPage] = useState(1)
  const request = useLiveQuery(`/operaciones/historial/${entity}/${id}?page=${page}`)
  return <div className="mt-3 text-sm"><QueryStatus request={request} />{request.data && <><ul className="space-y-3">{request.data.data.map(event => <li key={event.id} className="border-l-2 border-teal-200 pl-3"><p className="font-semibold">{event.accion} · {new Date(event.fecha).toLocaleString('es-PE', { timeZone: 'America/Lima' })}</p><p>{[event.actor?.nombres, event.actor?.apellido_paterno].filter(Boolean).join(' ') || 'Operación del sistema'}</p>{event.despues?.fedateo_sustento_documento_id && <p className="break-all">Sustento conservado: {event.despues.fedateo_sustento_documento_id}{event.usuario_titular_id && <Link className="ml-2 font-semibold text-teal-800 underline" to={`/legajos/${event.usuario_titular_id}`}>Abrir historial documental en la ficha</Link>}</p>}{event.despues?.motivo && <p>{event.despues.motivo}</p>}{event.despues?.motivo_resolucion && <p>{event.despues.motivo_resolucion}</p>}{event.despues?.estado && <p>{event.antes?.estado || 'Nuevo'} → {event.despues.estado}</p>}{(event.antes || event.despues) && <details className="mt-2"><summary className="cursor-pointer font-semibold text-teal-800">Ver valores registrados</summary><div className="mt-2 grid gap-2 lg:grid-cols-2"><pre className="max-h-60 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-slate-50 p-2 text-xs">{JSON.stringify(event.antes, null, 2)}</pre><pre className="max-h-60 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-slate-50 p-2 text-xs">{JSON.stringify(event.despues, null, 2)}</pre></div></details>}</li>)}</ul>{!request.data.data.length && <p>No hay operaciones de este flujo registradas todavía.</p>}<WorkflowPagination data={request.data} setPage={setPage} loading={request.loading} /></>}</div>
}
