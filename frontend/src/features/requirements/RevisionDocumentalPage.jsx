import { useState } from 'react'
import { Link } from 'react-router-dom'
import api, { apiErrorMessage } from '../../axios'
import { useManualQuery } from '../../shared/queries/useManualQuery'
import DocumentoModal from '../legajos/components/documentos/DocumentoModal'
import { legajoSections } from '../legajos/config/legajoSections'
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
  const [vista, setVista] = useState('por_revisar')
  const [detalle, setDetalle] = useState(null)
  const [seccion, setSeccion] = useState('')
  const [revision, setRevision] = useState(null)
  const [fedateo, setFedateo] = useState(null)
  const administra = usePuede('sistema.administrar')
  const [visor, setVisor] = useState(null)
  const request = useManualQuery(`/revision-documental?${new URLSearchParams({ page, q: term, estado, seccion, vista })}`)
  const revisar = (_usuario, id, datos) => api.post(`/revision-documental/${id}`, datos)
  return <div className="space-y-5"><header><h1 className="text-2xl font-bold text-slate-900">Revisión documental</h1><p className="mt-2 text-sm text-slate-600">Documentos vigentes de legajos activos. Solo cuentan como completos los recepcionados y aceptados o verificados. La subsanación conserva el PDF anterior y se carga desde la ficha.</p></header>
    <div className="rounded-2xl border border-teal-100 bg-teal-50 p-4 text-sm text-teal-900">1. Filtra los pendientes → 2. Abre el documento y comprueba el PDF → 3. Acepta o indica qué debe subsanarse. Administración puede cargar y revisar documentos; cada operación queda en el historial.</div>
    <form className="flex flex-wrap items-end gap-3" onSubmit={event => { event.preventDefault(); setTerm(q.trim()); setPage(1) }}><label className="grid gap-1 text-sm">Persona o documento de identidad<input className="rounded-lg border border-slate-300 p-2" maxLength={120} value={q} onChange={event => setQ(event.target.value)} /></label><label className="grid gap-1 text-sm">Estado<select className="rounded-lg border border-slate-300 p-2" value={estado} onChange={event => { setEstado(event.target.value); setVista(''); setPage(1) }}><option value="">Todos</option>{['pendiente', 'cargado', 'aceptado', 'verificado', 'observado', 'en_fiscalizacion'].map(value => <option key={value} value={value}>{value.replaceAll('_', ' ')}</option>)}</select></label><label className="grid gap-1 text-sm">Sección<select className="rounded-lg border border-slate-300 p-2" value={seccion} onChange={event => { setSeccion(event.target.value); setPage(1) }}><option value="">Todas</option>{legajoSections.filter(item => Number(item.number) <= 12).map(item => <option key={item.number} value={Number(item.number)}>{item.number} · {item.title}</option>)}</select></label><button className={boton}>Buscar</button><button type="button" className={boton} onClick={() => { setQ(''); setTerm(''); setEstado(''); setSeccion(''); setVista('por_revisar'); setPage(1) }}>Limpiar filtros</button><button type="button" className={boton} onClick={request.retry}>Actualizar</button></form>
    <QueryStatus request={request} />{request.data && <><section aria-label="Resumen de persona y sección" className="grid grid-cols-2 gap-3 lg:grid-cols-4">{[['documentos', '', 'Todos', 'bg-white text-slate-800'], ['por_revisar', 'por_revisar', 'Por revisar', 'bg-white text-slate-800'], ['completos', 'completos', 'Completos', 'bg-teal-50 text-teal-900'], ['observados', 'observados', 'Por subsanar', 'bg-amber-50 text-amber-900']].map(([key, value, label, color]) => <button key={key} type="button" aria-pressed={!estado && vista === value} onClick={() => { setVista(value); setEstado(''); setPage(1) }} className={`rounded-xl border p-4 text-left transition hover:border-teal-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 ${!estado && vista === value ? 'border-teal-600 ring-2 ring-teal-600' : 'border-slate-200'} ${color}`}><span className="block text-xs font-medium">{label}</span><span className="mt-1 block text-2xl font-bold">{request.data.resumen[key]}</span></button>)}</section><p className="text-xs text-slate-600">Pulsa un cuadro para filtrar los documentos de la persona y sección seleccionadas.</p><p role="status" className="text-sm font-semibold text-slate-800">{request.data.total} documento(s) en la vista actual</p><p className="text-xs text-slate-500">Se muestran primero las incorporaciones más antiguas. «Con archivo» no equivale a «Completo»: se requiere recepción y aceptación o verificación.</p><div className="space-y-3">{request.data.data.map(doc => <article key={doc.id} className="flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-4"><div className="min-w-0 flex-1"><p className="text-xs font-semibold text-teal-800">Sección {String(doc.seccion_legajo_id).padStart(2, '0')} · {doc.usuario.numero_doc_identidad}</p><h2 className="mt-1 font-semibold">{doc.tipo.nombre}</h2><p className="text-sm text-slate-600">{[doc.usuario.nombres, doc.usuario.apellido_paterno, doc.usuario.apellido_materno].filter(Boolean).join(' ')}</p><div className="mt-2 flex flex-wrap gap-2 text-xs"><span className="rounded-full bg-slate-100 px-2 py-1">{doc.estado.replaceAll('_', ' ')}</span><span className={`rounded-full px-2 py-1 ${doc.completo ? 'bg-teal-50 text-teal-900' : 'bg-amber-50 text-amber-900'}`}>{doc.completo ? 'Completo' : doc.estado === 'observado' ? 'Requiere subsanación' : doc.fecha_recepcion ? 'Recepcionado · revisión pendiente' : 'Sin recepción'}</span><span className="px-2 py-1">Folios {doc.folio_inicio}–{doc.folio_fin}</span></div>{doc.revision_propia_bloqueada && <p className="mt-2 text-xs text-amber-900">Tu carga: debe revisarla otro administrador.</p>}</div><button type="button" className={boton} onClick={() => { setDetalle(doc); setRevision(null); setFedateo(null); setVisor(null) }}>Abrir documento</button></article>)}</div>{!request.data.data.length && <p className="rounded-2xl border bg-white p-6">No hay documentos para estos filtros.</p>}<WorkflowPagination data={request.data} setPage={setPage} loading={request.loading} /></>}
    {detalle && <DocumentoModal titulo={detalle.tipo.nombre} onCancel={() => setDetalle(null)}>
      <p className="mb-4 text-sm text-slate-600">{[detalle.usuario.nombres, detalle.usuario.apellido_paterno, detalle.usuario.apellido_materno].filter(Boolean).join(' ')} · Sección {detalle.seccion_legajo_id} · Folios {detalle.folio_inicio}–{detalle.folio_fin} · {detalle.estado.replaceAll('_', ' ')}</p>
      <div className="mb-4 flex flex-wrap gap-2"><button type="button" className={boton} onClick={() => setVisor(detalle.id)}>Ver PDF</button><Link className={boton} to={`/legajos/${detalle.usuario_id}`}>Abrir ficha / subsanar</Link>{administra && <button type="button" className={boton} onClick={() => { setFedateo(detalle.id); setRevision(null) }}>Registrar fedateo con sustento</button>}{!detalle.revision_propia_bloqueada && <>{!['aceptado', 'verificado', 'observado'].includes(detalle.estado) && <button type="button" className={boton} onClick={() => { setRevision({ estado: 'aceptado' }); setFedateo(null) }}>Aceptar</button>}{detalle.estado !== 'observado' && <button type="button" className={boton} onClick={() => { setRevision({ estado: 'observado' }); setFedateo(null) }}>Observar</button>}</>}</div>
      {detalle.revision_propia_bloqueada && <p className="mb-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">No puedes aceptar ni observar una carga propia. Solicita la revisión a otro administrador.</p>}
      {revision && <Revision03Form key={revision.estado} usuario={detalle.usuario_id} documento={{ ...detalle, estado_original: detalle.estado }} item={detalle.tipo} estado={revision.estado} fechaActual={request.data?.hoy} revisar={revisar} onCancel={() => setRevision(null)} onSaved={() => { setDetalle(null); setRevision(null); request.retry() }} />}
      {fedateo && <FedateoForm doc={detalle} hoy={request.data?.hoy} onCancel={() => setFedateo(null)} onSaved={() => { setDetalle(null); setFedateo(null); request.retry() }} />}
      {administra && <WorkflowHistory entity="documentos_usuario" id={detalle.id} />}
      {visor && <VisorPdf usuario={detalle.usuario_id} documento={detalle} titulo={detalle.tipo.nombre} onClose={() => setVisor(null)} />}
    </DocumentoModal>}
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
