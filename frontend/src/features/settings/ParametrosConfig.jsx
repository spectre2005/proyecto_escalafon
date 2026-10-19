import { useState } from 'react'
import api, { apiErrorMessage } from '../../axios'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'
import { QueryStatus, WorkflowHistory } from '../../shared/WorkflowControls'
import { todayLima } from '../../shared/dates'

const entrada = 'rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800'
export default function ParametrosConfig() {
  const request = useLiveQuery('/configuracion/parametros')
  return <section className="rounded-2xl border border-slate-200 bg-white p-6"><h2 className="text-lg font-semibold">Parámetros funcionales</h2><QueryStatus request={request} />{request.data && <ParametrosForm data={request.data.data} onSaved={data => request.setData({ data })} />}</section>
}

function ParametrosForm({ data, onSaved }) {
  const [base, setBase] = useState(data)
  const [values, setValues] = useState(data.valores)
  const [motivo, setMotivo] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const set = key => event => setValues(current => ({ ...current, [key]: event.target.value }))
  const ejemplo = values.numero_legajo_prefijo.replace('{anio}', todayLima().slice(0, 4)) + '1'.padStart(Math.max(1, Math.min(10, Number(values.numero_legajo_digitos) || 1)), '0')
  async function guardar(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    setNotice('')
    try {
      const payload = Object.fromEntries(Object.entries(values).map(([key, value]) => [key, key === 'numero_legajo_prefijo' ? value : Number(value)]))
      const next = (await api.put('/configuracion/parametros', { valores: payload, version: base.version, motivo })).data.data
      setBase(next)
      setValues(next.valores)
      setMotivo('')
      onSaved(next)
      setNotice('Parámetros guardados. El historial conserva los valores anteriores.')
    } catch (failure) { setError(apiErrorMessage(failure)) }
    finally { setBusy(false) }
  }
  return <form onSubmit={guardar} aria-busy={busy} className="mt-5 space-y-4"><p className="text-sm text-slate-600">El tamaño máximo se aplica a las nuevas cargas. El formato de numeración se aplica al abrir legajos; los números existentes se conservan.</p>{data.version > base.version && <div role="status" className="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">Hay una configuración más reciente. <button type="button" disabled={busy} className="font-semibold underline" onClick={() => { setBase(data); setValues(data.valores); setError(''); setNotice('Valores actualizados desde el servidor.') }}>Recargar valores del servidor</button></div>}
    <div className="grid gap-4 sm:grid-cols-2"><label className="grid gap-1 text-sm">Días de aviso de vínculos (Sección 02)<input required type="number" min={0} max={365} step={1} value={values.dias_aviso_vencimiento_vinculo} onChange={set('dias_aviso_vencimiento_vinculo')} className={entrada} /></label><label className="grid gap-1 text-sm">Tamaño máximo de cada PDF (KB)<input required type="number" min={1} max={data.maximo_pdf_kb} step={1} value={values.tamano_maximo_pdf_kb} onChange={set('tamano_maximo_pdf_kb')} className={entrada} /><span className="text-xs text-slate-500">Máximo permitido: {data.maximo_pdf_kb} KB.</span></label><label className="grid gap-1 text-sm">Prefijo del número de legajo<input required maxLength={40} value={values.numero_legajo_prefijo} onChange={set('numero_legajo_prefijo')} className={entrada} /><span className="text-xs text-slate-500">Letras, números, punto, guion y guion bajo. {'{anio}'} representa el año.</span></label><label className="grid gap-1 text-sm">Dígitos del correlativo<input required type="number" min={1} max={10} step={1} value={values.numero_legajo_digitos} onChange={set('numero_legajo_digitos')} className={entrada} /></label></div><p className="text-sm">Ejemplo de formato: <strong>{ejemplo}</strong> · Máximo total: 20 caracteres.</p><label className="grid gap-1 text-sm">Motivo del cambio<textarea required minLength={5} maxLength={1000} rows={3} value={motivo} onChange={event => setMotivo(event.target.value)} className={entrada} /></label>{error && <p role="alert" className="text-sm text-rose-800">{error}</p>}{notice && <p role="status" className="text-sm text-teal-800">{notice}</p>}<button disabled={busy} className="rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{busy ? 'Guardando…' : 'Guardar parámetros'}</button>{base.historial_id && <WorkflowHistory entity="parametros_sistema" id={base.historial_id} />}
  </form>
}
