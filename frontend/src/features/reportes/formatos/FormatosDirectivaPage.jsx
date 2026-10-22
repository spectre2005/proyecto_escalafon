import { useState } from 'react'
import { Link } from 'react-router-dom'
import api, { apiErrorMessage } from '../../../axios'
import { useManualQuery } from '../../../shared/queries/useManualQuery'
import { parametrosFormato } from './filtros'
import { legajoSections } from '../../legajos/config/legajoSections'
import { usePuede } from '../../auth/session'
import LegajoSearch from '../../../shared/LegajoSearch'
import Icon from '../../../components/ui/Icon'
import { WorkflowField, WorkflowPagination, QueryStatus } from '../../../shared/WorkflowControls'
import { fetchInstitution } from '../../settings/institutionApi'

const boton = 'rounded-xl border border-teal-300 bg-white px-4 py-2 text-sm font-semibold text-teal-800 disabled:opacity-50'

function Resultado({ formato, parametros, desactualizado, alcance }) {
  const [page, setPage] = useState(1)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const request = useManualQuery(`/reportes/formatos/${formato}?${parametros}&page=${page}`)
  async function exportar(tipo) {
    if (busy || desactualizado) return
    setBusy(true); setError('')
    try {
      const response = await api.get(`/reportes/formatos/${formato}?${parametros}&exportar=1`, { timeout: 120000 })
      const reporte = { ...response.data, formato }
      const generador = await import('./formatosArchivo')
      if (tipo === 'pdf') {
        const institution = await fetchInstitution()
        generador.createInstitutionalReport(reporte, institution.data).save(`${formato}.pdf`)
      } else {
        const buffer = await generador.createInstitutionalWorkbook(reporte)
        const url = URL.createObjectURL(new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }))
        const a = document.createElement('a'); a.href = url; a.download = `${formato}.xlsx`; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000)
      }
    } catch (failure) { setError(apiErrorMessage(failure)) } finally { setBusy(false) }
  }
  return <section className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5"><QueryStatus request={request} />{error && <p role="alert" className="text-rose-800">{error}</p>}{request.data && <><div><p className="text-[10px] font-semibold uppercase tracking-wider text-teal-700">Resultados de la consulta</p><h2 className="mt-1 font-semibold text-slate-900">{request.data.titulo}</h2><p className="mt-1 text-xs text-slate-500">{alcance} · {request.data.total} registro(s)</p></div>{desactualizado && <p role="status" className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Cambiaste los filtros. Pulsa «Consultar reporte» para actualizar los resultados y habilitar la exportación.</p>}<div className="flex gap-3"><button className={boton} disabled={busy || desactualizado || !request.data.total} onClick={() => exportar('pdf')}>Exportar PDF</button><button className={boton} disabled={busy || desactualizado || !request.data.total} onClick={() => exportar('excel')}>Exportar Excel</button>{busy && <span role="status">Preparando todos los resultados filtrados…</span>}</div>{!request.data.data.length ? <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-7 text-center"><p className="font-medium text-slate-700">No hay registros para esta consulta</p><p className="mt-2 text-sm text-slate-500">{formato === 'referencias' ? 'Este anexo requiere referencias registradas a documentos de un legajo pasivo. Puedes revisarlas en la sección 13 de la ficha.' : 'Prueba con otro nombre, cambia el estado o amplía los filtros de la consulta.'}</p></div> : <div className="overflow-x-auto"><table className="w-full text-left text-xs"><thead><tr>{Object.entries(request.data.columnas).map(([key, titulo]) => <th key={key} className="border-b p-3" scope="col">{titulo}</th>)}</tr></thead><tbody>{request.data.data.map((fila, i) => <tr key={i}>{Object.keys(request.data.columnas).map(key => <td key={key} className="border-b p-3">{fila[key] ?? '—'}</td>)}</tr>)}</tbody></table></div>}<WorkflowPagination data={request.data} setPage={setPage} loading={request.loading} /></>}</section>
}

// Secciones 01 a 13 de la directiva (las 12 y 13 no tienen espacio propio en la ficha).
const secciones = [...legajoSections, { number: '12', title: 'Otros' }, { number: '13', title: 'Legajo pasivo' }]

const descripciones = {
  matriz: 'Consulta el personal, su número de legajo, puesto, unidad y estado del expediente.',
  caratula: 'Genera la portada de un expediente. Selecciona un legajo específico para continuar.',
  incorporaciones: 'Consulta los documentos incorporados al legajo, con sus fechas, secciones y folios.',
  referencias: 'Consulta referencias registradas a documentos de legajos anteriores en estado pasivo.',
}

