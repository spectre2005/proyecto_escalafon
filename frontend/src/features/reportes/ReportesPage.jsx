import { cloneElement, useEffect, useId, useState } from 'react'
import { Link, useOutletContext, useSearchParams } from 'react-router-dom'
import api, { apiErrorMessage } from '../../axios'
import Icon from '../../components/ui/Icon'
import { usePuede } from '../auth/session'
import { defaultInstitution } from '../../config/institution'
import { crearExcelReporte, crearPdfReporte, descargarExcel, describirFiltros, valorReporte } from './exportarReporte'

const control = 'w-full min-w-0 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-100 disabled:opacity-50'
const boton = 'inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition-colors hover:border-teal-200 hover:bg-teal-50 focus-visible:outline-2 focus-visible:outline-teal-700 disabled:cursor-not-allowed disabled:opacity-50'

function useConsulta(url) {
  const [resultado, setResultado] = useState({ data: null, loading: true, error: null })
  useEffect(() => {
    const controller = new AbortController()
    api.get(url, { signal: controller.signal }).then(response => {
      if (!controller.signal.aborted) setResultado({ data: response.data, loading: false, error: null })
    }).catch(error => { if (!controller.signal.aborted) setResultado({ data: null, loading: false, error }) })
    return () => controller.abort()
  }, [url])
  return resultado
}

function Campo({ titulo, children }) {
  const id = useId()
  return <div className="grid min-w-0 gap-1.5 text-xs font-medium text-slate-500"><label htmlFor={id}>{titulo}</label>{cloneElement(children, { id })}</div>
}

