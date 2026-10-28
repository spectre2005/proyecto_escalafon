import { useEffect } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useLiveQuery } from '../../../shared/queries/useLiveQuery'
import Paginacion from '../../../shared/Paginacion'
import { conPagina, conPorPagina, leerPaginacion } from '../../../shared/paginacionUrl'

const fecha = value => value ? value.split('-').reverse().join('/') : 'No registrada'

export default function LegajosPasivosPage() {
  const [params, setParams] = useSearchParams()
  const { pagina, porPagina } = leerPaginacion(params)
  const query = conPagina(conPorPagina(new URLSearchParams(), porPagina), pagina)
  if (params.get('q')) query.set('q', params.get('q'))
  const request = useLiveQuery(`/expedientes/legajos-pasivos?${query}`)
  const data = request.data
  useEffect(() => {
    if (data?.total > 0 && data.current_page > data.last_page) setParams(conPagina(params, data.last_page), { replace: true })
  }, [data, params, setParams])
  return <>
    <Link to="/" className="text-xs font-semibold text-teal-700 hover:underline">← Resumen institucional</Link>
    <h1 className="mt-4 text-3xl font-bold text-slate-800">Legajos pasivos</h1>
    <p className="mt-3 text-sm text-slate-500">Legajos cerrados conservados como historial. Un reingreso abre un nuevo legajo para la misma persona.</p>
    <form className="my-6 flex gap-3" onSubmit={event => event.preventDefault()}>
      <input aria-label="Buscar legajos pasivos" maxLength={120} placeholder="Nombre, DNI o número de legajo" value={params.get('q') || ''} onChange={event => {
        const siguiente = conPagina(params, 1)
        if (event.target.value) siguiente.set('q', event.target.value)
        else siguiente.delete('q')
        setParams(siguiente, { replace: true })
      }} className="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm focus-visible:outline-teal-700" />
    </form>
    {request.error && <p role="alert" className="mb-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-800">No se pudo consultar el archivo pasivo. <button type="button" onClick={request.retry} className="underline">Reintentar</button></p>}
    {!data && request.loading && <p role="status">Consultando legajos…</p>}
    {data && <section aria-label="Archivo pasivo" aria-busy={request.loading} className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
      <div className="overflow-x-auto"><table className="w-full text-left text-sm">
        <thead className="bg-slate-50 text-xs text-slate-500"><tr>{['Persona', 'Legajo', 'Apertura', 'Pase a pasivo', 'Acciones'].map(label => <th key={label} className="px-5 py-4">{label}</th>)}</tr></thead>
        <tbody>{data.data.map(legajo => <tr key={legajo.id} className="border-t border-slate-100">
          <td className="px-5 py-4"><p className="font-semibold text-slate-800">{[legajo.persona?.nombres, legajo.persona?.apellido_paterno, legajo.persona?.apellido_materno].filter(Boolean).join(' ')}</p><p className="mt-1 text-xs text-slate-500">{legajo.persona?.numero_doc_identidad}</p></td>
          <td className="px-5 py-4"><p>{legajo.numero_legajo}</p><span className="text-xs text-slate-500">Pasivo · Solo consulta</span></td>
          <td className="px-5 py-4">{fecha(legajo.fecha_apertura)}</td><td className="px-5 py-4">{fecha(legajo.fecha_pasivo)}</td>
          <td className="px-5 py-4"><div className="flex flex-col items-start gap-2"><Link to={`/legajos/${legajo.usuario_id}?categoria=documentos&legajo_id=${legajo.id}`} className="text-xs font-semibold text-teal-700 hover:underline">Consultar documentos</Link><Link to={`/legajos/${legajo.usuario_id}`} className="text-xs font-semibold text-teal-700 hover:underline">Ver ficha / reingreso</Link></div></td>
        </tr>)}</tbody>
      </table></div>
      {!data.data.length && <p className="p-6 text-sm text-slate-500">No hay legajos pasivos para esta búsqueda.</p>}
      {data.total > 0 && <Paginacion etiqueta="Paginación de legajos pasivos" sustantivo="legajos" desde={data.from || 0} hasta={data.to || 0} total={data.total} pagina={data.current_page} paginas={data.last_page} porPagina={porPagina} ocupado={request.loading}
        onPagina={numero => setParams(conPagina(params, numero))} onPorPagina={numero => setParams(conPorPagina(params, numero))} />}
    </section>}
  </>
}
