import { usePuede } from '../auth/session'
import { NewRequirement, RequirementManagement } from './RequirementManagement'
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
  return [person.nombres, person.apellido_paterno, person.apellido_materno].filter(Boolean).join(' ')
}

function Results() {
  const [searchParams, setSearchParams] = useSearchParams()
  const query = searchParams.get('q') || ''
  const [searchState, setSearchState] = useState({ query, value: query })
  const search = searchState.query === query ? searchState.value : query
  const request = useLiveQuery(`/requerimientos?${searchParams.toString()}`)
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

  return <>
    <div className="mb-7">
      <p className="mb-2 text-[10px] font-semibold uppercase tracking-[.2em] text-teal-700">Seguimiento documental</p>
      <h1 className="text-3xl font-bold text-slate-800">Requerimientos</h1>
      <p className="mt-3 text-sm text-slate-500">Revisa las solicitudes de documentos, sus plazos y la ficha de Legajo vinculada.</p>
    </div>

    <NewRequirement onCreated={request.retry} />
    <section className={`${panel} mb-6 p-5`} aria-label="Buscar y filtrar requerimientos">
      <form onSubmit={submitSearch} className="flex flex-wrap gap-3">
        <label className="flex min-w-60 flex-1 items-center gap-2 rounded-xl border border-slate-200 px-3">
          <span className="sr-only">Buscar por descripción o persona</span>
          <input className="w-full py-3 text-sm outline-none" aria-label="Buscar por descripción o persona" placeholder="Descripción o persona" value={search} onChange={event => setSearchState({ query, value: event.target.value })} />
        </label>
        <select aria-label="Filtrar por estado" className={control} value={searchParams.get('estado') || ''} onChange={event => updateFilter('estado', event.target.value)}>
          <option value="">Todos los estados</option>
          {(result?.filters?.estados || []).map(state => <option key={state} value={state}>{state}</option>)}
        </select>
        <select aria-label="Filtrar por origen" className={control} value={searchParams.get('origen') || ''} onChange={event => updateFilter('origen', event.target.value)}>
          <option value="">Todos los orígenes</option>
          {(result?.filters?.origenes || []).map(origin => <option key={origin} value={origin}>{origin}</option>)}
        </select>
        <button className="rounded-xl bg-teal-700 px-5 py-3 text-sm font-medium text-white hover:bg-teal-800">Buscar</button>
        {(query || searchParams.has('estado') || searchParams.has('origen')) && <button type="button" className={control} onClick={() => { setSearchState({ query: '', value: '' }); setSearchParams({}, { replace: true }) }}>Limpiar filtros</button>}
      </form>
    </section>

    {request.error && <div role="alert" className="mb-5 rounded-xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-800">No se pudieron cargar los requerimientos.<button onClick={request.retry} className="ml-3 font-semibold underline">Reintentar</button></div>}
    {request.loading && !result && <div role="status" className={`${panel} animate-pulse p-8 text-sm text-slate-500`}>Consultando requerimientos…</div>}

    {result && <section className={`${panel} overflow-hidden`} aria-label="Requerimientos de documentos">
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-5">
        <div><h2 className="text-sm font-semibold text-slate-800">Solicitudes registradas</h2><p className="mt-1 text-xs text-slate-500">Resultados para los filtros seleccionados.</p></div>
        <span className="rounded-full bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-800">{result.meta?.total ?? result.data.length} registros</span>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full text-left text-sm">
          <thead className="bg-slate-50 text-xs text-slate-500"><tr>{['Persona', 'Documento solicitado', 'Fecha de solicitud', 'Fecha límite', 'Estado', 'Legajo'].map(title => <th key={title} className="px-5 py-4 font-medium">{title}</th>)}</tr></thead>
          <tbody>{result.data.map(record => <tr key={record.id} className="border-t border-slate-100 align-top">
            <td className="px-5 py-4"><span className="font-medium text-slate-800">{personName(record.usuario)}</span></td>
            <td className="max-w-sm px-5 py-4"><p className="font-medium text-slate-800">{record.tipo_documento?.nombre || 'Tipo documental no especificado'}</p><p className="mt-1 whitespace-pre-wrap text-xs leading-relaxed text-slate-500">{record.descripcion}</p><details className="mt-2 text-xs"><summary className="cursor-pointer font-medium text-teal-700">Ver seguimiento</summary><dl className="mt-3 grid gap-2 text-slate-600 sm:grid-cols-2"><div><dt className="text-slate-400">Origen</dt><dd>{record.origen || 'No registrado'}</dd></div><div><dt className="text-slate-400">Plazo indicado</dt><dd>{record.plazo_dias_habiles === null ? 'Fecha límite explícita' : `${record.plazo_dias_habiles} días hábiles (dato histórico)`}</dd></div><div><dt className="text-slate-400">Prórroga hasta</dt><dd>{dateText(record.prorroga_hasta)}</dd></div><div><dt className="text-slate-400">Documento vinculado</dt><dd>{record.documento ? [record.documento.num_resolucion, record.documento.estado].filter(Boolean).join(' · ') || 'Asociado' : 'Aún no asociado'}</dd></div><div><dt className="text-slate-400">Cierre</dt><dd>{dateText(record.cerrado_en)}</dd></div></dl></details></td>
            <td className="whitespace-nowrap px-5 py-4 text-slate-600">{dateText(record.fecha_requerimiento)}</td>
            <td className="whitespace-nowrap px-5 py-4 text-slate-600">{dateText(record.prorroga_hasta || record.fecha_limite)}{record.prorroga_hasta && <p className="mt-1 text-xs text-slate-500">Original: {dateText(record.fecha_limite)}</p>}</td>
            <td className="px-5 py-4"><span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-700">{record.estado || 'No registrado'}</span><RequirementManagement record={record} onChanged={request.retry} /></td>
            <td className="px-5 py-4">{record.usuario_id ? <Link className="whitespace-nowrap text-xs font-semibold text-teal-700 hover:underline" to={`/legajos/${record.usuario_id}`}>Ver ficha →</Link> : '—'}</td>
          </tr>)}</tbody>
        </table>
      </div>
      {!result.data.length && <p className="p-10 text-center text-sm text-slate-500">{query || searchParams.has('estado') || searchParams.has('origen') ? 'No se encontraron requerimientos para estos filtros.' : 'No hay requerimientos registrados.'}</p>}
      {result.meta?.last_page > 1 && <nav aria-label="Paginación de requerimientos" className="flex items-center justify-between gap-3 border-t border-slate-100 px-6 py-4 text-xs text-slate-500"><span>Página {result.meta.current_page} de {result.meta.last_page}</span><div className="flex gap-2"><button className={control} disabled={!result.links?.prev} onClick={() => updateFilter('page', result.meta.current_page - 1)}>Anterior</button><button className={control} disabled={!result.links?.next} onClick={() => updateFilter('page', result.meta.current_page + 1)}>Siguiente</button></div></nav>}
    </section>}
  </>
}

export default function RequirementsPage() {
  const authorized = usePuede('sistema.administrar')
  return authorized ? <Results /> : <p role="alert">No tienes permiso para gestionar requerimientos.</p>
}
