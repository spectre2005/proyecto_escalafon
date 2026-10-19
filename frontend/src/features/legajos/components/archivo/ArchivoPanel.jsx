import { useState } from 'react'
import api, { apiErrorMessage } from '../../../../axios'
import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { QueryStatus, WorkflowField, WorkflowPagination } from '../../../../shared/WorkflowControls'
import { todayLima, formatDate } from '../../../../shared/dates'
import { usePuede } from '../../../auth/session'
import VisorPdf from '../documentos/VisorPdf'
import DocumentoModal from '../documentos/DocumentoModal'

const boton = 'rounded-xl border border-teal-300 px-3 py-2 text-sm font-semibold text-teal-800 disabled:opacity-50'

function ReferenciaForm({ usuario, data, onSaved, onCancel }) {
  const [origen, setOrigen] = useState('')
  const [page, setPage] = useState(1)
  const [documento, setDocumento] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function guardar(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true); setError('')
    try {
      const valores = Object.fromEntries(new FormData(event.currentTarget))
      await api.post(`/expedientes/${usuario}/archivo/referencias`, { ...valores, documento_origen_id: documento })
      setDocumento(''); onSaved('Referencia registrada sin duplicar el PDF ni sus folios.')
    } catch (failure) { setError(apiErrorMessage(failure)) } finally { setBusy(false) }
  }
  return <DocumentoModal titulo="Referenciar documento del legajo anterior" busy={busy} onCancel={onCancel}><form onSubmit={guardar} aria-busy={busy} className="space-y-4 rounded-xl border border-slate-200 p-4"><h3 className="font-semibold">Referenciar un documento del legajo anterior</h3><WorkflowField label="Legajo pasivo de origen" value={origen} onChange={event => { setOrigen(event.target.value); setPage(1); setDocumento('') }}><option value="">Selecciona un legajo</option>{data.pasivos.map(l => <option key={l.id} value={l.id}>{l.numero_legajo}</option>)}</WorkflowField>{origen && <DocumentoSelector usuario={usuario} origen={origen} page={page} setPage={value => { setPage(value); setDocumento('') }} documento={documento} setDocumento={setDocumento} />}<WorkflowField name="vinculo_laboral_id" label="Vínculo anterior terminado"><option value="">Selecciona el vínculo</option>{data.vinculos_anteriores.map(v => <option key={v.id} value={v.id}>{v.nombre_puesto} · {v.regimen_laboral} · fin {formatDate(v.fecha_cese || v.fin_efectivo)}</option>)}</WorkflowField><WorkflowField name="motivo" label="Motivo de la referencia" type="textarea" maxLength={2000} minLength={5} />{error && <p role="alert" className="text-rose-800">{error}</p>}<button className={boton} disabled={busy || !documento}>{busy ? 'Guardando…' : 'Registrar referencia'}</button><button type="button" className={`${boton} ml-3`} disabled={busy} onClick={onCancel}>Cancelar</button></form></DocumentoModal>
}

function DocumentoSelector({ usuario, origen, page, setPage, documento, setDocumento }) {
  const request = useLiveQuery(`/expedientes/${usuario}/archivo/${origen}/documentos?page=${page}`)
  return <><QueryStatus request={request} />{request.data && <><WorkflowField label="Documento original" value={documento} onChange={event => setDocumento(event.target.value)}><option value="">Selecciona un documento disponible</option>{request.data.data.map(doc => <option key={doc.id} value={doc.id}>{doc.tipo_documento?.nombre} · sección {doc.seccion_legajo_id} · folios {doc.folio_inicio}–{doc.folio_fin}</option>)}</WorkflowField>{!request.data.data.length && <p className="text-sm">No hay documentos disponibles para tu perfil.</p>}<WorkflowPagination data={request.data} setPage={setPage} loading={request.loading} /></>}</>
}

function TransferenciaForm({ legajo, onSaved, onCancel }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function guardar(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true); setError('')
    try { await api.post(`/archivo/legajos/${legajo.id}/transferir`, new FormData(event.currentTarget)); onSaved('Transferencia registrada con inventario e historial.') }
    catch (failure) { setError(apiErrorMessage(failure)) } finally { setBusy(false) }
  }
  return <DocumentoModal titulo={`Registrar traslado: ${legajo.numero_legajo}`} busy={busy} onCancel={onCancel}><form onSubmit={guardar} aria-busy={busy} className="space-y-4 rounded-xl border border-teal-200 p-4"><h3 className="font-semibold">Registrar traslado: {legajo.numero_legajo}</h3><p className="text-sm text-slate-600">Registra la entrega realizada y su sustento. El sistema conserva los documentos digitales.</p><WorkflowField name="fecha_transferencia" label="Fecha de transferencia" type="date" defaultValue={todayLima()} min={legajo.transferible_desde} max={todayLima()} /><WorkflowField name="ubicacion" label="Nueva ubicación" maxLength={200} /><WorkflowField name="entidad_destino" label="Archivo o entidad de destino" maxLength={200} /><WorkflowField name="numero_inventario" label="Número de inventario" maxLength={40} /><WorkflowField name="numero_documento_sustento" label="Número de documento de sustento" maxLength={60} /><WorkflowField name="referencia_microforma" label="Constancia de microforma" required={['digital', 'mixto'].includes(legajo.formato_legajo)} maxLength={200} /><WorkflowField name="inventario_pdf" label="Inventario firmado en PDF" type="file" accept="application/pdf,.pdf" /><WorkflowField name="motivo" label="Motivo y observaciones" type="textarea" minLength={5} maxLength={2000} />{error && <p role="alert" className="text-rose-800">{error}</p>}<div className="flex gap-3"><button className={boton} disabled={busy}>{busy ? 'Guardando…' : 'Registrar transferencia'}</button><button type="button" className={boton} disabled={busy} onClick={onCancel}>Cancelar</button></div></form></DocumentoModal>
}

