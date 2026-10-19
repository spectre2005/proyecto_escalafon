import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { mensajeError, resumen, sesionTerminada } from '../api'
import { Aviso } from '../componentes'
import { ESTADOS, etiquetaAccion, fecha } from '../estado'
import { useSesionPlataforma } from '../sesion'

export default function ResumenPage() {
  const { terminada } = useSesionPlataforma()
  const [estado, setEstado] = useState({ loading: true })
  const [intento, setIntento] = useState(0)
  useEffect(() => {
    document.title = 'Resumen · Plataforma'
    const controller = new AbortController()
    resumen(controller.signal).then(data => setEstado({ data })).catch(error => {
      if (controller.signal.aborted) return
      if (sesionTerminada(error)) terminada()
      else setEstado({ error: mensajeError(error) })
    })
    return () => controller.abort()
  }, [intento, terminada])
  const { data } = estado
  return <section aria-labelledby="titulo-resumen" aria-busy={Boolean(estado.loading)}>
    <h1 id="titulo-resumen" className="text-2xl font-bold text-slate-900">Resumen</h1>
    <p className="mt-1 text-sm text-slate-500">Instituciones registradas en la plataforma por estado y últimas operaciones.</p>
    {estado.loading && <p role="status" className="mt-6 text-sm text-slate-500">Cargando…</p>}
    {estado.error && <div className="mt-6 space-y-3"><Aviso>{estado.error}</Aviso><button type="button" onClick={() => { setEstado({ loading: true }); setIntento(intento + 1) }} className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm">Reintentar</button></div>}
    {data && <>
      <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Link to="/plataforma/instituciones" className="rounded-2xl border border-slate-200 bg-white p-5 hover:border-slate-300"><p className="text-xs text-slate-500">Total</p><p className="mt-2 text-3xl font-bold text-slate-900">{data.total}</p></Link>
        {Object.entries(ESTADOS).map(([clave, { etiqueta }]) => <Link key={clave} to={`/plataforma/instituciones?estado=${clave}`} className="rounded-2xl border border-slate-200 bg-white p-5 hover:border-slate-300"><p className="text-xs text-slate-500">{etiqueta}{clave === 'registrada' ? ' (sin operar)' : ''}</p><p className="mt-2 text-3xl font-bold text-slate-900">{data.por_estado?.[clave] ?? 0}</p></Link>)}
      </div>
      {data.importes_registrados && <div className="mt-6 rounded-2xl border border-slate-200 bg-white p-5"><h2 className="font-semibold">Importes registrados (PEN)</h2><p className="mt-2 text-sm">Mes: S/ {Number(data.importes_registrados.mes).toFixed(2)} · Año: S/ {Number(data.importes_registrados.anio).toFixed(2)}</p><p className="mt-2 text-xs text-slate-500">Por fecha de registro, excluyendo periodos anulados. No representa una conciliación bancaria.</p></div>}
      {data.servicios && <div className="mt-6 grid gap-4 lg:grid-cols-3">{[['proximos', 'Vencen en los próximos 30 días'], ['en_gracia', 'En periodo de gracia'], ['vencidos', 'Servicio vencido']].map(([key, title]) => <section key={key} className="rounded-2xl border border-slate-200 bg-white p-5"><h2 className="font-semibold">{title} ({data.servicios[key].length})</h2>{!data.servicios[key].length && <p className="mt-3 text-sm text-slate-500">Sin instituciones en este estado.</p>}<ul className="mt-3 space-y-3">{data.servicios[key].map(item => <li key={item.id}><Link className="text-sm text-teal-800 underline" to={`/plataforma/instituciones/${item.id}`}>{item.nombre}</Link><p className="mt-1 text-xs text-slate-500">Fin: {item.vence_el || 'Sin cobertura actual'}{item.gracia_hasta && ` · Gracia hasta ${item.gracia_hasta}`}{item.proximo_desde && ` · Próximo periodo: ${item.proximo_desde}`}</p></li>)}</ul></section>)}</div>}
      <div className="mt-8 rounded-2xl border border-slate-200 bg-white p-5">
        <h2 className="font-semibold text-slate-800">Últimas operaciones</h2>
        {data.recientes.length === 0 ? <p className="mt-3 text-sm text-slate-500">Todavía no hay operaciones registradas.</p> : <ul className="mt-3 divide-y divide-slate-100">
          {data.recientes.map(evento => <li key={evento.id} className="flex flex-wrap justify-between gap-2 py-3 text-sm"><span><strong className="text-slate-700">{etiquetaAccion(evento.accion)}</strong>{evento.institucion && <> · {evento.institucion_id ? <Link to={`/plataforma/instituciones/${evento.institucion_id}`} className="text-teal-700 underline">{evento.institucion}</Link> : evento.institucion}</>}{evento.motivo && <span className="text-slate-500"> — {evento.motivo}</span>}</span><span className="text-xs text-slate-500">{evento.administrador || 'Sistema'} · {fecha(evento.created_at)}</span></li>)}
        </ul>}
      </div>
    </>}
  </section>
}
