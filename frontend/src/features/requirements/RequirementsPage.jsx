import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import Icon from '../../components/ui/Icon'
import { usePuede } from '../auth/session'
import { NewRequirement, RequirementManagement } from './RequirementManagement'
import { useManualQuery } from '../../shared/queries/useManualQuery'
import { QueryStatus, WorkflowField, WorkflowPagination } from '../../shared/WorkflowControls'
import { formatDate } from '../../shared/dates'
import { personName, stateLabels, isOverdue, effectiveDeadline } from './requirementUtils'
import { panel, primary, button, RequirementState, EmptyRequirements } from './RequirementUI'

function Results() {
  const [searchParams, setSearchParams] = useSearchParams()
  const query = searchParams.get('q') || ''
  const [searchState, setSearchState] = useState({ query, value: query })
  const search = searchState.query === query ? searchState.value : query
  const [selected, setSelected] = useState('')
  const request = useManualQuery(`/requerimientos?${searchParams.toString()}`)
  const result = request.data
  const estado = searchParams.get('atrasados') === '1' ? 'atrasados' : searchParams.get('estado') || ''
  const states = [...new Set(['abierto', 'en_revision', 'observado', 'cumplido', 'cancelado', searchParams.get('estado'), ...(result?.filters?.estados || [])])].filter(Boolean)
  const origins = [...new Set(['legajo', 'fiscalizacion', searchParams.get('origen'), ...(result?.filters?.origenes || [])])].filter(Boolean).sort()
  function updateFilter(key, value) {
    const next = new URLSearchParams(searchParams)
    if (key === 'estado') {
      next.delete('atrasados'); next.delete('estado')
      if (value === 'atrasados') next.set('atrasados', '1')
      else if (value) next.set('estado', value)
    } else if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    setSearchParams(next, { replace: true })
  }
  function created(id) { setSearchParams({ estado: 'abierto' }, { replace: true }); setSearchState({ query: '', value: '' }); setSelected(id); request.retry() }
  function limpiar() { setSearchState({ query: '', value: '' }); setSearchParams({}, { replace: true }); request.retry() }
  return <div className="space-y-6">
    <header className="flex items-start gap-4"><span className="hidden size-12 shrink-0 place-items-center rounded-2xl border border-teal-100 bg-teal-50 text-teal-700 sm:grid"><Icon name="clock" size={23} /></span><div><p className="text-[10px] font-semibold uppercase tracking-[.18em] text-teal-700">Seguimiento documental</p><h1 className="mt-1 text-2xl font-bold text-slate-900">Requerimientos</h1><p className="mt-2 text-sm text-slate-500">Solicita documentos al personal y acompaña cada respuesta hasta su revisión y cumplimiento.</p></div></header>
    <div className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 text-xs text-slate-500 sm:grid-cols-3">{[['1', 'Solicitar', 'Selecciona a la persona, el documento y su plazo.'], ['2', 'Presentar respuesta', 'Vincula un PDF ya cargado en el legajo.'], ['3', 'Revisar y cumplir', 'Acepta la respuesta u observa para subsanar.']].map(([number, title, description]) => <div key={number} className="flex items-start gap-3"><span className="grid size-7 shrink-0 place-items-center rounded-lg bg-slate-100 font-semibold text-slate-600">{number}</span><div><p className="font-semibold text-slate-800">{title}</p><p className="mt-1 leading-relaxed">{description}</p></div></div>)}</div>
    <NewRequirement onCreated={created} />
    <section className={`${panel} p-5 sm:p-6`} aria-label="Buscar y filtrar requerimientos"><div className="mb-4 flex flex-wrap items-center justify-between gap-3"><div><h2 className="font-semibold text-slate-900">Seguimiento de solicitudes</h2><p className="mt-1 text-xs text-slate-500">Busca por nombre, documento de identidad, tipo documental o descripción.</p></div><button type="button" className={button} disabled={request.loading} onClick={request.retry}><Icon name="clock" size={16} />Actualizar</button></div>
      <div className="mb-5 flex flex-wrap gap-2" aria-label="Vistas rápidas de requerimientos">{[['', 'Todos'], ['abierto', 'Abiertos'], ['en_revision', 'En revisión'], ['atrasados', 'Atrasados'], ['cumplido', 'Cumplidos']].map(([value, title]) => <button key={value} type="button" aria-pressed={estado === value} className={estado === value ? primary : button} onClick={() => updateFilter('estado', value)}>{title}</button>)}</div>
      <form onSubmit={event => { event.preventDefault(); updateFilter('q', search.trim()); request.retry() }} className="grid items-end gap-3 sm:grid-cols-2 xl:grid-cols-[minmax(0,2fr)_minmax(140px,1fr)_minmax(140px,1fr)_auto]"><label className="grid gap-2 text-sm text-slate-700">Buscar requerimiento<div className="flex items-center gap-2 rounded-xl border border-slate-300 px-3"><Icon name="search" size={17} className="text-slate-400" /><input className="min-w-0 flex-1 py-2.5 text-sm outline-none" maxLength={120} placeholder="Nombre, DNI o documento solicitado" value={search} onChange={event => setSearchState({ query, value: event.target.value })} /></div></label><WorkflowField label="Estado o plazo" required={false} value={estado} onChange={event => updateFilter('estado', event.target.value)}><option value="">Todos los estados</option>{states.map(state => <option key={state} value={state}>{stateLabels[state] || state}</option>)}<option value="atrasados">Atrasados · plazo efectivo vencido</option></WorkflowField><WorkflowField label="Origen" required={false} value={searchParams.get('origen') || ''} onChange={event => updateFilter('origen', event.target.value)}><option value="">Todos los orígenes</option>{origins.map(origin => <option key={origin} value={origin}>{origin === 'legajo' ? 'Legajo' : origin === 'fiscalizacion' ? 'Fiscalización' : origin}</option>)}</WorkflowField><div className="flex gap-2"><button className={primary}>Buscar</button><button type="button" className={button} onClick={limpiar}>Limpiar</button></div></form>
    </section>
    <QueryStatus request={request} />
    {result && <section className={`${panel} p-5 sm:p-6`} aria-label="Requerimientos de documentos"><div className="mb-4 flex flex-wrap items-center justify-between gap-3"><h2 className="text-sm font-semibold text-slate-800">Solicitudes registradas</h2><span role="status" className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">{result.meta?.total ?? result.data.length} resultado(s)</span></div>{!result.data.length ? <EmptyRequirements title="No hay requerimientos para esta consulta">Crea una solicitud documental o prueba con otra persona, estado u origen.</EmptyRequirements> : <ul className="space-y-4">{result.data.map(record => <li key={record.id} className="rounded-xl border border-slate-200 p-4 transition hover:border-teal-200"><div className="flex flex-wrap items-start justify-between gap-3"><div><p className="text-[10px] font-semibold uppercase tracking-wider text-slate-500">Destinatario · DNI {record.usuario?.numero_doc_identidad || 'Sin dato'}</p><h3 className="mt-1 font-semibold text-slate-900">{personName(record.usuario)}</h3></div><div className="flex flex-wrap gap-2"><RequirementState state={record.estado} />{isOverdue(record) && <span className="rounded-full bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-800 ring-1 ring-inset ring-rose-200">Plazo vencido</span>}</div></div><div className="mt-4"><p className="text-sm font-semibold text-slate-800">{record.tipo_documento?.nombre || 'Solicitud general · tipo documental no especificado'}</p><p className="mt-2 whitespace-pre-wrap break-words text-sm leading-relaxed text-slate-600">{record.descripcion}</p></div><dl className="mt-4 grid gap-4 rounded-xl bg-slate-50 p-4 text-xs sm:grid-cols-3"><div><dt className="text-slate-500">Solicitado el</dt><dd className="mt-1 font-medium text-slate-800">{formatDate(record.fecha_requerimiento)}</dd></div><div><dt className="text-slate-500">Fecha límite efectiva</dt><dd className={`mt-1 font-semibold ${isOverdue(record) ? 'text-rose-800' : 'text-slate-800'}`}>{formatDate(effectiveDeadline(record))}</dd>{record.prorroga_hasta && <p className="mt-1 text-slate-500">Prórroga · Original: {formatDate(record.fecha_limite)}</p>}</div><div><dt className="text-slate-500">Respuesta y origen</dt><dd className="mt-1 font-medium text-slate-800">{record.documento_id ? 'Documento vinculado' : 'Sin documento de respuesta'}</dd><p className="mt-1 text-slate-500">Origen: {record.origen || 'No registrado'}</p></div></dl><div className="mt-4 flex flex-wrap items-center justify-between gap-3"><Link className="inline-flex items-center gap-2 text-xs font-semibold text-teal-800 hover:underline" to={`/legajos/${record.usuario_id}`} target="_blank" rel="noopener noreferrer">Abrir ficha del legajo<Icon name="arrow" size={14} /></Link><button type="button" className={button} aria-haspopup="dialog" onClick={() => setSelected(record.id)}>Gestionar e historial<Icon name="arrow" size={16} /></button></div></li>)}</ul>}<WorkflowPagination data={result.meta} setPage={page => updateFilter('page', page)} loading={request.loading} /></section>}
    {selected && <RequirementManagement key={selected} id={selected} onClose={() => setSelected('')} onChanged={request.retry} />}
  </div>
}

export default function RequirementsPage() {
  const authorized = usePuede('sistema.administrar')
  return authorized ? <Results /> : <p role="alert">No tienes permiso para gestionar requerimientos.</p>
}
