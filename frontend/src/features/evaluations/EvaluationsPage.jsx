import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'

const panel = 'rounded-2xl border border-slate-200 bg-white'
const control = 'rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:ring-2 focus:ring-teal-600 disabled:opacity-50'

function dateText(value) {
  if (!value) return 'No registrada'
  const [year, month, day] = value.slice(0, 10).split('-')
  return `${day}/${month}/${year}`
}

function personName(person) {
  if (!person) return 'Persona no disponible'
  return [person.nombres, person.apellido_paterno, person.apellido_materno].filter(Boolean).join(' ') || 'Persona no disponible'
}

export default function EvaluationsPage() {
  const [searchParams, setSearchParams] = useSearchParams()
  const query = searchParams.get('q') || ''
  const [searchState, setSearchState] = useState({ query, value: query })
  const search = searchState.query === query ? searchState.value : query
  const request = useLiveQuery(`/evaluaciones-desempeno?${searchParams.toString()}`)
  const result = request.data

  function updateFilter(key, value) {
    const next = new URLSearchParams(searchParams)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    setSearchParams(next, { replace: true })
  }

  function submitSearch(event) {
    event.preventDefault()
    updateFilter('q', search.trim())
  }

  function clearFilters() {
    setSearchState({ query: '', value: '' })
    setSearchParams({}, { replace: true })
  }

  const hasFilters = Boolean(query || searchParams.has('ciclo_anio'))

  return <>
    <div className="mb-7">
      <p className="mb-2 text-[10px] font-semibold uppercase tracking-[.2em] text-teal-700">Trayectoria del personal</p>
      <h1 className="text-3xl font-bold text-slate-800">Evaluaciones</h1>
      <p className="mt-3 text-sm text-slate-500">Consulta los registros por persona y ciclo, con sus referencias documentales.</p>
    </div>

    <section className={`${panel} mb-6 p-5`} aria-label="Buscar y filtrar evaluaciones">
      <form onSubmit={submitSearch} className="flex flex-wrap gap-3">
        <label className="flex min-w-60 flex-1 items-center gap-2 rounded-xl border border-slate-200 px-3">
          <span className="sr-only">Buscar por persona</span>
          <input className="w-full py-3 text-sm outline-none" aria-label="Buscar por persona" placeholder="Nombre de la persona" value={search} onChange={event => setSearchState({ query, value: event.target.value })} />
        </label>
        <select aria-label="Filtrar por ciclo" className={control} value={searchParams.get('ciclo_anio') || ''} onChange={event => updateFilter('ciclo_anio', event.target.value)}>
          <option value="">Todos los ciclos</option>
          {(result?.filters?.ciclos || []).map(cycle => <option key={cycle} value={cycle}>{cycle}</option>)}
        </select>
        <button className="rounded-xl bg-teal-700 px-5 py-3 text-sm font-medium text-white hover:bg-teal-800">Buscar</button>
        {hasFilters && <button type="button" className={control} onClick={clearFilters}>Limpiar filtros</button>}
      </form>
    </section>

    {request.error && <div role="alert" className="mb-5 rounded-xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-800">No se pudieron cargar las evaluaciones.<button onClick={request.retry} className="ml-3 font-semibold underline">Reintentar</button></div>}
    {request.loading && !result && <div role="status" className={`${panel} animate-pulse p-8 text-sm text-slate-500`}>Consultando evaluaciones…</div>}

    {result && <section className={`${panel} overflow-hidden`} aria-label="Registros de evaluación">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-5">
        <div><h2 className="text-sm font-semibold text-slate-800">Registros de evaluación</h2><p className="mt-1 text-xs text-slate-500">Se muestran las calificaciones tal como están registradas.</p></div>
        <span className="rounded-full bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-800">{result.meta?.total ?? result.data.length} registros</span>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="bg-slate-50 text-xs text-slate-500"><tr>{['Persona', 'Ciclo', 'Fecha', 'Calificación', 'Puntaje', 'Plan de mejora', 'Documento', 'Legajo'].map(title => <th key={title} className="px-5 py-4 font-medium">{title}</th>)}</tr></thead>
          <tbody>{result.data.map(record => <tr key={record.id} className="border-t border-slate-100 align-top">
            <td className="px-5 py-4"><p className="font-medium text-slate-800">{personName(record.usuario)}</p><p className="mt-1 text-xs text-slate-500">Evaluador: {record.evaluador ? personName(record.evaluador) : 'No registrado'}</p>{record.anulado && <span className="mt-2 inline-block rounded-full bg-rose-50 px-2.5 py-1 text-xs text-rose-700">Anulada</span>}</td>
            <td className="whitespace-nowrap px-5 py-4">{record.ciclo_anio}</td>
            <td className="whitespace-nowrap px-5 py-4">{dateText(record.fecha_evaluacion)}</td>
            <td className="px-5 py-4">{record.calificacion || 'No registrada'}</td>
            <td className="whitespace-nowrap px-5 py-4">{record.puntaje ?? 'No registrado'}</td>
            <td className="px-5 py-4">{record.plan_mejora ? 'Sí' : 'No'}</td>
            <td className="px-5 py-4">{record.documento ? [record.documento.num_resolucion, record.documento.estado].filter(Boolean).join(' · ') || 'Asociado' : 'Sin documento asociado'}</td>
            <td className="px-5 py-4">{record.usuario_id ? <Link className="whitespace-nowrap font-semibold text-teal-700 hover:underline" to={`/legajos/${record.usuario_id}`}>Abrir ficha →</Link> : 'Sin persona vinculada'}</td>
          </tr>)}</tbody>
        </table>
      </div>
      {!result.data.length && <p className="p-10 text-center text-sm text-slate-500">{hasFilters ? 'No se encontraron evaluaciones para estos filtros.' : 'No hay evaluaciones registradas.'}</p>}
      {result.meta?.last_page > 1 && <nav aria-label="Paginación de evaluaciones" className="flex items-center justify-between gap-3 border-t border-slate-100 px-6 py-4 text-xs text-slate-500"><span>Página {result.meta.current_page} de {result.meta.last_page}</span><div className="flex gap-2"><button className={control} disabled={!result.links?.prev} onClick={() => updateFilter('page', result.meta.current_page - 1)}>Anterior</button><button className={control} disabled={!result.links?.next} onClick={() => updateFilter('page', result.meta.current_page + 1)}>Siguiente</button></div></nav>}
    </section>}
  </>
}
