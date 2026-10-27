import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { apiErrorMessage } from '../../axios'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'
import { useManualQuery } from '../../shared/queries/useManualQuery'
import Paginacion from '../../shared/Paginacion'
import DocumentoModal from '../legajos/components/documentos/DocumentoModal'
import { conPagina, conPorPagina, leerPaginacion, paginaCorregida } from '../../shared/paginacionUrl'
import { usePuede } from '../auth/session'
import ExportarReporte from '../reportes/ExportarReporte'
import { destinoMotivo, destinoRegistroMotivo } from './destinosVencimiento'
import { aplicarDias, conFiltro, DIAS_POR_DEFECTO, opcionesEstado } from './filtrosVencimiento'

const panel = 'rounded-2xl border border-slate-200 bg-white'
const control = 'w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700'
const accion = 'inline-flex items-center justify-center rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-xs font-semibold text-teal-800 hover:bg-teal-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700'
const button = 'rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-teal-800 hover:bg-teal-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 disabled:opacity-50'
// Motivos y estados del backend (ConsultaVencimientos). Las opciones de motivo llegan filtradas por permisos.
const estados = {
  vencido: ['Vencido', 'bg-rose-100 text-rose-900'],
  fuera_de_plazo: ['Fuera de plazo', 'bg-orange-100 text-orange-900'],
  por_vencer: ['Por vencer', 'bg-amber-100 text-amber-900'],
}
const fecha = value => value ? value.slice(0, 10).split('-').reverse().join('/') : 'Sin fecha'
const diasTexto = (dias, estado) => dias < 0 ? `${estado === 'fuera_de_plazo' ? 'Plazo vencido' : 'Venció'} hace ${-dias} día${Math.abs(dias) === 1 ? '' : 's'}` : dias === 0 ? 'Vence hoy' : `Vence en ${dias} día${Math.abs(dias) === 1 ? '' : 's'}`

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
  return <Campo label="Próximos días"><input name="dias" required type="number" min={1} max={365} value={dias} onChange={e => setDias(e.target.value)} onBlur={confirmar}
    onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); confirmar() } }} className={control} /></Campo>
}

