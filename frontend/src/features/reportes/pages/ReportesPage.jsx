import { useState } from 'react'
import api, { apiErrorMessage } from '../../../axios'
import { useLiveQuery } from '../../../shared/queries/useLiveQuery'
import { WorkflowField, WorkflowPagination, QueryStatus, LegajoPicker } from '../../../shared/WorkflowControls'
import { fetchInstitution } from '../../settings/institutionApi'

const boton = 'rounded-xl border border-teal-300 bg-white px-4 py-2 text-sm font-semibold text-teal-800 disabled:opacity-50'

function Resultado({ formato, parametros }) {
  const [page, setPage] = useState(1)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const request = useLiveQuery(`/reportes/${formato}?${parametros}&page=${page}`)
  async function exportar(tipo) {
    if (busy) return
    setBusy(true); setError('')
    try {
      const response = await api.get(`/reportes/${formato}?${parametros}&exportar=1`, { timeout: 120000 })
      const reporte = { ...response.data, formato }
      const generador = await import('../pdf/reportesInstitucionales')
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
  return <section className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5"><QueryStatus request={request} />{error && <p role="alert" className="text-rose-800">{error}</p>}{request.data && <><h2 className="font-semibold">{request.data.titulo} · {request.data.total} registro(s)</h2><div className="flex gap-3"><button className={boton} disabled={busy || !request.data.total} onClick={() => exportar('pdf')}>Exportar PDF</button><button className={boton} disabled={busy || !request.data.total} onClick={() => exportar('excel')}>Exportar Excel</button>{busy && <span role="status">Preparando todos los resultados filtrados…</span>}</div>{!request.data.data.length ? <p>Sin registros para estos filtros.</p> : <div className="overflow-x-auto"><table className="w-full text-left text-xs"><thead><tr>{Object.entries(request.data.columnas).map(([key, titulo]) => <th key={key} className="border-b p-3" scope="col">{titulo}</th>)}</tr></thead><tbody>{request.data.data.map((fila, i) => <tr key={i}>{Object.keys(request.data.columnas).map(key => <td key={key} className="border-b p-3">{fila[key] ?? '—'}</td>)}</tr>)}</tbody></table></div>}<WorkflowPagination data={request.data} setPage={setPage} loading={request.loading} /></>}</section>
}

export default function ReportesPage() {
  const catalogo = useLiveQuery('/reportes')
  const [formato, setFormato] = useState('matriz')
  const [legajo, setLegajo] = useState(null)
  const [consulta, setConsulta] = useState(null)
  function consultar(event) {
    event.preventDefault()
    const params = new URLSearchParams()
    for (const [key, value] of new FormData(event.currentTarget)) if (value) params.set(key, value)
    if (legajo) params.set('legajo_id', legajo.id)
    setConsulta({ formato, parametros: params.toString() })
  }
  return <section className="space-y-5"><h1 className="text-2xl font-bold">Reportes institucionales</h1><p className="text-sm text-slate-600">Formatos con datos registrados, sujetos a los permisos de consulta. Las referencias mantienen sus folios originales; las firmas se completan por el responsable. El control de incorporaciones muestra documentos disponibles y su estado.</p><QueryStatus request={catalogo} />{catalogo.data && <form onSubmit={consultar} className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5"><WorkflowField label="Formato" value={formato} onChange={event => setFormato(event.target.value)}>{catalogo.data.data.map(f => <option key={f.id} value={f.id}>{f.titulo}</option>)}</WorkflowField><WorkflowField name="q" label="Nombre, documento o número de legajo" required={false} maxLength={120} /><WorkflowField name="estado" label="Estado del legajo" required={false}><option value="">Todos</option><option value="activo">Activo</option><option value="pasivo">Pasivo</option></WorkflowField>{formato === 'incorporaciones' && <div className="grid gap-4 sm:grid-cols-3"><WorkflowField name="desde" label="Incorporado desde" type="date" required={false} /><WorkflowField name="hasta" label="Incorporado hasta" type="date" required={false} /><WorkflowField name="seccion" label="Sección" type="number" min={1} max={13} required={false} /></div>}<LegajoPicker value={legajo} onChange={setLegajo} />{legajo && <button type="button" className={boton} onClick={() => setLegajo(null)}>Consultar todos los legajos</button>}<button className={boton} disabled={formato === 'caratula' && !legajo}>Consultar reporte</button>{formato === 'caratula' && !legajo && <p className="text-sm">Selecciona un legajo para generar su carátula.</p>}</form>}{consulta && <Resultado key={`${consulta.formato}:${consulta.parametros}`} {...consulta} />}</section>
}