export default function ArchivoPanel({ usuario }) {
  const [page, setPage] = useState(1)
  const request = useLiveQuery(`/expedientes/${usuario}/archivo?page=${page}`)
  const data = request.data?.data
  const puedeReferenciar = usePuede('legajo.editar')
  const admin = usePuede('sistema.administrar')
  const [traslado, setTraslado] = useState(null)
  const [referencia, setReferencia] = useState(false)
  const [visor, setVisor] = useState(null)
  const [mensaje, setMensaje] = useState('')
  const [error, setError] = useState('')
  const [descargando, setDescargando] = useState(false)
  function guardado(texto) { setMensaje(texto); setTraslado(null); setReferencia(false); request.retry() }
  async function inventario(id) {
    if (descargando) return
    setDescargando(true); setError('')
    try {
      const response = await api.get(`/archivo/movimientos/${id}/inventario`, { responseType: 'blob' })
      const url = URL.createObjectURL(response.data)
      const enlace = document.createElement('a'); enlace.href = url; enlace.download = 'inventario.pdf'; enlace.click()
      setTimeout(() => URL.revokeObjectURL(url), 1000)
    } catch { setError('No se pudo descargar el inventario. Comprueba tu acceso y vuelve a intentar.') } finally { setDescargando(false) }
  }
  return <section className="space-y-5 rounded-2xl border border-slate-200 bg-white p-6"><h2 className="text-xl font-semibold">Legajos pasivos y referencias</h2><p className="text-sm text-slate-600">Consulta los legajos anteriores y referencia documentación en el reingreso conservando su procedencia, folios y PDF original.</p><QueryStatus request={request} />{mensaje && <p role="status" className="text-teal-800">{mensaje}</p>}{error && <p role="alert" className="text-rose-800">{error}</p>}{data && <><h3 className="font-semibold">Legajos pasivos</h3>{!data.pasivos.length && <p className="text-sm">No existen legajos pasivos para esta persona.</p>}{data.pasivos.map(l => <article key={l.id} className="space-y-2 rounded-xl border border-slate-200 p-4"><p className="font-semibold">{l.numero_legajo}</p><p className="text-sm">Pasivo desde {formatDate(l.fecha_pasivo)} · {l.ubicacion || 'Ubicación sin registrar'} · {l.formato_legajo}</p>{l.fecha_transferencia_archivo_central ? <><p className="text-sm">Transferido el {formatDate(l.fecha_transferencia_archivo_central)}.</p>{admin && l.transferencia_id && <button type="button" disabled={descargando} className={boton} onClick={() => inventario(l.transferencia_id)}>Descargar inventario</button>}</> : <><p className="text-sm">Transferible desde {formatDate(l.transferible_desde)}.</p>{admin && l.transferible_desde && l.transferible_desde <= data.hoy && <button type="button" className={boton} onClick={() => setTraslado(l)}>Registrar transferencia</button>}</>}</article>)}{traslado && <TransferenciaForm key={traslado.id} legajo={traslado} onSaved={guardado} onCancel={() => setTraslado(null)} />}{data.activo && data.pasivos.length > 0 && puedeReferenciar && <><button type="button" className={boton} onClick={() => setReferencia(true)}>Referenciar documento del legajo anterior</button>{referencia && <ReferenciaForm usuario={usuario} data={data} onSaved={guardado} onCancel={() => setReferencia(false)} />}</>}<h3 className="font-semibold">Referencias conservadas</h3>{!data.referencias.data.length && <p className="text-sm">No hay referencias registradas.</p>}{data.referencias.data.map(r => <article key={r.id} className="space-y-2 rounded-xl border border-slate-200 p-4"><p className="font-semibold">{r.documento}</p><p className="text-sm">{r.origen?.numero_legajo || 'Origen histórico'} → {r.destino} · folios originales {r.folio_inicio_pasivo}–{r.folio_fin_pasivo}</p><p className="text-sm">Estado actual del original: {r.estado_actual_fuente || 'Sin enlace digital histórico'} · Responsable: {r.responsable || 'Sin dato'}</p><p className="whitespace-pre-wrap text-sm">{r.motivo}</p>{r.documento_origen_id && <button type="button" className={boton} onClick={() => setVisor({ id: r.documento_origen_id, titulo: r.documento })}>Ver PDF original</button>}</article>)}<WorkflowPagination data={data.referencias} setPage={setPage} loading={request.loading} /></>}{visor && <VisorPdf usuario={usuario} documento={visor} titulo={visor.titulo} onClose={() => setVisor(null)} />}</section>
}
