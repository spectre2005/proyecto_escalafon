import { Link, useSearchParams } from 'react-router-dom'
import { useLiveQuery } from '../../../shared/queries/useLiveQuery'
import Seccion07Filtros from '../components/Seccion07Filtros'
import Seccion07Tabla from '../components/Seccion07Tabla'
import { findTipo, porPagina, tiposSeccion07, tiposVisibles } from '../config/seccion07'
import { useFuncionalidades } from '../../../shared/useFuncionalidades'

const panel = 'rounded-2xl border border-slate-200 bg-white'
const control = 'rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:ring-2 focus:ring-teal-600 disabled:opacity-40'
// Filtros que se envían a la API; 'tipo' va en la ruta.
const filtrosApi = ['q', 'anio', 'unidad_organica_id', 'plan_mejora', 'por_pagina', 'page']

function errorText(error) {
  const status = error.response?.status
  if (status === 401 || status === 419) return 'Tu sesión terminó. Vuelve a iniciar sesión.'
  if (status === 403) return 'No tienes permiso para consultar la sección 07.'
  if (status === 422) return 'Algún filtro no es válido. Revisa los filtros e intenta de nuevo.'
  if (!error.response) return 'No se pudo conectar con el servidor. Comprueba la conexión.'
  return 'El servidor no pudo responder la consulta. Intenta nuevamente.'
}

function Pagination({ data, perPage, onChange }) {
  return <nav aria-label="Paginación" className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-6 py-4 text-xs text-slate-500">
    <span>Página {data.current_page} de {data.last_page} · {data.total} registros</span>
    <div className="flex flex-wrap items-center gap-2">
      <select aria-label="Registros por página" className={control} value={perPage} onChange={event => onChange('por_pagina', event.target.value)}>{porPagina.map(size => <option key={size} value={size}>{size} por página</option>)}</select>
      <button className={control} disabled={data.current_page <= 1} onClick={() => onChange('page', data.current_page - 1)}>Anterior</button>
      <button className={control} disabled={data.current_page >= data.last_page} onClick={() => onChange('page', data.current_page + 1)}>Siguiente</button>
    </div>
  </nav>
}

export default function EvaluacionesPage() {
  const consulta = useLiveQuery('/funcionalidades')
  if (!consulta.data) return <p role="status" className={`${panel} p-6 text-sm text-slate-500`}>{consulta.error ? 'No se pudo consultar la disponibilidad del módulo.' : 'Consultando disponibilidad…'}</p>
  if (consulta.data.data.modulos_sistema?.evaluaciones !== true) return <div className={`${panel} space-y-3 p-6`}><h1 className="text-xl font-semibold text-slate-800">Módulo desactivado</h1><p className="text-sm text-slate-500">La consulta general de Evaluaciones está desactivada para esta institución. Los registros de la sección 07 continúan disponibles en el legajo según tus permisos.</p><Link to="/" className="inline-block text-sm font-semibold text-teal-700">Volver al inicio</Link></div>
  return <ConsultaEvaluaciones />
}

function ConsultaEvaluaciones() {
  const [params, setParams] = useSearchParams()
  // Solo las pestañas de funcionalidades activas en la configuración de la institución.
  const visibles = tiposVisibles(useFuncionalidades())
  const tipo = findTipo(params.get('tipo'), visibles.length ? visibles : tiposSeccion07)
  const query = new URLSearchParams()
  filtrosApi.forEach(key => params.get(key) && query.set(key, params.get(key)))
  if (tipo.key !== 'evaluaciones') query.delete('plan_mejora')
  const request = useLiveQuery(`/seccion07/${tipo.key}?${query}`)
  const opciones = useLiveQuery('/seccion07-opciones')
  const result = request.data

  function change(key, value) {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    setParams(next, { replace: key === 'q' })
  }

  function changeTipo(key) {
    // El año y el plan de mejora dependen del tipo; la búsqueda y la unidad se conservan.
    const next = new URLSearchParams(params)
    next.set('tipo', key)
    ;['anio', 'plan_mejora', 'page'].forEach(item => next.delete(item))
    setParams(next)
  }

  return <>
    <div className="mb-7"><p className="mb-2 text-[10px] font-semibold uppercase tracking-[.2em] text-teal-700">Sección 07 del legajo</p><h1 className="text-3xl font-bold text-slate-800">Evaluaciones</h1><p className="mt-3 max-w-2xl text-sm text-slate-500">Consulta de evaluación de desempeño, progresión en la carrera y desplazamientos del personal. Los registros se cargan desde el legajo de cada persona.</p></div>

    <div role="tablist" aria-label="Tipo de registro" className="mb-5 flex gap-2 overflow-x-auto pb-1">
      {visibles.map(item => <button key={item.key} role="tab" aria-selected={item.key === tipo.key} onClick={() => changeTipo(item.key)} className={`whitespace-nowrap rounded-xl px-4 py-2.5 text-sm font-medium ${item.key === tipo.key ? 'bg-teal-700 text-white' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50'}`}>{item.label}</button>)}
    </div>

    <Seccion07Filtros key={tipo.key} tipo={tipo} params={params} opciones={opciones.data?.data} onChange={change} />
    {opciones.error && <p role="status" className="-mt-3 mb-5 text-xs text-amber-700">No se pudieron cargar los años y las unidades para los filtros. La búsqueda por nombre o DNI sigue disponible.</p>}

    {request.error && <div role="alert" className="mb-5 rounded-xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-800">{errorText(request.error)}{result && ' Se muestran los últimos datos obtenidos.'}<button onClick={request.retry} className="ml-3 font-semibold underline">Reintentar</button></div>}
    {request.loading && !result && !request.error && <div role="status" className={`${panel} animate-pulse p-8 text-sm text-slate-500`}>Consultando {tipo.label.toLowerCase()}…</div>}

    {result && <section className={`${panel} overflow-hidden`} aria-label={tipo.label}>
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-6 py-5">
        <h2 className="text-sm font-semibold text-slate-800">{tipo.label} <span className="ml-2 rounded-full bg-teal-50 px-2 py-1 text-xs text-teal-700">{result.total}</span></h2>
        {tipo.key === 'evaluaciones' && opciones.data?.data && !opciones.data.data.ve_calificacion && <p className="text-xs text-slate-500">{opciones.data.data.puntaje_activo === false ? 'Calificación reservada' : 'Calificación y puntaje reservados'} hasta que la institución defina quién puede verlos.</p>}
      </div>
      {result.data.length ? <Seccion07Tabla tipo={tipo} registros={result.data} puntajeActivo={opciones.data?.data?.puntaje_activo} /> : <p className="p-10 text-center text-sm text-slate-500">{tipo.vacio}</p>}
      {result.total > 0 && <Pagination data={result} perPage={result.per_page} onChange={change} />}
    </section>}
  </>
}