function Resultados({ url, reporte, opciones, onPagina, onReintentar, institucion }) {
  const consulta = useConsulta(url)
  const [exportando, setExportando] = useState(null)
  const [errorExportar, setErrorExportar] = useState('')
  const puedeLegajo = usePuede('legajo.ver')
  const data = consulta.data
  async function exportar(formato) {
    if (exportando) return
    setExportando(formato)
    setErrorExportar('')
    try {
      const parametros = new URLSearchParams(url.split('?')[1])
      parametros.delete('page')
      parametros.delete('por_pagina')
      parametros.set('exportar', '1')
      const response = await api.get(`/reportes/${reporte.id}?${parametros}`, { timeout: 120000 })
      const completo = response.data
      const filtros = describirFiltros(completo.meta.filtros, { ...opciones, reporte })
      const nombre = `${reporte.id}_${new Date(completo.meta.generado_en).toLocaleDateString('sv-SE', { timeZone: 'America/Lima' })}`
      if (formato === 'excel') descargarExcel(await crearExcelReporte(completo, institucion, filtros), `${nombre}.xlsx`)
      else (await crearPdfReporte(completo, institucion, filtros)).save(`${nombre}.pdf`)
    } catch (error) { setErrorExportar(apiErrorMessage(error)) } finally { setExportando(null) }
  }
  if (consulta.loading) return <div role="status" className="flex min-h-64 items-center justify-center gap-3 rounded-2xl border border-slate-200 bg-white text-sm text-slate-500"><span className="size-5 animate-spin rounded-full border-2 border-slate-200 border-t-teal-700" />Preparando reporte…</div>
  if (consulta.error) return <div role="alert" className="rounded-2xl border border-rose-100 bg-white p-6 text-sm text-rose-700">{apiErrorMessage(consulta.error)}<button type="button" onClick={onReintentar} className={`${boton} ml-3`}>Reintentar</button></div>
  const total = data.meta.total
  return <div className="space-y-4">
    <div className="grid gap-3 sm:grid-cols-3">
      {[[total.toLocaleString('es-PE'), reporte.id === 'dotacion' ? 'Grupos por unidad y régimen' : 'Registros filtrados', 'file'], [data.resumen.personas.toLocaleString('es-PE'), 'Personas incluidas', 'users'], [new Date(data.meta.generado_en).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Lima' }), 'Última consulta · hora de Lima', 'clock']].map(([valor, etiqueta, icono]) => <div key={etiqueta} className="flex items-center gap-4 rounded-2xl border border-slate-200 bg-white px-5 py-4"><span className="grid size-10 shrink-0 place-items-center rounded-xl bg-slate-50 text-teal-700"><Icon name={icono} /></span><div><p className="text-xl font-semibold tracking-tight text-slate-800">{valor}</p><p className="mt-0.5 text-xs text-slate-500">{etiqueta}</p></div></div>)}
    </div>
    <Distribucion reporte={reporte} resumen={data.resumen} />
    <section aria-label="Resultados del reporte" className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
      <div className="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 p-5">
        <div><h2 className="font-semibold text-slate-800">Vista previa</h2><p className="mt-1 text-xs text-slate-500">{total ? `Resultados ${(data.meta.current_page - 1) * data.meta.per_page + 1}–${Math.min(data.meta.current_page * data.meta.per_page, total)} de ${total}` : 'Sin resultados para estos filtros'} · La exportación incluye todos los resultados.</p></div>
        <div className="flex flex-wrap gap-2">
          <button type="button" disabled={!total || !!exportando} onClick={() => exportar('excel')} className={boton}><Icon name="file" size={17} />{exportando === 'excel' ? 'Generando Excel…' : 'Excel'}</button>
          <button type="button" disabled={!total || !!exportando} onClick={() => exportar('pdf')} className={boton}><Icon name="file" size={17} />{exportando === 'pdf' ? 'Generando PDF…' : 'PDF'}</button>
        </div>
      </div>
      {errorExportar && <p role="alert" className="mx-5 mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{errorExportar}</p>}
      {exportando && <p role="status" className="px-5 pt-4 text-xs text-teal-700">Preparando todos los resultados filtrados…</p>}
      {total ? <div className="overflow-x-auto"><table className="w-full min-w-[800px] text-left text-sm"><thead className="border-b border-slate-100 bg-slate-50/70"><tr>{data.columnas.map(columna => <th key={columna.key} scope="col" className="whitespace-nowrap px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-slate-500">{columna.label}</th>)}</tr></thead><tbody className="divide-y divide-slate-100">{data.data.map((fila, indice) => <tr key={`${fila.id}:${indice}`} className="hover:bg-slate-50/60">{data.columnas.map(columna => <td key={columna.key} className={`px-5 py-4 align-top ${['detalle', 'motivo', 'unidad', 'nombre', 'por_seccion'].includes(columna.key) ? 'min-w-48 max-w-xs' : 'whitespace-nowrap'}`}>
        {columna.key === 'nombre' ? puedeLegajo ? <Link to={`/legajos/${fila.usuario_id}`} className="font-medium text-slate-800 hover:text-teal-700 hover:underline">{fila.nombre}</Link> : <span className="font-medium text-slate-800">{fila.nombre}</span> : columna.key === 'estado' ? <span className={`inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium ${['vencido', 'observado'].includes(fila.estado_codigo) ? 'bg-rose-50 text-rose-700' : ['activo', 'con_vinculo'].includes(fila.estado_codigo) ? 'bg-teal-50 text-teal-700' : 'bg-slate-100 text-slate-600'}`}>{fila.estado}</span> : <span className="break-words text-xs leading-relaxed text-slate-600">{valorReporte(fila, columna)}</span>}
      </td>)}</tr>)}</tbody></table></div> : <div className="grid min-h-56 place-content-center gap-3 p-8 text-center"><span className="mx-auto grid size-12 place-items-center rounded-2xl bg-teal-50 text-teal-700"><Icon name="search" size={23} /></span><h3 className="font-semibold text-slate-800">No encontramos coincidencias</h3><p className="max-w-sm text-sm text-slate-500">Prueba con otra búsqueda o amplía los filtros para consultar más registros.</p></div>}
      {data.meta.last_page > 1 && <nav aria-label="Paginación del reporte" className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 p-4"><span className="text-xs text-slate-500">Página {data.meta.current_page} de {data.meta.last_page}</span><div className="flex gap-2"><button type="button" className={boton} disabled={data.meta.current_page <= 1 || !!exportando} onClick={() => onPagina(data.meta.current_page - 1)}>Anterior</button><button type="button" className={boton} disabled={data.meta.current_page >= data.meta.last_page || !!exportando} onClick={() => onPagina(data.meta.current_page + 1)}>Siguiente</button></div></nav>}
    </section>
    <details className="rounded-xl border border-slate-200 bg-white px-4 py-3 text-xs text-slate-500"><summary className="cursor-pointer font-medium">Criterios y alcance del reporte</summary><p className="mt-3 leading-relaxed">{reporte.ayuda}</p><p className="mt-2">Filtros aplicados: {describirFiltros(data.meta.filtros, { ...opciones, reporte })}.</p><p className="mt-2">Los resultados corresponden únicamente a esta institución y a los permisos de tu sesión.</p></details>
  </div>
}

function PanelReporte({ reporte, opciones, params, setParams, institucion }) {
  const [formulario, setFormulario] = useState(() => Object.fromEntries(['q', 'tipo_personal', 'unidad_id', 'estado', 'seccion', 'desde', 'hasta', 'dias', 'anio', 'regimen_id', 'nivel', 'modalidad', 'contenido'].map(clave => [clave, params.get(clave) || (clave === 'dias' ? '30' : clave === 'anio' && reporte.id === 'evaluaciones' ? String(new Date().getFullYear()) : '')])))
  const [revision, setRevision] = useState(0)
  const cambiar = clave => event => setFormulario(anterior => ({ ...anterior, [clave]: event.target.value }))
  const enviados = new URLSearchParams(params)
  enviados.delete('tipo')
  const url = `/reportes/${reporte.id}?${enviados}`
  function aplicar(event) {
    event.preventDefault()
    const siguiente = new URLSearchParams({ tipo: reporte.id })
    Object.entries(formulario).forEach(([clave, valor]) => { if (valor) siguiente.set(clave, valor) })
    if (reporte.id !== 'vencimientos') siguiente.delete('dias')
    setParams(siguiente)
    setRevision(valor => valor + 1)
  }
  return <div className="space-y-5">
    <form onSubmit={aplicar} className="rounded-2xl border border-slate-200 bg-white p-5">
      <div className="mb-4 flex items-center gap-2 text-sm font-semibold text-slate-800"><Icon name="search" size={17} />Filtrar resultados</div>
      <div className="grid items-end gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Campo titulo="Nombre, DNI o número de legajo"><input type="search" value={formulario.q} onChange={cambiar('q')} placeholder="Buscar persona…" maxLength={120} className={control} /></Campo>
        <Campo titulo="Tipo de personal"><select value={formulario.tipo_personal} onChange={cambiar('tipo_personal')} className={control}><option value="">Todo el personal</option><option value="docente">Docentes</option><option value="administrativo">Administrativos</option></select></Campo>
        <Campo titulo={['contratos', 'movimientos', 'vacaciones'].includes(reporte.id) ? 'Unidad del vínculo' : 'Unidad orgánica actual'}><select value={formulario.unidad_id} onChange={cambiar('unidad_id')} className={control}><option value="">Todas las unidades</option>{opciones.unidades.map(unidad => <option key={unidad.id} value={unidad.id}>{unidad.nombre}</option>)}</select></Campo>
        {reporte.id !== 'dotacion' && Object.keys(reporte.estados).length > 1 && <Campo titulo="Estado"><select value={formulario.estado} onChange={cambiar('estado')} className={control}><option value="">Todos los estados</option>{Object.entries(reporte.estados).map(([clave, nombre]) => <option key={clave} value={clave}>{nombre}</option>)}</select></Campo>}
        {['dotacion', 'contratos', 'movimientos', 'personal'].includes(reporte.id) && <Campo titulo="Ley / régimen"><select value={formulario.regimen_id} onChange={cambiar('regimen_id')} className={control}><option value="">Todos los regímenes</option>{opciones.regimenes.map(item => <option key={item.id} value={item.id}>{item.codigo} · {item.nombre}</option>)}</select></Campo>}
        {reporte.id === 'formacion' && <Campo titulo="Nivel académico"><select value={formulario.nivel} onChange={cambiar('nivel')} className={control}><option value="">Todos los niveles</option>{opciones.niveles.map(item => <option key={item.id} value={item.id}>{item.nombre}</option>)}</select></Campo>}
        {reporte.id === 'capacitaciones' && <Campo titulo="Tipo de capacitación"><select value={formulario.modalidad} onChange={cambiar('modalidad')} className={control}><option value="">Todos los tipos</option>{opciones.modalidades.map(item => <option key={item.id} value={item.id}>{item.nombre}</option>)}</select></Campo>}
        {['inventario', 'pendientes'].includes(reporte.id) && <Campo titulo="Sección del legajo"><select value={formulario.seccion} onChange={cambiar('seccion')} className={control}><option value="">Todas las secciones permitidas</option>{opciones.secciones.map(seccion => <option key={seccion.id} value={seccion.id}>{String(seccion.id).padStart(2, '0')} · {seccion.nombre}</option>)}</select></Campo>}
        {['vacaciones', 'evaluaciones'].includes(reporte.id) && <Campo titulo={reporte.id === 'evaluaciones' ? 'Ciclo de evaluación' : 'Año del período'}><input type="number" min="1900" max="2200" value={formulario.anio} onChange={cambiar('anio')} placeholder="Todos los períodos" className={control} /></Campo>}
        {reporte.seccion_origen && <Campo titulo="Buscar en los datos del reporte"><input type="search" value={formulario.contenido} onChange={cambiar('contenido')} placeholder="Entidad, cargo, resultado…" maxLength={120} className={control} /></Campo>}
        {reporte.fecha && <><Campo titulo={`${reporte.fecha} · desde`}><input type="date" value={formulario.desde} max={formulario.hasta || undefined} onChange={cambiar('desde')} className={control} /></Campo><Campo titulo="Hasta"><input type="date" value={formulario.hasta} min={formulario.desde || undefined} onChange={cambiar('hasta')} className={control} /></Campo></>}
        {reporte.id === 'vencimientos' && <Campo titulo="Próximos vencimientos"><select value={formulario.dias} onChange={cambiar('dias')} className={control}>{[7, 15, 30, 60, 90, 180, 365].map(dias => <option key={dias} value={dias}>{dias} días</option>)}</select></Campo>}
      </div>
      <div className="mt-5 flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4"><button type="button" className={boton} onClick={() => { setFormulario({ q: '', contenido: '', regimen_id: '', nivel: '', modalidad: '', tipo_personal: '', unidad_id: '', estado: '', seccion: '', desde: '', hasta: '', dias: '30', anio: reporte.id === 'evaluaciones' ? String(new Date().getFullYear()) : '' }); setParams({ tipo: reporte.id }); setRevision(valor => valor + 1) }}>Limpiar filtros</button><button type="submit" className="inline-flex items-center gap-2 rounded-xl bg-teal-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700"><Icon name="chart" size={17} />Generar reporte</button></div>
    </form>
    <Resultados key={`${url}:${revision}`} url={url} reporte={reporte} opciones={opciones} institucion={institucion} onReintentar={() => setRevision(valor => valor + 1)} onPagina={pagina => { const siguiente = new URLSearchParams(params); siguiente.set('page', String(pagina)); setParams(siguiente) }} />
  </div>
}

function Distribucion({ reporte, resumen }) {
  const entradas = Object.entries(resumen.por_estado)
  const total = entradas.reduce((suma, [, cantidad]) => suma + cantidad, 0)
  if (!total || entradas.length === 1 && entradas[0][0] === 'registrado') return null
  return <section aria-label="Resumen visual del reporte" className="rounded-2xl border border-slate-200 bg-white p-5">
    <div className="mb-4 flex flex-wrap items-center justify-between gap-2"><h2 className="text-sm font-semibold text-slate-800">{reporte.id === 'dotacion' ? 'Composición del personal con vínculo abierto' : 'Distribución de los resultados'}</h2>{reporte.id === 'capacitaciones' && <span className="text-xs text-teal-700">{resumen.horas.toLocaleString('es-PE')} horas declaradas</span>}</div>
    <div className="grid gap-x-8 gap-y-4 sm:grid-cols-2">{entradas.map(([estado, cantidad]) => <div key={estado}><div className="mb-2 flex justify-between gap-3 text-xs"><span className="text-slate-500">{reporte.estados[estado] || estado}</span><span className="font-semibold text-slate-700">{cantidad.toLocaleString('es-PE')} <span className="font-normal text-slate-400">· {Math.round(cantidad / total * 100)}%</span></span></div><div className="h-1.5 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-teal-600" style={{ width: `${cantidad / total * 100}%` }} /></div></div>)}</div>
  </section>
}

function ContenidoReportes() {
  const opciones = useConsulta('/reportes/opciones')
  const [params, setParams] = useSearchParams()
  const institucion = useOutletContext()?.institution || defaultInstitution
  const reportes = opciones.data?.data.reportes || []
  const reporte = reportes.find(item => item.id === params.get('tipo')) || reportes.find(item => item.id === 'personal') || reportes[0]
  const grupos = [...new Set(reportes.map(item => item.grupo))].sort((a, b) => a.localeCompare(b, 'es'))
  const abrir = tipo => setParams({ tipo })
  const administra = usePuede('sistema.administrar')
  const veLegajos = usePuede('legajo.ver')
  const formatos = administra && veLegajos
  return <div className="mx-auto max-w-[1500px] space-y-6 pb-8">
    <header className="flex flex-wrap items-start justify-between gap-4"><div><p className="text-[10px] font-semibold uppercase tracking-[.2em] text-teal-700">Personal con legajo</p><h1 className="mt-2 text-3xl font-bold tracking-tight text-slate-800">Reportes del personal</h1><p className="mt-2 text-sm leading-relaxed text-slate-500">Consulta y exporta la información registrada en las once secciones del legajo.</p></div>{formatos && <Link to="/reportes/formatos" className="inline-flex items-center gap-2 rounded-xl border border-teal-200 bg-white px-4 py-2.5 text-sm font-semibold text-teal-800 hover:bg-teal-50"><Icon name="file" size={17} />Formatos de la directiva (anexos 06 a 09)</Link>}</header>
    {opciones.loading && <p role="status" className="text-sm text-slate-500">Cargando reportes disponibles…</p>}
    {opciones.error && <p role="alert" className="rounded-xl bg-rose-50 p-4 text-sm text-rose-700">{apiErrorMessage(opciones.error)}</p>}
    {reporte && <>
      <section aria-label="Elegir reporte" className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 md:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)]">
        <Campo titulo="Sección del reporte"><select value={reporte.grupo} onChange={event => abrir(reportes.find(item => item.grupo === event.target.value).id)} className={control}>{grupos.map(grupo => <option key={grupo} value={grupo}>{grupo}</option>)}</select></Campo>
        <Campo titulo="Reporte"><select value={reporte.id} onChange={event => abrir(event.target.value)} className={control}>{reportes.filter(item => item.grupo === reporte.grupo).map(item => <option key={item.id} value={item.id}>{item.titulo}</option>)}</select></Campo>
      </section>
      <div><h2 className="text-xl font-semibold tracking-tight text-slate-800">{reporte.titulo}</h2><p className="mt-2 text-sm text-slate-500">{reporte.descripcion}</p></div>
      <PanelReporte key={`${reporte.id}:${params.toString()}`} reporte={reporte} opciones={opciones.data.data} params={params} setParams={setParams} institucion={institucion} />
    </>}
  </div>
}

export default function ReportesPage() {
  const personal = usePuede('personal.ver')
  const legajos = usePuede('legajo.ver')
  if (!personal && !legajos) return <p role="alert" className="rounded-2xl border border-slate-200 bg-white p-6 text-sm text-slate-600">No tienes permisos para consultar reportes.</p>
  return <ContenidoReportes />
}
