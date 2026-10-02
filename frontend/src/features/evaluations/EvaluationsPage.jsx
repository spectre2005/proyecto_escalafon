import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'

const panel = 'rounded-2xl border border-slate-200 bg-white'
const control = 'rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm text-slate-700 disabled:opacity-50'

function dateText(value) {
  if (!value) return 'No registrada'
  const [year, month, day] = value.slice(0, 10).split('-')
  return `${day}/${month}/${year}`
}

export default function EvaluationsPage() {
  const [page, setPage] = useState(1)
  const request = useLiveQuery(`/evaluaciones-desempeno?page=${page}`)
  const result = request.data

  return <>
    <div className="mb-7"><p className="mb-2 text-[10px] font-semibold uppercase tracking-[.2em] text-teal-700">Trayectoria del personal</p><h1 className="text-3xl font-bold text-slate-800">Evaluaciones</h1><p className="mt-3 text-sm text-slate-500">Consulta los registros de evaluación vinculados a cada persona.</p></div>
    {request.error && <div role="alert" className="mb-5 rounded-xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-800">No se pudieron cargar las evaluaciones.<button onClick={request.retry} className="ml-3 font-semibold underline">Reintentar</button></div>}
    {request.loading && !result && <div role="status" className={`${panel} animate-pulse p-8 text-sm text-slate-500`}>Consultando evaluaciones…</div>}
    {result && <section className={`${panel} overflow-hidden`} aria-label="Registros de evaluación">
      <div className="border-b border-slate-100 px-6 py-5"><h2 className="text-sm font-semibold text-slate-800">Registros <span className="ml-2 rounded-full bg-teal-50 px-2 py-1 text-xs text-teal-700">{result.meta?.total ?? result.data.length}</span></h2></div>
      <div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-slate-50 text-xs text-slate-500"><tr>{['Ciclo', 'Fecha', 'Calificación', 'Puntaje', 'Plan de mejora', 'Legajo'].map(title => <th key={title} className="px-5 py-4 font-medium">{title}</th>)}</tr></thead><tbody>{result.data.map(record => <tr key={record.id} className="border-t border-slate-100"><td className="px-5 py-4">{record.ciclo_anio}</td><td className="px-5 py-4">{dateText(record.fecha_evaluacion)}</td><td className="px-5 py-4">{record.calificacion || 'No registrada'}</td><td className="px-5 py-4">{record.puntaje ?? 'No registrado'}</td><td className="px-5 py-4">{record.plan_mejora ? 'Sí' : 'No'}</td><td className="px-5 py-4">{record.usuario_id ? <Link className="font-semibold text-teal-700 hover:underline" to={`/legajos/${record.usuario_id}`}>Abrir ficha →</Link> : 'Sin persona vinculada'}</td></tr>)}</tbody></table></div>
      {!result.data.length && <p className="p-10 text-center text-sm text-slate-500">No hay evaluaciones registradas.</p>}
      {result.meta?.last_page > 1 && <nav aria-label="Paginación de evaluaciones" className="flex items-center justify-between gap-3 border-t border-slate-100 px-6 py-4 text-xs text-slate-500"><span>Página {result.meta.current_page} de {result.meta.last_page}</span><div className="flex gap-2"><button className={control} disabled={!result.links?.prev} onClick={() => setPage(value => value - 1)}>Anterior</button><button className={control} disabled={!result.links?.next} onClick={() => setPage(value => value + 1)}>Siguiente</button></div></nav>}
    </section>}
  </>
}
