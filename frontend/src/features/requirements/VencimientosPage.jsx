import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { apiErrorMessage } from '../../axios'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'
import { usePuede } from '../auth/session'
import { destinoMotivo } from './destinosVencimiento'
import { aplicarDias, conFiltro, DIAS_POR_DEFECTO, opcionesEstado } from './filtrosVencimiento'

const panel = 'rounded-2xl border border-slate-200 bg-white'
const control = 'w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700'
const button = 'rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-teal-800 hover:bg-teal-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 disabled:opacity-50'
// Motivos y estados del backend (ConsultaVencimientos). Las opciones de motivo llegan filtradas por permisos.
const estados = {
  vencido: ['Vencido', 'bg-rose-100 text-rose-900'],
  fuera_de_plazo: ['Requerimiento fuera de plazo', 'bg-orange-100 text-orange-900'],
  por_vencer: ['Por vencer', 'bg-amber-100 text-amber-900'],
}
const fecha = value => value ? value.slice(0, 10).split('-').reverse().join('/') : 'Sin fecha'
const diasTexto = (dias, estado) => dias < 0 ? `${estado === 'fuera_de_plazo' ? 'Plazo vencido' : 'Venció'} hace ${-dias} día(s)` : dias === 0 ? 'Vence hoy' : `Vence en ${dias} día(s)`

function Campo({ label, children }) {
  return <label className="block text-sm font-medium text-slate-700"><span className="mb-2 block">{label}</span>{children}</label>
}

// Próximos días: se aplica al confirmar (Enter o salir del campo), no con cada dígito. Se reinicia al cambiar el valor de la
// dirección (key), p. ej. con «Limpiar»; un valor inválido vuelve al vigente.
function CampoDias({ params, setParams }) {
  const vigente = params.get('dias') || String(DIAS_POR_DEFECTO)
  const [dias, setDias] = useState(vigente)
  function confirmar() {
    const siguiente = aplicarDias(params, dias)
    if (siguiente) setParams(siguiente)
    else setDias(vigente)
  }
  return <Campo label="Próximos días (incluye hoy)"><input name="dias" required type="number" min={1} max={365} value={dias} onChange={e => setDias(e.target.value)} onBlur={confirmar}
    onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); confirmar() } }} className={control} /></Campo>
}

