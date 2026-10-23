import { useState } from 'react'
import { TareasPersona } from './RequirementsPage'
import { Link, useSearchParams } from 'react-router-dom'
import { apiErrorMessage } from '../../axios'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'
import { usePuede } from '../auth/session'

const panel = 'rounded-2xl border border-slate-200 bg-white'
const control = 'w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700'
const button = 'rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-teal-800 hover:bg-teal-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 disabled:opacity-50'
const fuentes = { documento: 'Documento', contrato: 'Contrato / vínculo', requerimiento: 'Requerimiento pendiente' }
const fecha = value => value ? value.slice(0, 10).split('-').reverse().join('/') : 'Sin fecha'
const diasTexto = dias => dias < 0 ? `Venció hace ${-dias} día(s)` : dias === 0 ? 'Vence hoy' : `Vence en ${dias} día(s)`

function Campo({ label, children }) {
  return <label className="block text-sm font-medium text-slate-700"><span className="mb-2 block">{label}</span>{children}</label>
}

function Resultados() {
  const [params, setParams] = useSearchParams()
  const [personaTareas, setPersonaTareas] = useState(null)
  const formKey = new URLSearchParams(params)
  formKey.delete('q')
  formKey.delete('page')
  const admin = usePuede('sistema.administrar')
  const gestionar = usePuede('requerimientos.gestionar')
  const request = useLiveQuery(`/vencimientos?${params.toString()}`)
  const result = request.data
  function filtrar(event) {
    event.preventDefault()
    const next = new URLSearchParams()
    for (const [key, value] of new FormData(event.currentTarget)) {
      if (String(value).trim()) next.set(key, String(value).trim())
    }
    setParams(next)
  }
  function pagina(numero) {
    const next = new URLSearchParams(params)
    next.set('page', numero)
    setParams(next)
  }
  return <>
    {personaTareas && <TareasPersona usuarioId={personaTareas} dias={Number(result?.dias || 30)} onClose={() => { setPersonaTareas(null); request.retry() }} />}
    <header className="mb-7"><p className="text-xs font-semibold uppercase tracking-widest text-teal-700">Seguimiento del personal</p><h1 className="mt-2 text-3xl font-bold text-slate-800">Vencimientos</h1><p className="mt-3 text-sm text-slate-600">Personas con documentos o contratos próximos a vencer, documentos vencidos o requerimientos documentales atrasados.</p></header>
    <section className={`${panel} mb-6 p-5`} aria-label="Criterios del panel"><p className="text-sm leading-relaxed text-slate-700">Se revisan legajos activos. «Por vencer» incluye hoy y los próximos {result?.dias ?? params.get('dias') ?? 30} días. «Desactualizado» indica documentos vencidos o requerimientos pendientes fuera de plazo; no significa que todo el legajo haya caducado.</p><p className="mt-2 text-xs text-slate-600">Rojo: vencido o menos de 7 días. Amarillo: desde 7 días hasta el límite elegido. Los registros sin fecha no generan alertas.{!admin && ' Los requerimientos administrativos y los documentos sensibles se muestran según tus permisos.'}</p></section>
    {result && <section aria-label="Resumen según los filtros" className="mb-6 grid gap-4 sm:grid-cols-3">{[['Personal con alertas', result.resumen.personas, 'text-slate-800'], ['Desactualizados', result.resumen.desactualizados, 'text-rose-800'], ['Por vencer', result.resumen.por_vencer, 'text-amber-900']].map(([label, count, color]) => <div key={label} className={`${panel} p-5`}><h2 className="text-sm text-slate-600">{label}</h2><p className={`mt-2 text-3xl font-bold ${color}`}>{count}</p></div>)}</section>}
    <section className={`${panel} mb-6 p-5`} aria-label="Filtros de vencimientos"><form key={formKey.toString()} onSubmit={filtrar} className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <Campo label="Nombre o documento de identidad"><input name="q" maxLength={120} value={params.get('q') || ''} onChange={e => {
        const next = new URLSearchParams(params)
        e.target.value ? next.set('q', e.target.value) : next.delete('q')
        next.delete('page')
        setParams(next, { replace: true })
      }} className={control} /></Campo>
      <Campo label="Próximos días (incluye hoy)"><input name="dias" required type="number" min={1} max={365} defaultValue={params.get('dias') || 30} className={control} /></Campo>
      <Campo label="Estado del seguimiento"><select name="estado" defaultValue={params.get('estado') || ''} className={control}><option value="">Todos los estados</option><option value="desactualizado">Desactualizado</option><option value="por_vencer">Por vencer</option></select></Campo>
      <Campo label="Motivo de la alerta"><select name="origen" defaultValue={params.get('origen') || ''} className={control}><option value="">Todos los motivos</option>{Object.entries(fuentes).filter(([key]) => admin || key !== 'requerimiento').map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></Campo>
      <Campo label="Tipo de personal"><select name="tipo_personal" defaultValue={params.get('tipo_personal') || ''} className={control}><option value="">Todo el personal</option><option value="docente">Docente</option><option value="administrativo">Administrativo</option></select></Campo>
      <Campo label="Área / unidad orgánica"><select key={result?.areas.length ?? 0} name="unidad_id" defaultValue={params.get('unidad_id') || ''} className={control}><option value="">Todas las áreas</option>{result?.areas.map(area => <option key={area.id} value={area.id}>{area.nombre}</option>)}</select></Campo>
      <div className="flex flex-wrap gap-3 sm:col-span-2 xl:col-span-3"><button type="submit" className={button}>Aplicar filtros</button><button type="button" onClick={() => setParams({})} className={button}>Limpiar</button></div>
    </form></section>
    {request.error && <div role="alert" className="mb-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-900">{apiErrorMessage(request.error)} <button onClick={request.retry} className={`${button} ml-3`}>Reintentar</button></div>}
    {request.loading && !result && <p role="status" className={`${panel} p-8`}>Consultando vencimientos…</p>}
    {result && <section aria-label="Personal con alertas de vencimiento" aria-busy={request.loading} className={`${panel} overflow-hidden`}><p role="status" className="border-b border-slate-200 p-5 text-sm text-slate-700">{result.total} persona(s) según filtros · Corte en Lima: {fecha(result.hoy)} · Orden por la fecha más antigua</p>
      {result.data.length ? <div className="overflow-x-auto"><table className="w-full text-left text-sm"><caption className="sr-only">Personal con alertas, una fila por persona y ordenado por fecha ascendente</caption><thead className="bg-slate-50 text-slate-700"><tr>{['Personal', 'Área', 'Estado', 'Motivos y fechas', 'Acción'].map(label => <th key={label} scope="col" className="px-5 py-4 font-semibold">{label}</th>)}</tr></thead><tbody>{result.data.map(persona => <tr key={persona.usuario_id} className="border-t border-slate-200 align-top"><th scope="row" className="px-5 py-5 font-semibold text-slate-800">{persona.nombre}<span className="mt-1 block text-xs font-normal text-slate-600">{persona.numero_doc_identidad} · {persona.tipo_personal}</span></th><td className="px-5 py-5 text-slate-700">{persona.area || 'Sin área registrada'}</td><td className="px-5 py-5"><span className={`inline-block rounded-xl px-3 py-2 text-xs font-semibold ${persona.estado === 'desactualizado' ? 'bg-rose-100 text-rose-900' : 'bg-amber-100 text-amber-900'}`}>{persona.estado === 'desactualizado' ? 'Desactualizado' : 'Por vencer'}</span><p className="mt-2 text-xs text-slate-600">{persona.total_alertas} alerta(s)</p></td><td className="min-w-72 px-5 py-5"><ul className="space-y-3">{persona.motivos.map((motivo, index) => <li key={index}><p className="font-medium text-slate-800">{fuentes[motivo.origen]}: {motivo.detalle}</p><p className={`mt-1 text-xs font-medium ${motivo.criticidad === 'rojo' ? 'text-rose-800' : 'text-amber-900'}`}><time dateTime={motivo.fecha}>{fecha(motivo.fecha)}</time> · {diasTexto(motivo.dias_restantes)}</p></li>)}</ul>{persona.total_alertas > persona.motivos.length && <p className="mt-3 text-xs text-slate-600">Se muestran los primeros {persona.motivos.length} motivos de {persona.total_alertas}. Revisa el legajo{admin ? ' y Requerimientos' : ''} para consultar el resto.</p>}</td><td className="px-5 py-5"><Link to={`/legajos/${persona.usuario_id}`} aria-label={`Revisar legajo de ${persona.nombre}`} className={`${button} inline-block`}>Ver legajo</Link>{gestionar && <button type="button" className={`${button} mt-3 block`} onClick={() => setPersonaTareas(persona.usuario_id)}>Requerimientos</button>}</td></tr>)}</tbody></table></div> : <p className="p-10 text-center text-sm text-slate-600">No hay personas con alertas para estos filtros. Los documentos sin caducidad y los contratos sin fecha de fin no aparecen como vencimientos.</p>}
      {result.last_page > 1 && <nav aria-label="Paginación de vencimientos" className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-5"><span className="text-sm text-slate-700">Página {result.current_page} de {result.last_page}</span><div className="flex gap-3"><button className={button} disabled={result.current_page <= 1} onClick={() => pagina(result.current_page - 1)}>Anterior</button><button className={button} disabled={result.current_page >= result.last_page} onClick={() => pagina(result.current_page + 1)}>Siguiente</button></div></nav>}
    </section>}
  </>
}

export default function VencimientosPage() {
  const canView = usePuede('legajo.ver')
  return canView ? <Resultados /> : <p role="alert">No tienes permiso para consultar vencimientos de legajos.</p>
}
