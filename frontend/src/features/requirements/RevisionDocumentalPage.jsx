import { useState } from 'react'
import { Link } from 'react-router-dom'
import api, { apiErrorMessage } from '../../axios'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'
import { DocumentPicker, QueryStatus, WorkflowField, WorkflowHistory, WorkflowPagination } from '../../shared/WorkflowControls'
import { usePuede } from '../auth/session'
import Revision03Form from '../legajos/components/seccion03/Revision03Form'
import VisorPdf from '../legajos/components/documentos/VisorPdf'

const boton = 'rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-teal-800 disabled:opacity-50'
export default function RevisionDocumentalPage() {
  const puede = usePuede('documento.revisar')
  return puede ? <Bandeja /> : <p role="alert">No tienes permiso para revisar documentos.</p>
}
function Bandeja() {
  const [page, setPage] = useState(1)
  const [q, setQ] = useState('')
  const [term, setTerm] = useState('')
  const [estado, setEstado] = useState('')
  const [seccion, setSeccion] = useState('')
  const [revision, setRevision] = useState(null)
  const [fedateo, setFedateo] = useState(null)
  const administra = usePuede('sistema.administrar')
  const [visor, setVisor] = useState(null)
  const request = useLiveQuery(`/revision-documental?${new URLSearchParams({ page, q: term, estado, seccion })}`)
  const revisar = (_usuario, id, datos) => api.post(`/revision-documental/${id}`, datos)
  return <div className="space-y-5"><header><h1 className="text-2xl font-bold text-slate-900">Revisión documental</h1><p className="mt-2 text-sm text-slate-600">Documentos vigentes de legajos activos. Solo cuentan como completos los recepcionados y aceptados o verificados. La subsanación conserva el PDF anterior y se carga desde la ficha.</p></header>
    <form className="flex flex-wrap items-end gap-3" onSubmit={event => { event.preventDefault(); setTerm(q.trim()); setPage(1) }}><label className="grid gap-1 text-sm">Persona o documento de identidad<input className="rounded-lg border border-slate-300 p-2" maxLength={120} value={q} onChange={event => setQ(event.target.value)} /></label><label className="grid gap-1 text-sm">Estado<select className="rounded-lg border border-slate-300 p-2" value={estado} onChange={event => { setEstado(event.target.value); setPage(1) }}><option value="">Todos</option>{['pendiente', 'cargado', 'aceptado', 'verificado', 'observado', 'en_fiscalizacion'].map(value => <option key={value} value={value}>{value.replaceAll('_', ' ')}</option>)}</select></label><label className="grid gap-1 text-sm">Sección<select className="rounded-lg border border-slate-300 p-2" value={seccion} onChange={event => { setSeccion(event.target.value); setPage(1) }}><option value="">Todas</option>{Array.from({ length: 12 }, (_, index) => <option key={index + 1}>{index + 1}</option>)}</select></label><button className={boton}>Buscar</button></form>
    <QueryStatus request={request} />{request.data && <><p className="text-sm" role="status">Documentos: {request.data.resumen.documentos} · Completos: {request.data.resumen.completos} · Observados: {request.data.resumen.observados} · Sin recepción: {request.data.resumen.sin_recepcion}</p><div className="space-y-3">{request.data.data.map(doc => <article key={doc.id} className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5"><h2 className="font-semibold">{doc.tipo.nombre}</h2><p className="text-sm">{[doc.usuario.nombres, doc.usuario.apellido_paterno, doc.usuario.apellido_materno].filter(Boolean).join(' ')} · Sección {doc.seccion_legajo_id} · Folios {doc.folio_inicio}–{doc.folio_fin} · {doc.estado}</p><div className="flex flex-wrap gap-3"><button type="button" className={boton} onClick={() => setVisor(visor === doc.id ? null : doc.id)}>Ver PDF</button><Link className={boton} to={`/legajos/${doc.usuario_id}`}>Abrir ficha / subsanar</Link>{administra && <button type="button" className={boton} onClick={() => setFedateo(doc.id)}>Registrar fedateo con sustento</button>}{!['aceptado', 'verificado', 'observado'].includes(doc.estado) && <button type="button" className={boton} onClick={() => setRevision({ id: doc.id, estado: 'aceptado' })}>Aceptar</button>}{doc.estado !== 'observado' && <button type="button" className={boton} onClick={() => setRevision({ id: doc.id, estado: 'observado' })}>Observar</button>}</div>{fedateo === doc.id && <FedateoForm key={`${doc.id}:${doc.fedateo_sustento_documento_id || ''}`} doc={doc} hoy={request.data.hoy} onCancel={() => setFedateo(null)} onSaved={() => { setFedateo(null); request.retry() }} />}{administra && <WorkflowHistory entity="documentos_usuario" id={doc.id} />}{visor === doc.id && <VisorPdf key={doc.id} usuario={doc.usuario_id} documento={doc} titulo={doc.tipo.nombre} onClose={() => setVisor(null)} />}{revision?.id === doc.id && <Revision03Form key={`${doc.id}:${doc.estado}:${revision.estado}`} usuario={doc.usuario_id} documento={{ ...doc, estado_original: doc.estado }} item={doc.tipo} estado={revision.estado} fechaActual={request.data.hoy} revisar={revisar} onCancel={() => setRevision(null)} onSaved={() => { setRevision(null); request.retry() }} />}</article>)}</div>{!request.data.data.length && <p className="rounded-2xl border bg-white p-6">No hay documentos para estos filtros.</p>}<WorkflowPagination data={request.data} setPage={setPage} loading={request.loading} /></>}
  </div>
}

function FedateoForm({ doc, hoy, onSaved, onCancel }) {
  const [sustento, setSustento] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function guardar(event) {
    event.preventDefault()
    if (!sustento || busy) { if (!sustento) setError('Selecciona el PDF de sustento.'); return }
    const datos = Object.fromEntries(new FormData(event.currentTarget))
    setBusy(true)
    setError('')
    try {
      await api.post(`/revision-documental/${doc.id}/fedatear`, { ...datos, sustento_documento_id: sustento, sustento_anterior_id: doc.fedateo_sustento_documento_id || null, estado_anterior: doc.estado })
      onSaved()
    } catch (failure) { setError(apiErrorMessage(failure)) }
    finally { setBusy(false) }
  }
  return <form onSubmit={guardar} aria-busy={busy} className="space-y-4 rounded-xl border border-slate-200 p-4"><h3 className="font-semibold">Fedateo con sustento documental</h3><p className="text-sm text-slate-600">Carga primero el sustento desde la ficha. Debe ser otro PDF del mismo legajo. Se conservan los archivos y el historial de los cambios.</p><DocumentPicker legajo={doc.legajo_id} value={sustento} onChange={setSustento} label="PDF de sustento obligatorio" /><WorkflowField name="fecha_fedateo" label="Fecha del fedateo" type="date" max={hoy} defaultValue={hoy} /><WorkflowField name="motivo" label="Motivo / registro del contraste" type="textarea" maxLength={1000} />{error && <p role="alert" className="text-rose-800">{error}</p>}<div className="flex gap-3"><button disabled={busy} className={boton}>{busy ? 'Guardando…' : 'Guardar fedateo'}</button><button type="button" disabled={busy} onClick={onCancel} className={boton}>Cancelar</button></div></form>
}