function ContenidoFormatos() {
  const catalogo = useManualQuery('/reportes/formatos')
  const [formato, setFormato] = useState('matriz')
  const [search, setSearch] = useState('')
  const [legajo, setLegajo] = useState(null)
  const [estado, setEstado] = useState('')
  const [fechas, setFechas] = useState({ desde: '', hasta: '', seccion: '' })
  const [consulta, setConsulta] = useState(null)
  const parametros = parametrosFormato({ formato, search, legajo, estado, ...fechas })
  const requiereSeleccion = formato === 'caratula' && !legajo
  const desactualizado = Boolean(consulta && (consulta.formato !== formato || consulta.parametros !== parametros))
  const alcance = legajo ? `${[legajo.usuario?.nombres, legajo.usuario?.apellido_paterno, legajo.usuario?.apellido_materno].filter(Boolean).join(' ')} · ${legajo.numero_legajo}` : search.trim() ? `Coincidencias con «${search.trim()}»` : 'Todos los legajos'
  function consultar(event) {
    event.preventDefault()
    if (requiereSeleccion) return
    setConsulta(previous => ({ formato, parametros, alcance, version: (previous?.version || 0) + 1 }))
  }
  function limpiar() { setSearch(''); setLegajo(null); setEstado(''); setFechas({ desde: '', hasta: '', seccion: '' }); setConsulta(null) }
  return <section className="space-y-6">
    <header className="flex items-start gap-4"><span className="hidden size-12 shrink-0 place-items-center rounded-2xl border border-teal-100 bg-teal-50 text-teal-700 sm:grid"><Icon name="chart" size={23} /></span><div><Link to="/reportes" className="text-xs font-semibold text-teal-700 hover:underline">← Reportes del personal</Link><h1 className="mt-1 text-2xl font-bold text-slate-900">Formatos de la directiva</h1><p className="mt-2 text-sm text-slate-500">Anexos 06 a 09 de la Directiva N° 001-2023-SERVIR-GDSRH. Busca a una persona o su legajo y genera el formato que necesitas.</p></div></header>
    <QueryStatus request={catalogo} />
    {catalogo.data && <form onSubmit={consultar} className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
      <div className="border-b border-slate-100 bg-slate-50/70 px-5 py-4 sm:px-6"><h2 className="text-sm font-semibold text-slate-800">Prepara tu reporte</h2><p className="mt-1 text-xs text-slate-500">Elige el formato y busca por nombre, apellidos, documento o número de legajo.</p></div>
      <div className="space-y-6 p-5 sm:p-6">
        <div className="grid items-start gap-5 sm:grid-cols-[minmax(0,2fr)_minmax(160px,1fr)]"><div><WorkflowField label="Formato del reporte" value={formato} onChange={event => setFormato(event.target.value)}>{catalogo.data.data.map(f => <option key={f.id} value={f.id}>{f.titulo}</option>)}</WorkflowField><p className="mt-2 text-xs leading-relaxed text-slate-500">{descripciones[formato]}</p></div><div><WorkflowField label="Estado del legajo" required={false} disabled={Boolean(legajo)} value={legajo ? legajo.estado_legajo : estado} onChange={event => setEstado(event.target.value)}><option value="">Todos los estados</option><option value="activo">Activo</option><option value="pasivo">Pasivo</option></WorkflowField>{legajo && <p className="mt-2 text-xs text-slate-500">Corresponde al expediente seleccionado.</p>}</div></div>
        <LegajoSearch search={search} legajo={legajo} onSearch={value => { setSearch(value); setLegajo(null) }} onSelect={value => { setLegajo(value); setSearch('') }} onClear={() => { setLegajo(null); setSearch('') }} />
        {['incorporaciones', 'referencias'].includes(formato) && <fieldset className="grid gap-4 rounded-xl border border-slate-200 p-4 sm:grid-cols-3"><legend className="px-2 text-xs font-semibold text-slate-600">Filtros opcionales</legend>
          {formato === 'incorporaciones' && <><WorkflowField label="Desde" type="date" required={false} value={fechas.desde} max={fechas.hasta || undefined} onChange={event => setFechas(current => ({ ...current, desde: event.target.value }))} /><WorkflowField label="Hasta" type="date" required={false} value={fechas.hasta} min={fechas.desde || undefined} onChange={event => setFechas(current => ({ ...current, hasta: event.target.value }))} /></>}
          <WorkflowField label="Sección" required={false} value={fechas.seccion} onChange={event => setFechas(current => ({ ...current, seccion: event.target.value }))}><option value="">Todas las secciones</option>{secciones.map(seccion => <option key={seccion.number} value={Number(seccion.number)}>{seccion.number} {seccion.title}</option>)}</WorkflowField>
        </fieldset>}
      </div>
      <div className="flex flex-wrap items-center justify-between gap-4 border-t border-slate-100 bg-slate-50/50 px-5 py-4 sm:px-6"><p className="text-xs text-slate-500">{requiereSeleccion ? 'Selecciona un resultado de búsqueda para generar la carátula.' : legajo ? 'Consulta del expediente seleccionado.' : search.trim() ? 'Se consultarán todos los legajos que coincidan con la búsqueda.' : 'Sin búsqueda: se consultarán todos los legajos del estado elegido.'}</p><div className="flex gap-3"><button type="button" className="rounded-xl px-3 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-100" onClick={limpiar}>Limpiar filtros</button><button className="inline-flex items-center gap-2 rounded-xl bg-teal-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50" disabled={requiereSeleccion}>Consultar reporte<Icon name="arrow" size={17} /></button></div></div>
    </form>}
    {consulta ? <Resultado key={`${consulta.formato}:${consulta.parametros}:${consulta.version}`} {...consulta} desactualizado={desactualizado} /> : catalogo.data && <div className="rounded-2xl border border-dashed border-slate-200 px-6 py-9 text-center"><span className="mx-auto grid size-11 place-items-center rounded-full bg-slate-100 text-slate-400"><Icon name="file" /></span><h2 className="mt-3 text-sm font-semibold text-slate-700">Tu reporte aparecerá aquí</h2><p className="mt-2 text-sm text-slate-500">Selecciona un formato y pulsa «Consultar reporte». Podrás descargar los resultados en PDF o Excel.</p></div>}
  </section>
}

export default function FormatosDirectivaPage() {
  const administra = usePuede('sistema.administrar')
  const legajos = usePuede('legajo.ver')
  if (!administra || !legajos) return <p role="alert" className="rounded-2xl border border-slate-200 bg-white p-6 text-sm text-slate-600">Los formatos de la directiva son para quien administra el sistema.</p>
  return <ContenidoFormatos />
}
