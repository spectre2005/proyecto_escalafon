import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useSesion } from '../auth/session'
import { useManualQuery } from '../../shared/queries/useManualQuery'
import { boton, campo, ErrorConsulta, Estado, panel, secundario } from './componentes'
import { estados, fecha, hoy, persona } from './config'
import DetalleProceso from './DetalleProceso'
import NuevoProceso from './NuevoProceso'

export default function FiscalizacionPage() {
  const [params, setParams] = useSearchParams()
  const [nuevo, setNuevo] = useState(false)
  const [busqueda, setBusqueda] = useState(params.get('q') || '')
  const { permisos } = useSesion()
  const puedeVer = permisos.includes('fiscalizacion.ver')
  const filtros = new URLSearchParams(params); filtros.delete('proceso')
  const request = useManualQuery(puedeVer ? `/fiscalizacion/procesos?${filtros.toString()}` : null)
  function filtrar(key, value) {
    const next = new URLSearchParams(params)
    if (key !== 'page') next.delete('page')
    if (value) next.set(key, value); else next.delete(key)
    setParams(next)
  }
  if (!puedeVer) return <p role="alert" className={panel}>No tienes permiso para consultar fiscalizaciones.</p>
  return <div className="grid gap-6"><header><p className="text-[10px] font-semibold uppercase tracking-[.2em] text-teal-700">Control documental · Recursos Humanos</p><h1 className="mt-2 text-3xl font-bold text-slate-800">Fiscalización</h1><p className="mt-3 max-w-3xl text-sm leading-relaxed text-slate-500">Organiza la verificación de documentos por legajo, conserva las evidencias y somete las conclusiones a revisión antes del cierre.</p></header>
    {params.get('proceso') ? <DetalleProceso key={params.get('proceso')} id={params.get('proceso')} onBack={() => { filtrar('proceso', ''); request.retry() }} /> : <>
      <section className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Resumen de fiscalizaciones">{Object.entries(estados).map(([key, label]) => <button key={key} className={`${panel} text-left ${params.get('estado') === key ? 'ring-2 ring-teal-600' : ''}`} onClick={() => filtrar('estado', params.get('estado') === key ? '' : key)}><span className="text-xs text-slate-500">{label}</span><strong className="mt-2 block text-3xl text-slate-800">{request.data?.resumen?.[key] ?? '—'}</strong></button>)}</section>
      <section className={`${panel} grid gap-4 sm:grid-cols-4`}>{[['1. Definir alcance', 'Selecciona el legajo, documentos, motivo y plazo.'], ['2. Contrastar fuentes', 'Comprueba archivos y registra consultas a emisores.'], ['3. Evaluar evidencias', 'Propón una conclusión sustentada por documento.'], ['4. Revisar y cerrar', 'Cierra como responsable o envía el informe a otro revisor.']].map(([title, text]) => <div key={title}><h2 className="text-sm font-semibold text-teal-800">{title}</h2><p className="mt-2 text-xs leading-relaxed text-slate-500">{text}</p></div>)}</section>
      {nuevo && <NuevoProceso onCancel={() => setNuevo(false)} onSaved={p => { setNuevo(false); filtrar('proceso', p.id) }} />}
      <div className="flex flex-wrap items-center justify-between gap-3"><div className="flex flex-wrap gap-4 text-sm font-medium text-teal-700"><Link to="/legajos">Consultar legajos →</Link>{permisos.includes('requerimientos.gestionar') && <Link to="/requerimientos">Requerimientos →</Link>}</div>{permisos.includes('fiscalizacion.gestionar') && <button className={boton} onClick={() => setNuevo(true)}>Abrir proceso</button>}</div>
      <section className={panel}><form className="mb-5 flex flex-wrap gap-3" onSubmit={e => { e.preventDefault(); filtrar('q', busqueda.trim()) }}><input aria-label="Buscar por persona, legajo o código" className={`${campo} min-w-52 flex-1`} value={busqueda} onChange={e => setBusqueda(e.target.value)} placeholder="Persona, número de legajo o código" /><select aria-label="Estado del proceso" className={`${campo} sm:w-48`} value={params.get('estado') || ''} onChange={e => filtrar('estado', e.target.value)}><option value="">Todos los estados</option>{Object.entries(estados).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select><button className={boton}>Buscar</button><button type="button" className={secundario} onClick={request.retry}>Actualizar</button></form>
        <ErrorConsulta request={request} />{request.loading && <p role="status" className="py-8 text-center text-sm text-slate-500">Consultando procesos…</p>}
        {request.data && <><p className="mb-4 text-xs text-slate-500">{request.data.total} procesos encontrados para los filtros seleccionados.</p><div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-slate-50 text-xs text-slate-500"><tr>{['Código y servidor', 'Legajo', 'Responsable', 'Fecha límite', 'Estado', 'Seguimiento'].map(t => <th key={t} className="px-4 py-3 font-medium">{t}</th>)}</tr></thead><tbody>{request.data.data.map(p => <tr key={p.id} className="border-t border-slate-100"><td className="px-4 py-4"><p className="text-xs font-semibold text-teal-700">{p.codigo || 'Proceso anterior'}</p><p className="mt-1 font-medium text-slate-800">{persona(p.usuario)}</p></td><td className="px-4 py-4">{p.legajo?.numero_legajo}</td><td className="px-4 py-4">{persona(p.responsable)}</td><td className="whitespace-nowrap px-4 py-4">{fecha(p.fecha_limite)}{p.estado !== 'cerrado' && p.fecha_limite.slice(0, 10) < hoy() && <p className="mt-1 text-xs text-rose-700">Plazo vencido</p>}</td><td className="px-4 py-4"><Estado value={p.estado} /></td><td className="px-4 py-4"><button className="whitespace-nowrap font-semibold text-teal-700 hover:underline" onClick={() => filtrar('proceso', p.id)}>Ver proceso →</button></td></tr>)}</tbody></table></div>{!request.data.data.length && <p className="py-10 text-center text-sm text-slate-500">No hay procesos para estos filtros. Abre un proceso para comenzar.</p>}{request.data.last_page > 1 && <nav aria-label="Paginación de fiscalizaciones" className="mt-5 flex items-center justify-between gap-3"><button className={secundario} disabled={request.data.current_page <= 1} onClick={() => filtrar('page', request.data.current_page - 1)}>Anterior</button><span className="text-xs text-slate-500">Página {request.data.current_page} de {request.data.last_page}</span><button className={secundario} disabled={request.data.current_page >= request.data.last_page} onClick={() => filtrar('page', request.data.current_page + 1)}>Siguiente</button></nav>}</>}
      </section>
      <p className="text-xs leading-relaxed text-slate-500">La recepción, aceptación, fedateo y fiscalización son controles independientes. Los plazos y la selección se sustentan en la disposición aplicable a cada proceso.</p>
    </>}
  </div>
}
