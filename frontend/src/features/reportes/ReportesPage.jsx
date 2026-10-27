import { cloneElement, useCallback, useEffect, useId, useState } from 'react'
import { Link, useOutletContext, useSearchParams } from 'react-router-dom'
import api, { apiErrorMessage } from '../../axios'
import Icon from '../../components/ui/Icon'
import Paginacion from '../../shared/Paginacion'
import { conPagina, conPorPagina, leerPaginacion, paginaCorregida } from '../../shared/paginacionUrl'
import { conFiltro, conFiltros, CONSULTAS_SECCION, etiquetaReporte, familiaDe, familiasDe, filtrosActivos, paramsLimpios, reporteElegido, tituloFiltroEstado, valoresIniciales } from './reportesMenu'
import { usePuede } from '../auth/session'
import { defaultInstitution } from '../../config/institution'
import ReportesPestanas from './ReportesPestanas'
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

function Resultados({ url, reporte, opciones, porPagina, onPagina, onPorPagina, onCorregir, onReintentar, institucion }) {
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
  // Página inexistente (URL editada o guardada): se reemplaza por la última válida, con los mismos filtros y tamaño.
  const corregida = data ? paginaCorregida({ total: data.meta.total, pagina: data.meta.current_page, paginas: data.meta.last_page }) : null
  useEffect(() => {
    if (corregida) onCorregir(corregida)
  }, [corregida, onCorregir])
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
      {corregida && total ? <p role="status" className="p-8 text-center text-sm text-slate-500">La página {data.meta.current_page} no existe. Mostrando la última página disponible…</p>
        : total ? <div className="overflow-x-auto"><table className="w-full min-w-[800px] text-left text-sm"><thead className="border-b border-slate-100 bg-slate-50/70"><tr>{data.columnas.map(columna => <th key={columna.key} scope="col" className="whitespace-nowrap px-5 py-3 text-[11px] font-semibold uppercase tracking-wide text-slate-500">{columna.label}</th>)}</tr></thead><tbody className="divide-y divide-slate-100">{data.data.map((fila, indice) => <tr key={`${fila.id}:${indice}`} className="hover:bg-slate-50/60">{data.columnas.map(columna => <td key={columna.key} className={`px-5 py-4 align-top ${['detalle', 'motivo', 'unidad', 'nombre', 'por_seccion'].includes(columna.key) ? 'min-w-48 max-w-xs' : 'whitespace-nowrap'}`}>
        {columna.key === 'nombre' ? puedeLegajo ? <Link to={`/legajos/${fila.usuario_id}`} className="font-medium text-slate-800 hover:text-teal-700 hover:underline">{fila.nombre}</Link> : <span className="font-medium text-slate-800">{fila.nombre}</span> : columna.key === 'estado' ? <span className={`inline-flex whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-medium ${['vencido', 'observado'].includes(fila.estado_codigo) ? 'bg-rose-50 text-rose-700' : ['activo', 'con_vinculo'].includes(fila.estado_codigo) ? 'bg-teal-50 text-teal-700' : 'bg-slate-100 text-slate-600'}`}>{fila.estado}</span> : <span className="break-words text-xs leading-relaxed text-slate-600">{valorReporte(fila, columna)}</span>}
      </td>)}</tr>)}</tbody></table></div> : <div className="grid min-h-56 place-content-center gap-3 p-8 text-center"><span className="mx-auto grid size-12 place-items-center rounded-2xl bg-teal-50 text-teal-700"><Icon name="search" size={23} /></span><h3 className="font-semibold text-slate-800">No encontramos coincidencias</h3><p className="max-w-sm text-sm text-slate-500">Prueba con otra búsqueda o amplía los filtros para consultar más registros.</p></div>}
      {total > 0 && !corregida && <Paginacion tamano="reporte" etiqueta="Paginación del reporte" sustantivo={reporte.id === 'dotacion' ? 'grupos' : 'registros'}
        desde={(data.meta.current_page - 1) * data.meta.per_page + 1} hasta={(data.meta.current_page - 1) * data.meta.per_page + 1 + data.data.length - 1} total={total} pagina={data.meta.current_page} paginas={data.meta.last_page}
        porPagina={porPagina} ocupado={!!exportando} onPagina={onPagina} onPorPagina={onPorPagina} />}
    </section>
    <details className="rounded-xl border border-slate-200 bg-white px-4 py-3 text-xs text-slate-500"><summary className="cursor-pointer font-medium">Filtros aplicados y fecha de los datos</summary><p className="mt-3">Filtros aplicados: {describirFiltros(data.meta.filtros, { ...opciones, reporte })}.</p><p className="mt-2">Los resultados corresponden únicamente a esta institución y a los permisos de tu sesión.</p></details>
  </div>
}

