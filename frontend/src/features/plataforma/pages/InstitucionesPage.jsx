import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import Icon from '../../../components/ui/Icon'
import { listarInstituciones, mensajeError, sesionTerminada } from '../api'
import { Aviso, EstadoInstitucion, EstadoRespaldo, EstadoServicio, SinAprovisionar } from '../componentes'
import { ESTADOS, fecha, siguienteFiltro } from '../estado'
import { boton } from '../estilos'
import { useSesionPlataforma } from '../sesion'

export default function InstitucionesPage() {
  const { terminada } = useSesionPlataforma()
  const [params, setParams] = useSearchParams()
  const q = params.get('q') || ''
  const estadoFiltro = params.get('estado') || ''
  const pagina = Number(params.get('page')) || 1
  const [texto, setTexto] = useState(q)
  const [estado, setEstado] = useState({ loading: true })
  const [intento, setIntento] = useState(0)
  useEffect(() => {
    document.title = 'Instituciones · Plataforma'
    const controller = new AbortController()
    listarInstituciones({ q: q || undefined, estado: estadoFiltro || undefined, page: pagina }, controller.signal).then(data => setEstado({ data })).catch(error => {
      if (controller.signal.aborted) return
      if (sesionTerminada(error)) terminada()
      else setEstado({ error: mensajeError(error) })
    })
    return () => controller.abort()
  }, [q, estadoFiltro, pagina, intento, terminada])
  function filtrar(cambio) {
    const { siguiente, igual } = siguienteFiltro(params, cambio)
    setEstado({ loading: true })
    if (igual) setIntento(valor => valor + 1)
    else setParams(siguiente)
  }
  const { data } = estado
  return <section aria-labelledby="titulo-instituciones" aria-busy={Boolean(estado.loading)}>
    <div className="flex flex-wrap items-end justify-between gap-4">
      <div><h1 id="titulo-instituciones" className="text-2xl font-bold text-slate-900">Instituciones</h1><p className="mt-1 text-sm text-slate-500">Registro, estado y datos básicos de cada institución cliente.</p></div>
      <Link to="/plataforma/instituciones/nueva" className={`inline-flex items-center gap-2 ${boton('principal', 'campo')}`}><Icon name="plus" size={16} />Registrar institución</Link>
    </div>
    <form role="search" onSubmit={event => { event.preventDefault(); filtrar({ q: texto.trim(), page: '' }) }} className="mt-6 flex flex-wrap gap-3">
      <label className="min-w-60 flex-1"><span className="sr-only">Buscar por nombre o identificador</span><input type="search" value={texto} onChange={event => setTexto(event.target.value)} placeholder="Buscar por nombre o identificador" maxLength={150} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm" /></label>
      <label><span className="sr-only">Estado</span><select value={estadoFiltro} onChange={event => filtrar({ estado: event.target.value, page: '' })} className="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm"><option value="">Todos los estados</option>{Object.entries(ESTADOS).map(([clave, { etiqueta }]) => <option key={clave} value={clave}>{etiqueta}</option>)}</select></label>
      <button type="submit" className={`inline-flex items-center gap-2 ${boton('secundario', 'campo')}`}><Icon name="search" size={16} />Buscar</button>
    </form>
    <div className="mt-5">
      {estado.loading && <p role="status" className="text-sm text-slate-500">Cargando…</p>}
      {estado.error && <div className="space-y-3"><Aviso>{estado.error}</Aviso><button type="button" onClick={() => { setEstado({ loading: true }); setIntento(intento + 1) }} className={boton('secundario')}>Reintentar</button></div>}
      {data && (data.data.length === 0 ? <p className="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">{q || estadoFiltro ? 'Ninguna institución coincide con la búsqueda.' : 'Todavía no hay instituciones registradas.'}</p> : <>
        <ul className="divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200 bg-white">
          {data.data.map(institucion => <li key={institucion.id}><Link to={`/plataforma/instituciones/${institucion.id}`} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4 hover:bg-slate-50">
            <span className="min-w-0"><strong className="block truncate text-sm text-slate-800">{institucion.nombre}</strong><span className="text-xs text-slate-500">{institucion.slug} · registrada {fecha(institucion.created_at)}</span></span>
            <span className="flex flex-wrap gap-2"><SinAprovisionar institucion={institucion} /><EstadoInstitucion estado={institucion.estado} /><EstadoServicio servicio={institucion.servicio} /><EstadoRespaldo institucion={institucion} /></span>
          </Link></li>)}
        </ul>
        {data.last_page > 1 && <nav aria-label="Paginación" className="mt-4 flex items-center justify-between text-sm"><button type="button" disabled={pagina <= 1} onClick={() => filtrar({ page: String(pagina - 1) })} className={boton('secundario')}>Anterior</button><span className="text-slate-500">Página {data.current_page} de {data.last_page} · {data.total} instituciones</span><button type="button" disabled={pagina >= data.last_page} onClick={() => filtrar({ page: String(pagina + 1) })} className={boton('secundario')}>Siguiente</button></nav>}
      </>)}
    </div>
  </section>
}
