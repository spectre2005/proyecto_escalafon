import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { mensajeError, resumen, sesionTerminada } from '../api'
import { Aviso } from '../componentes'
import { COPIA_EXTERNA, ESTADOS, etiquetaAccion, etiquetaEstado, fecha, fechaCorta, importe, textoVencimiento } from '../estado'
import { boton } from '../estilos'
import { useSesionPlataforma } from '../sesion'

/** Ingresos registrados (pagos no anulados, por fecha de registro en hora de Lima). Los pagos sin monto no se suman. */
function Ingresos({ ingresos }) {
  if (!ingresos) return null
  const tarjeta = (titulo, datos) => <div className="rounded-2xl border border-slate-200 bg-white p-5">
    <p className="text-xs text-slate-500">{titulo} <span className="text-slate-400">({fechaCorta(datos.desde)} al {fechaCorta(datos.hasta)})</span></p>
    <p className="mt-2 text-3xl font-bold text-slate-900">{importe(datos.total, ingresos.moneda)}</p>
    <p className="mt-1 text-xs text-slate-500">{datos.pagos === 0 ? 'Sin pagos registrados.' : `${datos.pagos} pago${datos.pagos === 1 ? '' : 's'} registrado${datos.pagos === 1 ? '' : 's'}`}{datos.sin_monto > 0 && <span className="text-amber-800"> · {datos.sin_monto} sin monto (no se suma{datos.sin_monto === 1 ? '' : 'n'})</span>}</p>
  </div>
  return <div className="mt-8">
    <h2 className="font-semibold text-slate-800">Ingresos registrados</h2>
    <div className="mt-3 grid gap-4 sm:grid-cols-2">{tarjeta('Este mes', ingresos.mes)}{tarjeta('Este año', ingresos.anio)}</div>
  </div>
}

const GRUPOS = [
  { clave: 'por_vencer', titulo: dias => `Vencen en los próximos ${dias} días`, vacio: 'Ninguna vence en este plazo.', borde: 'border-amber-200' },
  { clave: 'en_gracia', titulo: () => 'En días de gracia', vacio: 'Ninguna está en días de gracia.', borde: 'border-amber-200' },
  { clave: 'vencidas', titulo: () => 'Vencidas', vacio: 'Ninguna está vencida.', borde: 'border-rose-200' },
]

/** Tiempo de uso pagado: cada institución en un solo grupo (el backend usa su estado de servicio). */
function Vencimientos({ vencimientos }) {
  if (!vencimientos) return null
  return <div className="mt-8">
    <h2 className="font-semibold text-slate-800">Tiempo de uso pagado</h2>
    <div className="mt-3 grid gap-4 lg:grid-cols-3">
      {GRUPOS.map(({ clave, titulo, vacio, borde }) => {
        const lista = vencimientos[clave]
        return <div key={clave} className={`rounded-2xl border bg-white p-5 ${lista.length ? borde : 'border-slate-200'}`}>
          <h3 className="text-sm font-semibold text-slate-700">{titulo(vencimientos.dias)} <span className="text-slate-400">({lista.length})</span></h3>
          {lista.length === 0 ? <p className="mt-2 text-sm text-slate-500">{vacio}</p> : <ul className="mt-2 space-y-2 text-sm">
            {lista.map(item => <li key={item.id}><Link to={`/plataforma/instituciones/${item.id}`} className="font-medium text-teal-700 underline">{item.nombre}</Link>{item.estado !== 'activa' && <span className="text-xs text-slate-500"> · {etiquetaEstado(item.estado)}</span>}<p className="text-xs text-slate-600">{textoVencimiento(clave, item)}</p></li>)}
          </ul>}
        </div>
      })}
    </div>
    {vencimientos.sin_control > 0 && <p className="mt-2 text-xs text-slate-500">{vencimientos.sin_control === 1 ? 'Una institución no tiene' : `${vencimientos.sin_control} instituciones no tienen`} pagos registrados: su tiempo de uso no se controla.</p>}
  </div>
}