// Formulario de filtros: los campos parten de lo aplicado en la URL y se aplican juntos con «Aplicar» (Enter en el
// buscador también). Solo se envían los campos que se muestran para este reporte.
function FormularioFiltros({ reporte, opciones, valores, onAplicar, onCerrar }) {
  function aplicar(event) {
    event.preventDefault()
    onAplicar([...new FormData(event.currentTarget).entries()])
  }
  return <form onSubmit={aplicar} aria-label="Filtros del reporte" className="rounded-2xl border border-slate-200 bg-white p-5">
    <div className="grid items-end gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <Campo titulo="Persona: nombre, DNI o n.º de legajo"><input type="search" name="q" defaultValue={valores.q} placeholder="Buscar persona…" maxLength={120} className={control} /></Campo>
      <Campo titulo="Tipo de personal"><select name="tipo_personal" defaultValue={valores.tipo_personal} className={control}><option value="">Todo el personal</option><option value="docente">Docentes</option><option value="administrativo">Administrativos</option></select></Campo>
      <Campo titulo={['contratos', 'movimientos', 'vacaciones'].includes(reporte.id) ? 'Unidad del vínculo' : 'Unidad orgánica actual'}><select name="unidad_id" defaultValue={valores.unidad_id} className={control}><option value="">Todas las unidades</option>{opciones.unidades.map(unidad => <option key={unidad.id} value={unidad.id}>{unidad.nombre}</option>)}</select></Campo>
      {reporte.id !== 'dotacion' && Object.keys(reporte.estados).length > 1 && <Campo titulo={tituloFiltroEstado(reporte)}><select name="estado" defaultValue={valores.estado} className={control}><option value="">Todos los estados</option>{Object.entries(reporte.estados).map(([clave, nombre]) => <option key={clave} value={clave}>{nombre}</option>)}</select></Campo>}
      {['dotacion', 'contratos', 'movimientos', 'personal'].includes(reporte.id) && <Campo titulo="Ley / régimen"><select name="regimen_id" defaultValue={valores.regimen_id} className={control}><option value="">Todos los regímenes</option>{opciones.regimenes.map(item => <option key={item.id} value={item.id}>{item.codigo} · {item.nombre}</option>)}</select></Campo>}
      {reporte.id === 'formacion' && <Campo titulo="Nivel académico"><select name="nivel" defaultValue={valores.nivel} className={control}><option value="">Todos los niveles</option>{opciones.niveles.map(item => <option key={item.id} value={item.id}>{item.nombre}</option>)}</select></Campo>}
      {reporte.id === 'capacitaciones' && <Campo titulo="Tipo de capacitación"><select name="modalidad" defaultValue={valores.modalidad} className={control}><option value="">Todos los tipos</option>{opciones.modalidades.map(item => <option key={item.id} value={item.id}>{item.nombre}</option>)}</select></Campo>}
      {['inventario', 'pendientes'].includes(reporte.id) && <Campo titulo="Sección del legajo"><select name="seccion" defaultValue={valores.seccion} className={control}><option value="">Todas las secciones permitidas</option>{opciones.secciones.map(seccion => <option key={seccion.id} value={seccion.id}>{String(seccion.id).padStart(2, '0')} · {seccion.nombre}</option>)}</select></Campo>}
      {['vacaciones', 'evaluaciones'].includes(reporte.id) && <Campo titulo={reporte.id === 'evaluaciones' ? 'Ciclo de evaluación' : 'Año del período'}><input type="number" name="anio" min="1900" max="2200" defaultValue={valores.anio} placeholder="Todos los períodos" className={control} /></Campo>}
      {reporte.seccion_origen && <Campo titulo="Buscar en los datos (entidad, cargo, resultado…)"><input type="search" name="contenido" defaultValue={valores.contenido} placeholder="Texto de los registros…" maxLength={120} className={control} /></Campo>}
      {reporte.fecha && <><Campo titulo={`${reporte.fecha} · desde`}><input type="date" name="desde" defaultValue={valores.desde} className={control} /></Campo><Campo titulo="Hasta"><input type="date" name="hasta" defaultValue={valores.hasta} className={control} /></Campo></>}
      {reporte.id === 'vencimientos' && <Campo titulo="Próximos vencimientos"><select name="dias" defaultValue={valores.dias} className={control}>{[7, 15, 30, 60, 90, 180, 365].map(dias => <option key={dias} value={dias}>{dias} días</option>)}</select></Campo>}
    </div>
    <div className="mt-5 flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4"><button type="button" className={boton} onClick={onCerrar}>Cerrar</button><button type="submit" className="inline-flex items-center gap-2 rounded-xl bg-teal-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700"><Icon name="check" size={17} />Aplicar</button></div>
  </form>
}