function Resultados() {
  const [params, setParams] = useSearchParams()
  const formKey = new URLSearchParams(params)
  formKey.delete('q')
  formKey.delete('page')
  const request = useLiveQuery(`/vencimientos?${params.toString()}`)
  const result = request.data
  const fuentes = Object.fromEntries((result?.origenes || []).map(origen => [origen.id, origen.nombre]))
  const gestionaRequerimientos = usePuede('requerimientos.gestionar')
  // Desplegables: se aplican al cambiar. Próximos días: al confirmar (CampoDias).
  const cambiar = clave => event => setParams(conFiltro(params, clave, event.target.value))
  function pagina(numero) {
    const next = new URLSearchParams(params)
    next.set('page', numero)
    setParams(next)
  }
  return <>
    <header className="mb-7"><p className="text-xs font-semibold uppercase tracking-widest text-teal-700">Seguimiento del personal</p><h1 className="mt-2 text-3xl font-bold text-slate-800">Vencimientos</h1><p className="mt-3 text-sm text-slate-600">Personas con documentos, contratos u otros datos con fecha que vencen o ya vencieron, y requerimientos próximos a su plazo o fuera de plazo.</p></header>
    <section className={`${panel} mb-6 p-5`} aria-label="Criterios del panel"><p className="text-sm leading-relaxed text-slate-700">Se revisan legajos activos. «Por vencer» incluye hoy y los próximos {result?.dias ?? params.get('dias') ?? 30} días. «Vencido»: la fecha ya pasó (por ejemplo, un contrato sin cese registrado o un DNI caducado). «Requerimiento fuera de plazo»: la persona no entregó lo pedido a tiempo.</p><p className="mt-2 text-xs text-slate-600">Rojo: vencido o menos de 7 días. Amarillo: desde 7 días hasta el límite elegido. Solo generan alertas los datos que vencen o se renuevan y tienen fecha registrada. Los datos sensibles, la sección 07 y los requerimientos se muestran según tus permisos.</p></section>
    {result && <section aria-label="Resumen según los filtros" className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">{[['Personal con alertas', result.resumen.personas, 'text-slate-800'], ['Con vencidos', result.resumen.vencidos, 'text-rose-800'], ['Requerimientos fuera de plazo', result.resumen.fuera_de_plazo, 'text-orange-900'], ['Solo por vencer', result.resumen.por_vencer, 'text-amber-900']].map(([label, count, color]) => <div key={label} className={`${panel} p-5`}><h2 className="text-sm text-slate-600">{label}</h2><p className={`mt-2 text-3xl font-bold ${color}`}>{count}</p></div>)}</section>}
    <section className={`${panel} mb-6 p-5`} aria-label="Filtros de vencimientos"><form key={formKey.toString()} onSubmit={event => event.preventDefault()} className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <Campo label="Nombre o documento de identidad"><input name="q" maxLength={120} value={params.get('q') || ''} onChange={e => setParams(conFiltro(params, 'q', e.target.value), { replace: true })} className={control} /></Campo>
      <CampoDias key={params.get('dias') || ''} params={params} setParams={setParams} />
      <Campo label="Estado del seguimiento"><select name="estado" value={params.get('estado') || ''} onChange={cambiar('estado')} className={control}><option value="">Todos los estados</option>{opcionesEstado(gestionaRequerimientos).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></Campo>
      <Campo label="Motivo de la alerta"><select name="origen" value={params.get('origen') || ''} onChange={cambiar('origen')} className={control}><option value="">Todos los motivos</option>{(result?.origenes || []).map(origen => <option key={origen.id} value={origen.id}>{origen.nombre}</option>)}</select></Campo>
      <Campo label="Tipo de personal"><select name="tipo_personal" value={params.get('tipo_personal') || ''} onChange={cambiar('tipo_personal')} className={control}><option value="">Todo el personal</option><option value="docente">Docente</option><option value="administrativo">Administrativo</option></select></Campo>
      <Campo label="Área / unidad orgánica"><select name="unidad_id" value={params.get('unidad_id') || ''} onChange={cambiar('unidad_id')} className={control}><option value="">Todas las áreas</option>{result?.areas.map(area => <option key={area.id} value={area.id}>{area.nombre}</option>)}</select></Campo>
      <div className="flex flex-wrap gap-3 sm:col-span-2 xl:col-span-3"><button type="button" onClick={() => setParams({})} className={button}>Limpiar</button></div>
    </form></section>
    {request.error && <div role="alert" className="mb-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-900">{apiErrorMessage(request.error)} <button onClick={request.retry} className={`${button} ml-3`}>Reintentar</button></div>}
    {request.loading && !result && <p role="status" className={`${panel} p-8`}>Consultando vencimientos…</p>}
    {result && <section aria-label="Personal con alertas de vencimiento" aria-busy={request.loading} className={`${panel} overflow-hidden`}><p role="status" className="border-b border-slate-200 p-5 text-sm text-slate-700">{result.total} persona(s) según filtros · Corte en Lima: {fecha(result.hoy)} · Orden por la fecha más antigua</p>
      {result.data.length ? <div className="overflow-x-auto"><table className="w-full text-left text-sm"><caption className="sr-only">Personal con alertas, una fila por persona y ordenado por fecha ascendente</caption><thead className="bg-slate-50 text-slate-700"><tr>{['Personal', 'Área', 'Estado', 'Motivos y fechas'].map(label => <th key={label} scope="col" className="px-5 py-4 font-semibold">{label}</th>)}</tr></thead><tbody>{result.data.map(persona => <tr key={persona.usuario_id} className="border-t border-slate-200 align-top"><th scope="row" className="px-5 py-5 font-semibold text-slate-800">{persona.nombre}<span className="mt-1 block text-xs font-normal text-slate-600">{persona.numero_doc_identidad} · {persona.tipo_personal}</span></th><td className="px-5 py-5 text-slate-700">{persona.area || 'Sin área registrada'}</td><td className="px-5 py-5"><span className={`inline-block rounded-xl px-3 py-2 text-xs font-semibold ${estados[persona.estado]?.[1] || estados.por_vencer[1]}`}>{estados[persona.estado]?.[0] || persona.estado}</span><p className="mt-2 text-xs text-slate-600">{persona.total_alertas} alerta(s)</p></td><td className="min-w-72 px-5 py-5"><ul className="space-y-3">{persona.motivos.map((motivo, index) => { const destino = destinoMotivo(persona, motivo); return <li key={motivo.id || index}><p className="font-medium text-slate-800">{fuentes[motivo.origen] || motivo.origen}{motivo.detalle ? `: ${motivo.detalle}` : ''}</p><p className={`mt-1 text-xs font-medium ${motivo.criticidad === 'rojo' ? 'text-rose-800' : 'text-amber-900'}`}><time dateTime={motivo.fecha}>{fecha(motivo.fecha)}</time> · {diasTexto(motivo.dias_restantes, motivo.estado)}</p>{destino && <Link to={destino.ruta} aria-label={`${destino.texto} de ${persona.nombre}`} className="mt-1 inline-block text-xs font-semibold text-teal-800 underline">{destino.texto}</Link>}</li> })}</ul>{persona.total_alertas > persona.motivos.length && <p className="mt-3 text-xs text-slate-600">Se muestran los primeros {persona.motivos.length} motivos de {persona.total_alertas}. Para el resto, <Link to={`/legajos/${persona.usuario_id}`} className="font-semibold text-teal-800 underline">abre la ficha completa</Link>.</p>}</td></tr>)}</tbody></table></div> : <p className="p-10 text-center text-sm text-slate-600">No hay personas con alertas para estos filtros. Los datos sin fecha registrada (por ejemplo, un contrato sin fecha de fin o un certificado permanente) no generan alertas.</p>}
      {result.last_page > 1 && <nav aria-label="Paginación de vencimientos" className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 p-5"><span className="text-sm text-slate-700">Página {result.current_page} de {result.last_page}</span><div className="flex gap-3"><button className={button} disabled={result.current_page <= 1} onClick={() => pagina(result.current_page - 1)}>Anterior</button><button className={button} disabled={result.current_page >= result.last_page} onClick={() => pagina(result.current_page + 1)}>Siguiente</button></div></nav>}
    </section>}
  </>
}

export default function VencimientosPage() {
  const canView = usePuede('legajo.ver')
  return canView ? <Resultados /> : <p role="alert">No tienes permiso para consultar vencimientos de legajos.</p>
}
