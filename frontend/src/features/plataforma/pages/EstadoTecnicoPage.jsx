import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { listarInstituciones, mensajeError, sesionTerminada } from '../api'
import { Aviso, EstadoInstitucion, EstadoTecnico } from '../componentes'
import { cifra, fecha, resumenTecnico, textoEspacio } from '../estado'
import { useSesionPlataforma } from '../sesion'
import { useUso } from '../useUso'

/** Una institución: mide su uso por separado, así un fallo o una demora no afecta a las demás. */
function Fila({ institucion }) {
  const uso = useUso(institucion.id)
  const { data } = uso
  const celda = 'px-3 py-3 align-top text-sm'
  const espacio = textoEspacio(data?.almacenamiento)
  return <tr className="border-t border-slate-100">
    <td className={celda}><Link to={`/plataforma/instituciones/${institucion.id}`} className="font-medium text-teal-700 underline">{institucion.nombre}</Link><div className="mt-1"><EstadoInstitucion estado={institucion.estado} /></div></td>
    {uso.loading && <td colSpan={5} className={`${celda} text-slate-500`} role="status">Midiendo…</td>}
    {uso.error && <td colSpan={5} className={celda}><Aviso>{uso.error}</Aviso></td>}
    {data && <>
      <td className={celda}>{cifra(data.personas?.total)}</td>
      <td className={celda}>{cifra(data.legajos?.total)}</td>
      <td className={celda} title={espacio.nota}>{espacio.texto}{!espacio.completo && data.almacenamiento && <p className="text-xs text-amber-800">{espacio.nota}</p>}</td>
      <td className={celda}>{data.ultimo_ingreso ? (data.ultimo_ingreso.cualquiera ? fecha(data.ultimo_ingreso.cualquiera) : 'Nunca') : '—'}</td>
      <td className={celda}><EstadoTecnico {...resumenTecnico(data)} /></td>
    </>}
  </tr>
}

/** Uso y estado técnico de todas las instituciones (solo cifras y estados). */
export default function EstadoTecnicoPage() {
  const { terminada } = useSesionPlataforma()
  const [estado, setEstado] = useState({ loading: true })
  useEffect(() => {
    document.title = 'Estado técnico · Plataforma'
    const controller = new AbortController()
    async function todas() {
      const lista = []
      for (let pagina = 1; ; pagina++) {
        const respuesta = await listarInstituciones({ page: pagina }, controller.signal)
        lista.push(...respuesta.data)
        if (pagina >= respuesta.last_page) return lista
      }
    }
    todas().then(data => setEstado({ data })).catch(failure => {
      if (controller.signal.aborted) return
      if (sesionTerminada(failure)) terminada()
      else setEstado({ error: mensajeError(failure) })
    })
    return () => controller.abort()
  }, [terminada])
  return <section aria-labelledby="titulo-tecnico" aria-busy={Boolean(estado.loading)}>
    <h1 id="titulo-tecnico" className="text-2xl font-bold text-slate-900">Estado técnico</h1>
    <p className="mt-1 text-sm text-slate-500">Uso de cada institución (personas, legajos, espacio y último inicio de sesión) y sus comprobaciones técnicas. Es independiente del estado de pago. Abre una institución para ver el detalle o actualizar la medición.</p>
    {estado.loading && <p role="status" className="mt-6 text-sm text-slate-500">Cargando instituciones…</p>}
    {estado.error && <div className="mt-6"><Aviso>{estado.error}</Aviso></div>}
    {estado.data && (estado.data.length === 0 ? <p className="mt-6 text-sm text-slate-500">Todavía no hay instituciones registradas.</p>
      : <div className="mt-6 overflow-x-auto rounded-2xl border border-slate-200 bg-white"><table className="w-full min-w-[720px] text-left">
        <thead><tr className="text-xs text-slate-500"><th className="px-3 py-3 font-medium">Institución</th><th className="px-3 py-3 font-medium">Personas</th><th className="px-3 py-3 font-medium">Legajos</th><th className="px-3 py-3 font-medium">Espacio</th><th className="px-3 py-3 font-medium">Último inicio de sesión</th><th className="px-3 py-3 font-medium">Estado técnico</th></tr></thead>
        <tbody>{estado.data.map(institucion => <Fila key={institucion.id} institucion={institucion} />)}</tbody>
      </table></div>)}
  </section>
}