function PanelReporte({ reporte, opciones, params, setParams, institucion }) {
  // Los filtros viven en la URL. El panel se abre con «Filtros»; las etiquetas muestran lo aplicado.
  const [abierto, setAbierto] = useState(false)
  const [revision, setRevision] = useState(0)
  const activos = filtrosActivos(params, reporte, opciones)
  // Página y tamaño ya normalizados: un valor editado a mano no llega al servidor. 10, 15 o 20 (la exportación no los usa).
  const { pagina, porPagina } = leerPaginacion(params)
  const enviados = conPagina(conPorPagina(params, porPagina), pagina)
  enviados.delete('tipo')
  const corregir = useCallback(numero => setParams(conPagina(params, numero), { replace: true }), [params, setParams])
  const url = `/reportes/${reporte.id}?${enviados}`
  return <div className="space-y-4">
    <div className="flex flex-wrap items-center gap-2">
      <button type="button" aria-expanded={abierto} onClick={() => setAbierto(valor => !valor)} className={`${boton} py-2`}><Icon name="search" size={16} />Filtros{activos.length > 0 && ` (${activos.length})`}</button>
      {activos.map(filtro => <span key={filtro.clave} className="inline-flex items-center gap-1 rounded-full border border-teal-100 bg-teal-50 py-1 pl-3 pr-1 text-xs text-teal-800">{filtro.etiqueta}
        <button type="button" aria-label={`Quitar filtro ${filtro.etiqueta}`} onClick={() => setParams(conFiltro(params, reporte.id, filtro.clave, ''))} className="grid size-5 place-items-center rounded-full hover:bg-teal-100 focus-visible:outline-2 focus-visible:outline-teal-700"><Icon name="close" size={12} /></button></span>)}
      {activos.length > 0 && <button type="button" onClick={() => setParams(paramsLimpios(params, reporte.id))} className="px-2 text-xs font-semibold text-teal-700 hover:underline">Limpiar</button>}
      <button type="button" onClick={() => setRevision(valor => valor + 1)} className="ml-auto px-2 text-xs font-semibold text-slate-500 hover:text-teal-700">Actualizar resultados</button>
    </div>
    {/* Con key: si lo aplicado cambia (etiqueta quitada, Limpiar, atrás/adelante), el formulario vuelve a partir de la URL. */}
    {abierto && <FormularioFiltros key={params.toString()} reporte={reporte} opciones={opciones} valores={valoresIniciales(params, reporte.id)} onCerrar={() => setAbierto(false)}
      onAplicar={campos => { setParams(conFiltros(params, reporte.id, campos)); setAbierto(false) }} />}
    <Resultados key={`${url}:${revision}`} url={url} reporte={reporte} opciones={opciones} institucion={institucion} onReintentar={() => setRevision(valor => valor + 1)} porPagina={porPagina} onPagina={numero => setParams(conPagina(params, numero))} onPorPagina={numero => setParams(conPorPagina(params, numero))} onCorregir={corregir} />
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

// Elegir reporte: los de la pestaña, en botones compactos (la pregunta que responde cada uno va en el tooltip); las
// consultas de una sola sección, en un desplegable aparte.
function SelectorReportes({ reportes, familia, reporte, abrir }) {
  const consultas = reportes.filter(item => item.grupo === CONSULTAS_SECCION)
  return <section aria-label="Elegir reporte" className="space-y-3 rounded-2xl border border-slate-200 bg-white p-4">
    <div className="flex flex-wrap gap-2">{reportes.filter(item => item.grupo === familia?.grupo).map(item => <button key={item.id} type="button" aria-pressed={item.id === reporte.id} title={item.pregunta || item.descripcion} onClick={() => abrir(item.id)}
      className={`rounded-full border px-3.5 py-1.5 text-sm transition-colors focus-visible:outline-2 focus-visible:outline-teal-700 ${item.id === reporte.id ? 'border-teal-600 bg-teal-50 font-semibold text-teal-800' : 'border-slate-200 text-slate-700 hover:border-teal-200 hover:bg-teal-50/40'}`}>{item.titulo}</button>)}</div>
    {consultas.length > 0 && <label className="grid gap-1.5 border-t border-slate-100 pt-3 text-xs font-medium text-slate-500 sm:max-w-xl">{CONSULTAS_SECCION}
      <select value={reporte.grupo === CONSULTAS_SECCION ? reporte.id : ''} onChange={event => event.target.value && abrir(event.target.value)} className={control}>
        <option value="">Elegir una sección…</option>{consultas.map(item => <option key={item.id} value={item.id}>{etiquetaReporte(item)}</option>)}
      </select></label>}
  </section>
}

function ContenidoReportes() {
  const opciones = useConsulta('/reportes/opciones')
  const [params, setParams] = useSearchParams()
  const institucion = useOutletContext()?.institution || defaultInstitution
  const reportes = opciones.data?.data.reportes || []
  // Pestañas por familia, en el orden del backend; sin reporte elegido se ve el primero de la pestaña (o la dotación).
  const familias = familiasDe(reportes)
  const reporte = reporteElegido(reportes, params.get('tipo'), params.get('familia'))
  const familia = familiaDe(reporte?.grupo)
  const familiaMenu = familia || familias.find(item => item.id === params.get('familia')) || familias[0]
  // El menú se pliega a una línea en cuanto se elige un reporte; «Cambiar» lo vuelve a abrir.
  const [cambiando, setCambiando] = useState(false)
  const menuAbierto = cambiando || !reportes.some(item => item.id === params.get('tipo'))
  const abrir = tipo => { setCambiando(false); setParams({ tipo }) }
  const administra = usePuede('sistema.administrar')
  const veLegajos = usePuede('legajo.ver')
  return <div className="mx-auto max-w-[1500px] space-y-5 pb-8">
    <header><h1 className="text-2xl font-bold tracking-tight text-slate-800">Reportes del personal</h1><p className="mt-1 text-sm text-slate-500">Consulta y exporta la información registrada en el legajo.</p></header>
    {opciones.loading && <p role="status" className="text-sm text-slate-500">Cargando reportes disponibles…</p>}
    {opciones.error && <p role="alert" className="rounded-xl bg-rose-50 p-4 text-sm text-rose-700">{apiErrorMessage(opciones.error)}</p>}
    {reporte && <>
      <ReportesPestanas familias={familias} activa={familia?.id} directiva={administra && veLegajos} />
      {menuAbierto && <SelectorReportes reportes={reportes} familia={familiaMenu} reporte={reporte} abrir={abrir} />}
      <div>
        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1"><p className="text-xs text-slate-500">{familia?.etiqueta || CONSULTAS_SECCION} ›</p><h2 className="text-lg font-semibold tracking-tight text-slate-800">{etiquetaReporte(reporte)}</h2>
          {!menuAbierto && <button type="button" onClick={() => setCambiando(true)} className="text-xs font-semibold text-teal-700 hover:underline focus-visible:outline-2 focus-visible:outline-teal-700">Cambiar</button>}</div>
        <p className="mt-1 text-sm text-slate-500">{reporte.descripcion}</p>
        {/* El detalle técnico de qué se cuenta y qué no, plegado junto al título. */}
        <details className="mt-1 text-xs text-slate-500"><summary className="cursor-pointer font-medium text-teal-800">Qué incluye y qué no este reporte</summary><p className="mt-2 max-w-4xl leading-relaxed">{reporte.ayuda}</p></details></div>
      <PanelReporte key={reporte.id} reporte={reporte} opciones={opciones.data.data} params={params} setParams={setParams} institucion={institucion} />
    </>}
  </div>
}

export default function ReportesPage() {
  const personal = usePuede('personal.ver')
  const legajos = usePuede('legajo.ver')
  if (!personal && !legajos) return <p role="alert" className="rounded-2xl border border-slate-200 bg-white p-6 text-sm text-slate-600">No tienes permisos para consultar reportes.</p>
  return <ContenidoReportes />
}