function Motivo({ persona, motivo, fuentes, compacto = false }) {
  const destino = motivo.origen === 'requerimiento' ? null : destinoMotivo(persona, motivo)
  if (compacto) return <div className="space-y-2">
    <div className="flex flex-wrap items-center justify-between gap-2">
      <span className="text-sm font-semibold text-slate-800">{fuentes[motivo.origen] || motivo.origen}</span>
      <span className={`rounded-full px-2 py-1 text-xs font-medium ${motivo.criticidad === 'rojo' ? 'bg-rose-50 text-rose-800' : 'bg-amber-50 text-amber-900'}`}>{diasTexto(motivo.dias_restantes, motivo.estado)}</span>
    </div>
    {motivo.detalle && <p className="break-words text-xs leading-relaxed text-slate-600">{motivo.detalle}</p>}
    <div className="flex items-center justify-between gap-3 text-xs">
      <time className="text-slate-500" dateTime={motivo.fecha}>{fecha(motivo.fecha)}</time>
      <Link to={destinoRegistroMotivo(persona, motivo)} aria-label={`Registrar ${fuentes[motivo.origen] || motivo.origen} de ${persona.nombre}${motivo.detalle ? `: ${motivo.detalle}` : ''}`} className={`${accion} ml-auto`}>Registrar →</Link>
    </div>
  </div>
  return <div className="space-y-1.5">
    <div className="flex flex-wrap items-center gap-2">
      <span className="font-semibold text-slate-800">{fuentes[motivo.origen] || motivo.origen}</span>
      <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${motivo.criticidad === 'rojo' ? 'bg-rose-50 text-rose-800' : 'bg-amber-50 text-amber-900'}`}>{diasTexto(motivo.dias_restantes, motivo.estado)}</span>
    </div>
    {motivo.detalle && <p className="break-words text-xs text-slate-600">{motivo.detalle}</p>}
    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
      <time className="text-slate-500" dateTime={motivo.fecha}>{fecha(motivo.fecha)}</time>
      {destino && <Link to={destino.ruta} aria-label={`${destino.texto} de ${persona.nombre}`} className="font-semibold text-teal-800 underline underline-offset-4">{destino.texto} →</Link>}
    </div>
  </div>
}

function AccionRequerimientos({ persona }) {
  const puede = usePuede('requerimientos.gestionar')
  const termino = persona.numero_doc_identidad || persona.nombre.split(' ')[0]
  const request = useManualQuery(puede ? `/solicitudes-legajo?q=${encodeURIComponent(termino)}` : null)
  if (!puede) return null
  if (request.error) return <button type="button" onClick={request.retry} className={accion}>Reintentar consulta de requerimientos</button>
  if (!request.data) return <p role="status" className="text-xs text-slate-500">Consultando requerimientos…</p>
  const tiene = request.data.total > 0
  const destino = new URLSearchParams({ clase: 'todas', q: termino })
  if (!tiene) { destino.set('nuevo', '1'); destino.set('persona', persona.usuario_id) }
  return <Link to={`/requerimientos?${destino}`} className={accion}>{tiene ? 'Ver requerimientos' : 'Crear requerimiento'} →</Link>
}

function AlertasPersona({ persona, fuentes }) {
  const resto = persona.motivos.slice(1)
  const [abierto, setAbierto] = useState(false)
  const puedeGestionar = usePuede('requerimientos.gestionar')
  const hayMas = resto.length > 0 || persona.total_alertas > persona.motivos.length
  return <>
    {(hayMas || puedeGestionar) && <div className="flex flex-col items-stretch gap-2">
      {hayMas && <button type="button" className={accion} aria-haspopup="dialog" onClick={() => setAbierto(true)}>Ver más</button>}
      <AccionRequerimientos persona={persona} />
    </div>}
    {abierto && <DocumentoModal titulo="Otros vencimientos" onCancel={() => setAbierto(false)} compacto cerrarAlClickFuera>
      <p className="mb-4 text-sm font-semibold text-slate-700">{persona.nombre}</p>
      {resto.length > 0 && <ul className="divide-y divide-slate-200 rounded-xl border border-slate-200 bg-white px-4">{resto.map((motivo, index) => <li className="py-3" key={`${motivo.origen}-${motivo.id || index}`}><Motivo persona={persona} motivo={motivo} fuentes={fuentes} compacto /></li>)}</ul>}
      {persona.total_alertas > persona.motivos.length && <div className="mt-4 space-y-3 text-sm text-slate-600"><p>{persona.total_alertas - persona.motivos.length} más en el legajo.</p><Link to={`/legajos/${persona.usuario_id}`} className={accion}>Ver legajo →</Link></div>}
    </DocumentoModal>}
  </>
}

function Resultados() {
  const [params, setParams] = useSearchParams()
  const formKey = new URLSearchParams(params)
  formKey.delete('q')
  formKey.delete('page')
  formKey.delete('por_pagina')
  // Página y tamaño de la URL ya normalizados: un valor editado a mano no llega al servidor.
  const { pagina: paginaActual, porPagina } = leerPaginacion(params)
  const consulta = conPagina(conPorPagina(params, porPagina), paginaActual)
  const request = useLiveQuery(`/vencimientos?${consulta.toString()}`)
  const result = request.data
  // Página inexistente (URL editada o guardada): se reemplaza por la última válida, con los mismos filtros y tamaño.
  const corregida = result ? paginaCorregida({ total: result.total, pagina: result.current_page, paginas: result.last_page }) : null
  useEffect(() => {
    if (corregida) setParams(conPagina(params, corregida), { replace: true })
  }, [corregida, params, setParams])
  const fuentes = Object.fromEntries((result?.origenes || []).map(origen => [origen.id, origen.nombre]))
  const gestionaRequerimientos = usePuede('requerimientos.gestionar')
  // Desplegables: se aplican al cambiar. Próximos días: al confirmar (CampoDias).
  const cambiar = clave => event => setParams(conFiltro(params, clave, event.target.value))
  // Página y tamaño entran en el historial: «atrás» vuelve a la página anterior.
  const pagina = numero => setParams(conPagina(params, numero))
  return <>
    <header className="mb-6 flex flex-wrap items-start justify-between gap-4">
      <div><p className="text-xs font-semibold uppercase tracking-widest text-teal-700">Seguimiento del personal</p><h1 className="mt-2 text-3xl font-bold text-slate-800">Vencimientos</h1><p className="mt-2 text-sm text-slate-600">Identifica qué necesita atención y accede al registro para revisarlo.</p></div>
      {/* Exporta el detalle (una fila por alerta) con los filtros aplicados; el reporte ya no se repite en Reportes. */}
      {result && <div className="grid justify-items-end gap-1"><span className="text-xs text-slate-500">Exportar detalle de alertas</span>
        <ExportarReporte tipo="vencimientos" filtros={params} opciones={{ unidades: result.areas, origenes: result.origenes, reporte: { estados: Object.fromEntries(Object.entries(estados).map(([clave, [nombre]]) => [clave, nombre])) } }} deshabilitado={!result.total} /></div>}
    </header>
    {result && <section aria-label="Resumen según los filtros" className={`mb-5 grid gap-3 ${gestionaRequerimientos ? 'grid-cols-2 xl:grid-cols-4' : 'grid-cols-1 sm:grid-cols-3'}`}>{[
      ['Personal con alertas', result.resumen.personas, '', 'text-teal-800', 'bg-teal-50'],
      ['Con vencidos', result.resumen.vencidos, 'vencido', 'text-rose-800', 'bg-rose-50'],
      ['Fuera de plazo', result.resumen.fuera_de_plazo, 'fuera_de_plazo', 'text-orange-900', 'bg-orange-50'],
      ['Solo por vencer', result.resumen.por_vencer, 'por_vencer', 'text-amber-900', 'bg-amber-50'],
    ].filter(([, , estado]) => estado !== 'fuera_de_plazo' || gestionaRequerimientos).map(([label, count, estado, color, fondo]) => <button type="button" key={label} onClick={() => setParams(conFiltro(params, 'estado', estado))} aria-pressed={(params.get('estado') || '') === estado} className={`${panel} p-4 text-left transition hover:shadow-md focus-visible:outline-2 focus-visible:outline-teal-700 ${(params.get('estado') || '') === estado ? 'ring-2 ring-teal-600' : ''}`}><span className={`inline-block rounded-lg px-2 py-1 text-xs font-semibold ${color} ${fondo}`}>{label}</span><span className={`mt-3 block text-3xl font-bold ${color}`}>{count}</span></button>)}</section>}
    <section className={`${panel} mb-6 p-5`} aria-label="Filtros de vencimientos"><form key={formKey.toString()} onSubmit={event => event.preventDefault()} className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <Campo label="Buscar personal"><input name="q" maxLength={120} value={params.get('q') || ''} onChange={e => setParams(conFiltro(params, 'q', e.target.value), { replace: true })} placeholder="Nombre o documento…" className={control} /></Campo>
      <CampoDias key={params.get('dias') || ''} params={params} setParams={setParams} />
      <Campo label="Estado del seguimiento"><select name="estado" value={params.get('estado') || ''} onChange={cambiar('estado')} className={control}><option value="">Todos los estados</option>{opcionesEstado(gestionaRequerimientos).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></Campo>
      <div className="flex flex-wrap items-center gap-2 sm:col-span-2 xl:col-span-3"><span className="mr-1 text-xs text-slate-500">Ver próximos</span>{[7, 15, 30, 90].map(dias => <button key={dias} type="button" aria-pressed={Number(params.get('dias') || DIAS_POR_DEFECTO) === dias} onClick={() => setParams(conFiltro(params, 'dias', dias))} className={`rounded-full border px-3 py-1.5 text-xs font-semibold focus-visible:outline-2 focus-visible:outline-teal-700 ${Number(params.get('dias') || DIAS_POR_DEFECTO) === dias ? 'border-teal-700 bg-teal-700 text-white' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}>{dias} días</button>)}<button type="button" onClick={() => setParams({})} className="ml-auto rounded-lg px-3 py-2 text-xs font-semibold text-teal-800 hover:bg-teal-50">Limpiar filtros</button></div>
      <details className="border-t border-slate-100 pt-3 sm:col-span-2 xl:col-span-3" open={!!(params.get('origen') || params.get('tipo_personal') || params.get('unidad_id'))}><summary className="cursor-pointer text-sm font-semibold text-teal-800">Más filtros{(params.get('origen') || params.get('tipo_personal') || params.get('unidad_id')) ? ' · activos' : ''}</summary><div className="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <Campo label="Motivo de la alerta"><select name="origen" value={params.get('origen') || ''} onChange={cambiar('origen')} className={control}><option value="">Todos los motivos</option>{(result?.origenes || []).map(origen => <option key={origen.id} value={origen.id}>{origen.nombre}</option>)}</select></Campo>
      <Campo label="Tipo de personal"><select name="tipo_personal" value={params.get('tipo_personal') || ''} onChange={cambiar('tipo_personal')} className={control}><option value="">Todo el personal</option><option value="docente">Docente</option><option value="administrativo">Administrativo</option></select></Campo>
      <Campo label="Área / unidad orgánica"><select name="unidad_id" value={params.get('unidad_id') || ''} onChange={cambiar('unidad_id')} className={control}><option value="">Todas las áreas</option>{result?.areas.map(area => <option key={area.id} value={area.id}>{area.nombre}</option>)}</select></Campo>
      </div></details>
    </form></section>
    {request.error && <div role="alert" className="mb-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-900">{apiErrorMessage(request.error)} <button onClick={request.retry} className={`${button} ml-3`}>Reintentar</button></div>}
    {request.loading && !result && <p role="status" className={`${panel} p-8`}>Consultando vencimientos…</p>}
    {result && <section aria-label="Personal con alertas de vencimiento" aria-busy={request.loading} className="space-y-3">
      {corregida && result.total > 0 ? <p role="status" className="p-10 text-center text-sm text-slate-600">La página {result.current_page} no existe. Mostrando la última página disponible…</p>
        : result.data.length ? <div className={`${panel} overflow-x-auto`}>
          <table className="w-full min-w-[900px] table-fixed text-left text-sm">
            <caption className="sr-only">Vencimientos del personal ordenados por fecha</caption>
            <colgroup><col className="w-[23%]" /><col className="w-[15%]" /><col className="w-[13%]" /><col className="w-[32%]" /><col className="w-[17%]" /></colgroup>
            <thead className="border-b border-slate-200 bg-slate-50 text-xs text-slate-600"><tr>{['Personal', 'Área', 'Estado', 'Alerta principal', 'Acciones'].map(titulo => <th scope="col" key={titulo} className="px-4 py-3 font-semibold">{titulo}</th>)}</tr></thead>
            <tbody className="divide-y divide-slate-100">{result.data.map(persona => <tr key={persona.usuario_id} className="align-middle transition-colors hover:bg-slate-50/70">
              <th scope="row" className="break-words px-4 py-3 font-semibold text-slate-800">{persona.nombre}<span className="mt-1 block text-xs font-normal text-slate-500">{persona.numero_doc_identidad} · {persona.tipo_personal}</span></th>
              <td className="break-words px-4 py-3 text-xs text-slate-600">{persona.area || <span className="text-slate-400">Sin asignar</span>}</td>
              <td className="px-4 py-3"><span className={`inline-block whitespace-nowrap rounded-lg px-2 py-1 text-xs font-semibold ${estados[persona.estado]?.[1] || estados.por_vencer[1]}`}>{estados[persona.estado]?.[0] || persona.estado}</span><p className="mt-1 text-xs text-slate-500">{persona.total_alertas} {persona.total_alertas === 1 ? 'alerta' : 'alertas'}</p></td>
              <td className="break-words px-4 py-3">{persona.motivos[0] && <Motivo persona={persona} motivo={persona.motivos[0]} fuentes={fuentes} />}</td>
              <td className="px-4 py-3"><AlertasPersona key={consulta.toString()} persona={persona} fuentes={fuentes} /></td>
            </tr>)}</tbody>
          </table>
        </div> : <p className="p-10 text-center text-sm text-slate-600">No hay alertas con estos filtros. Prueba ampliar el período o limpiar los filtros.</p>}
      {result.total > 0 && !corregida && <div className={`${panel} overflow-hidden`}><Paginacion tamano="amplio" etiqueta="Paginación de vencimientos" sustantivo="personas" desde={result.from ?? 0} hasta={result.to ?? 0} total={result.total}
        pagina={result.current_page} paginas={result.last_page} porPagina={porPagina} ocupado={request.loading}
        onPagina={pagina} onPorPagina={size => setParams(conPorPagina(params, size))} /></div>}
    </section>}
  </>
}

export default function VencimientosPage() {
  const canView = usePuede('legajo.ver')
  return canView ? <Resultados /> : <p role="alert">No tienes permiso para consultar vencimientos de legajos.</p>
}