/** Instituciones sin un respaldo correcto reciente y último respaldo de la base de la plataforma. */
function Respaldos({ respaldos }) {
  if (!respaldos) return null
  const { atrasados, plataforma, horas_aviso: horas } = respaldos
  return <div className={`mt-8 rounded-2xl border p-5 ${atrasados.length ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-white'}`}>
    <h2 className="font-semibold text-slate-800">Respaldos</h2>
    {atrasados.length === 0 ? <p className="mt-2 text-sm text-slate-600">Todas las instituciones aprovisionadas tienen un respaldo correcto de las últimas {horas} horas.</p> : <>
      <p className="mt-2 text-sm text-rose-800">{atrasados.length === 1 ? 'Una institución no tiene' : `${atrasados.length} instituciones no tienen`} un respaldo correcto de las últimas {horas} horas:</p>
      <ul className="mt-2 space-y-1 text-sm">{atrasados.map(i => <li key={i.id}><Link to={`/plataforma/instituciones/${i.id}`} className="text-rose-800 underline">{i.nombre}</Link><span className="text-rose-700"> · {i.ultimo_correcto_en ? `último correcto ${fecha(i.ultimo_correcto_en)}` : 'sin ningún respaldo correcto'}</span></li>)}</ul>
    </>}
    <p className="mt-3 text-xs text-slate-500">Base de la plataforma: {plataforma ? <>{plataforma.estado === 'correcto' ? 'respaldo correcto' : 'el último respaldo falló'} {fecha(plataforma.created_at)} · <span className={COPIA_EXTERNA[plataforma.copia_externa]?.clase}>{COPIA_EXTERNA[plataforma.copia_externa]?.etiqueta}</span></> : 'todavía sin respaldos'}.</p>
  </div>
}

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
    <p className="mt-1 text-sm text-slate-500">Instituciones registradas en la plataforma por estado, ingresos, tiempo de uso pagado, respaldos y últimas operaciones.</p>
    {estado.loading && <p role="status" className="mt-6 text-sm text-slate-500">Cargando…</p>}
    {estado.error && <div className="mt-6 space-y-3"><Aviso>{estado.error}</Aviso><button type="button" onClick={() => { setEstado({ loading: true }); setIntento(intento + 1) }} className={boton('secundario')}>Reintentar</button></div>}
    {data && <>
      <div className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Link to="/plataforma/instituciones" className="rounded-2xl border border-slate-200 bg-white p-5 hover:border-slate-300"><p className="text-xs text-slate-500">Total</p><p className="mt-2 text-3xl font-bold text-slate-900">{data.total}</p></Link>
        {Object.entries(ESTADOS).map(([clave, { etiqueta }]) => <Link key={clave} to={`/plataforma/instituciones?estado=${clave}`} className="rounded-2xl border border-slate-200 bg-white p-5 hover:border-slate-300"><p className="text-xs text-slate-500">{etiqueta}{clave === 'registrada' ? ' (sin operar)' : ''}</p><p className="mt-2 text-3xl font-bold text-slate-900">{data.por_estado?.[clave] ?? 0}</p></Link>)}
      </div>
      <Ingresos ingresos={data.ingresos} />
      <Vencimientos vencimientos={data.vencimientos} />
      <Respaldos respaldos={data.respaldos} />
      <div className="mt-8 rounded-2xl border border-slate-200 bg-white p-5">
        <h2 className="font-semibold text-slate-800">Últimas operaciones</h2>
        {data.recientes.length === 0 ? <p className="mt-3 text-sm text-slate-500">Todavía no hay operaciones registradas.</p> : <ul className="mt-3 divide-y divide-slate-100">
          {data.recientes.map(evento => <li key={evento.id} className="flex flex-wrap justify-between gap-2 py-3 text-sm"><span><strong className="text-slate-700">{etiquetaAccion(evento.accion)}</strong>{evento.institucion && <> · {evento.institucion_id ? <Link to={`/plataforma/instituciones/${evento.institucion_id}`} className="text-teal-700 underline">{evento.institucion}</Link> : evento.institucion}</>}{evento.motivo && <span className="text-slate-500"> — {evento.motivo}</span>}</span><span className="text-xs text-slate-500">{evento.administrador || 'Sistema'} · {fecha(evento.created_at)}</span></li>)}
        </ul>}
      </div>
    </>}
  </section>
}
