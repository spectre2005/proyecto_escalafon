import { Link, useSearchParams } from 'react-router-dom'
import { apiErrorMessage } from '../../axios'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'
import { usePuede } from '../auth/session'

const control = 'w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700'
const button = 'rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-teal-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 hover:bg-teal-50 disabled:opacity-50'
const origenes = { ciclo: 'Ciclo del legajo', vinculo: 'Contrato / vínculo', documento: 'Documento' }
const fecha = value => value.slice(0, 10).split('-').reverse().join('/')

function Campo({ label, children }) {
  return <label className="block text-sm font-medium text-slate-700"><span className="mb-2 block">{label}</span>{children}</label>
}

export default function ProximosVencimientos() {
  const [params, setParams] = useSearchParams()
  const canView = usePuede('vencimientos.ver')
  const own = usePuede('renovacion.propia')
  const canReadLegajo = usePuede('legajo.ver')
  const canReadOwn = usePuede('legajo.propio')
  const canOpen = canReadLegajo || canReadOwn
  const consulta = new URLSearchParams(params)
  consulta.delete('vista')
  const request = useLiveQuery(canView || own ? `/vencimientos/proximos?${consulta}` : null)
  const result = request.data
  function filtrar(event) {
    event.preventDefault()
    const filtros = new URLSearchParams()
    for (const [key, value] of new FormData(event.currentTarget)) {
      if (String(value).trim()) filtros.set(key, String(value).trim())
    }
    setParams(filtros)
  }
  function pagina(value) {
    const next = new URLSearchParams(params)
    next.set('page', value)
    setParams(next)
  }
  if (!canView && !own) return <p role="alert">No tienes permiso para consultar vencimientos.</p>
  return <>
    <header className="mb-6"><p className="text-xs font-semibold uppercase tracking-widest text-teal-700">Control de legajos</p><h1 className="mt-2 text-3xl font-bold text-slate-800">Vencimientos</h1><p className="mt-3 text-sm text-slate-600">Ciclos del legajo, contratos y documentos con fecha registrada. El rango incluye hoy y los próximos {result?.dias ?? params.get('dias') ?? 30} días, según la fecha de Lima.</p></header>
    <nav aria-label="Vistas de vencimientos" className="mb-6 flex flex-wrap gap-3"><span aria-current="page" className="rounded-xl bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white">Próximos vencimientos</span><Link to="/vencimientos?vista=renovaciones" className={button}>Gestión de renovación docente</Link></nav>
    <section aria-label="Filtros de vencimientos" className="mb-6 rounded-2xl border border-slate-200 bg-white p-5">
      <form key={params.toString()} onSubmit={filtrar} className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        <Campo label="Nombre, documento de identidad o legajo"><input name="q" maxLength={120} defaultValue={params.get('q') || ''} className={control} /></Campo>
        <Campo label="Próximos días (incluye hoy)"><input name="dias" type="number" min={1} max={365} required defaultValue={params.get('dias') || 30} className={control} /></Campo>
        <Campo label="Tipo de vencimiento"><select name="origen" defaultValue={params.get('origen') || ''} className={control}><option value="">Los tres tipos</option>{Object.entries(origenes).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></Campo>
        <Campo label="Tipo de personal"><select name="tipo_personal" defaultValue={params.get('tipo_personal') || ''} className={control}><option value="">Todo el personal</option><option value="docente">Docente</option><option value="administrativo">Administrativo</option></select></Campo>
        {canView && <Campo label="Área / unidad orgánica"><select name="unidad_id" defaultValue={params.get('unidad_id') || ''} className={control}><option value="">Todas las áreas</option>{result?.areas.map(area => <option key={area.id} value={area.id}>{area.nombre}</option>)}</select></Campo>}
        <div className="flex flex-wrap items-end gap-3"><button type="submit" className={button}>Aplicar filtros</button><button type="button" onClick={() => setParams({})} className={button}>Limpiar</button></div>
      </form>
      <p className="mt-4 text-sm text-slate-600">Rojo: vence hoy o en menos de 7 días. Amarillo: desde 7 días hasta el límite elegido. Orden: fecha ascendente.</p>
    </section>
    {request.error && <div role="alert" className="mb-5 rounded-xl bg-rose-50 p-4 text-rose-900">{apiErrorMessage(request.error)} <button onClick={request.retry} className={`${button} ml-3`}>Reintentar</button></div>}
    {request.loading && !result && <p role="status" className="rounded-2xl bg-white p-8">Cargando vencimientos…</p>}
    {result && <section aria-label="Resultados de vencimientos" aria-busy={request.loading} className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
      <p role="status" className="border-b border-slate-200 p-5 text-sm text-slate-700">{result.total} vencimientos · Corte: {fecha(result.hoy)}</p>
      {result.data.length ? <div className="overflow-x-auto"><table className="w-full text-left text-sm"><caption className="sr-only">Vencimientos próximos ordenados por fecha ascendente</caption><thead className="bg-slate-50 text-slate-700"><tr>{['Persona / legajo', 'Tipo / detalle', 'Área', 'Fecha', 'Criticidad', 'Acciones'].map(label => <th key={label} scope="col" className="px-5 py-4 font-semibold">{label}</th>)}</tr></thead><tbody>{result.data.map(row => <tr key={`${row.origen}:${row.id}:${row.legajo_id}`} className="border-t border-slate-200"><th scope="row" className="px-5 py-4 font-medium text-slate-800">{row.nombre}<span className="mt-1 block text-xs text-slate-600">{row.numero_legajo}</span></th><td className="px-5 py-4 text-slate-800">{origenes[row.origen]}<span className="mt-1 block text-xs text-slate-600">{row.detalle || 'Sin descripción'}</span></td><td className="px-5 py-4 text-slate-700">{row.area || 'Sin área registrada'}</td><td className="whitespace-nowrap px-5 py-4 text-slate-800"><time dateTime={row.fecha}>{fecha(row.fecha)}</time></td><td className="px-5 py-4"><span className={`inline-block rounded-xl px-3 py-2 font-medium ${row.criticidad === 'rojo' ? 'bg-rose-100 text-rose-900' : 'bg-amber-100 text-amber-900'}`}>{row.criticidad === 'rojo' ? 'Urgente' : 'Próximo'} · {row.dias_restantes === 0 ? 'Vence hoy' : `${row.dias_restantes} días`}</span></td><td className="px-5 py-4">{canOpen && <Link to={`/legajos/${row.usuario_id}`} aria-label={`Abrir legajo de ${row.nombre}`} className={button}>Abrir legajo</Link>}</td></tr>)}</tbody></table></div> : <p className="p-10 text-center text-slate-600">No hay vencimientos con fecha registrada dentro de este rango y filtros.</p>}
      {result.last_page > 1 && <nav aria-label="Paginación de vencimientos" className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-5"><span className="text-sm text-slate-700">Página {result.current_page} de {result.last_page}</span><div className="flex gap-3"><button onClick={() => pagina(result.current_page - 1)} disabled={result.current_page <= 1} className={button}>Anterior</button><button onClick={() => pagina(result.current_page + 1)} disabled={result.current_page >= result.last_page} className={button}>Siguiente</button></div></nav>}
    </section>}
  </>
}
