import { useEffect, useId, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import api from '../../axios'
import Icon from '../../components/ui/Icon'
import { useManualQuery } from '../../shared/queries/useManualQuery'
import { QueryStatus, WorkflowField, WorkflowPagination } from '../../shared/WorkflowControls'
import { formatDate } from '../../shared/dates'
import VisorPdf from '../legajos/components/documentos/VisorPdf'
import { personName, stateLabels, availableActions, isClosed, isOverdue, effectiveDeadline, extensionMin } from './requirementUtils'
import { requirementError } from './requirementApi'
import { primary, button, RequirementState, EmptyRequirements } from './RequirementUI'
export { default as NewRequirement } from './NewRequirement'

const actionNames = { responder: 'Presentar respuesta / subsanar', cumplir: 'Aceptar respuesta y cumplir', observar: 'Observar respuesta', editar: 'Corregir descripción', prorrogar: 'Conceder prórroga', cancelar: 'Cancelar requerimiento' }
const actionHelp = { responder: 'Selecciona un PDF ya incorporado al legajo activo de esta persona. Debe corresponder al tipo documental solicitado.', cumplir: 'Confirma que revisaste la respuesta. El requerimiento quedará cumplido y cerrado.', observar: 'Explica qué debe corregirse. Después podrá presentarse una nueva respuesta.', editar: 'Puedes corregir la descripción mientras no exista una respuesta asociada.', prorrogar: 'La nueva fecha debe ser posterior al plazo efectivo actual. El estado del trámite se conserva.', cancelar: 'El requerimiento quedará cerrado como cancelado. Su información e historial se conservan.' }

function DocumentOptions({ record, selected, onSelect }) {
  const [page, setPage] = useState(1)
  const [visor, setVisor] = useState(null)
  const request = useManualQuery(`/requerimientos/${record.id}/documentos?page=${page}`)
  return <section className="space-y-3 rounded-xl border border-slate-200 p-4"><div className="flex flex-wrap items-center justify-between gap-3"><h4 className="text-sm font-semibold text-slate-800">Documento de respuesta</h4><button type="button" className={button} onClick={request.retry}>Actualizar documentos</button></div><p className="text-xs text-slate-500">Destinatario: {personName(record.usuario)} · {record.tipo_documento?.nombre || 'Solicitud general sin tipo específico'}</p><Link className="inline-block text-sm font-semibold text-teal-800 underline" to={`/legajos/${record.usuario_id}`} target="_blank" rel="noopener noreferrer">Abrir ficha para cargar o subsanar un PDF ↗</Link>
    <QueryStatus request={request} />
    {selected && <p role="status" className="rounded-lg bg-teal-50 p-3 text-sm text-teal-800">Seleccionado: {selected.nombre || selected.tipo_documento?.nombre} · {selected.num_resolucion || 'Sin número'} · {formatDate(selected.fecha_emision)}</p>}
    {request.data && <>{!request.data.data.length ? <EmptyRequirements title="No hay documentos admisibles">Carga el PDF desde la ficha del destinatario, en el mismo tipo solicitado y su legajo activo. Después vuelve aquí y pulsa Actualizar documentos. Un PDF de otra persona no aparecerá.</EmptyRequirements> : <fieldset className="max-h-72 space-y-2 overflow-auto"><legend className="sr-only">Selecciona un documento existente como respuesta</legend>{request.data.data.map(doc => <div key={doc.id} className={`flex items-start gap-3 rounded-xl border p-3 ${selected?.id === doc.id ? 'border-teal-300 bg-teal-50/50' : 'border-slate-200'}`}><label className="flex min-w-0 flex-1 cursor-pointer items-start gap-3"><input type="radio" name="documento_seleccionado" value={doc.id} checked={selected?.id === doc.id} onChange={() => onSelect(doc)} className="mt-1 size-4 accent-teal-700" /><span className="min-w-0"><span className="block text-sm font-semibold text-slate-800">{doc.nombre || doc.tipo_documento?.nombre}</span><span className="mt-1 block text-xs text-slate-500">{doc.num_resolucion || 'Sin número'} · {formatDate(doc.fecha_emision)} · {doc.estado}</span>{doc.folio_inicio && <span className="mt-1 block text-xs text-slate-500">Sección {doc.seccion_legajo_id} · Folios {doc.folio_inicio}–{doc.folio_fin}</span>}</span></label><button type="button" aria-label={`Ver PDF ${doc.nombre || doc.tipo_documento?.nombre}`} className="rounded-lg p-2 text-teal-700 hover:bg-teal-50" onClick={() => setVisor(doc)}><Icon name="eye" size={18} /></button></div>)}</fieldset>}<WorkflowPagination data={request.data} setPage={setPage} loading={request.loading} /></>}
    {visor && <VisorPdf usuario={record.usuario_id} documento={visor} titulo={visor.nombre || visor.tipo_documento?.nombre} onClose={() => setVisor(null)} />}
  </section>
}

function History({ id }) {
  const [page, setPage] = useState(1)
  const request = useManualQuery(`/requerimientos/${id}/historial?page=${page}`)
  return <section className="space-y-3 rounded-xl border border-slate-200 p-4"><h3 className="text-sm font-semibold text-slate-900">Historial del requerimiento</h3><QueryStatus request={request} />{request.data && <><ol className="space-y-4">{request.data.data.map(event => <li key={event.id} className="border-l-2 border-teal-200 pl-4 text-sm"><div className="flex flex-wrap items-center justify-between gap-2"><p className="font-semibold text-slate-800">{{ crear: 'Solicitud registrada', responder: 'Respuesta presentada', observar: 'Respuesta observada', cumplir: 'Requerimiento cumplido', prorrogar: 'Prórroga concedida', cancelar: 'Requerimiento cancelado', editar: 'Descripción corregida' }[event.accion.replace('requerimiento.', '')] || event.accion}</p><time className="text-xs text-slate-500" dateTime={event.fecha}>{new Date(event.fecha).toLocaleString('es-PE', { timeZone: 'America/Lima' })}</time></div><p className="mt-1 text-xs text-slate-500">{personName(event.actor)}</p>{event.despues?.motivo && <p className="mt-2 whitespace-pre-wrap break-words text-slate-700">{event.despues.motivo}</p>}<p className="mt-2 text-xs text-slate-500">{stateLabels[event.antes?.estado] || 'Nuevo'} → {stateLabels[event.despues?.estado] || event.despues?.estado} · Límite: {formatDate(event.despues?.prorroga_hasta || event.despues?.fecha_limite)}</p></li>)}</ol>{!request.data.data.length && <p className="text-sm text-slate-500">No hay operaciones registradas en este historial. Las operaciones antiguas no se reconstruyen.</p>}<WorkflowPagination data={request.data} setPage={setPage} loading={request.loading} /></>}</section>
}

function ManagementForm({ initial, onChanged, onBusy }) {
  const [record, setRecord] = useState(initial)
  const [action, setAction] = useState('')
  const [documento, setDocumento] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [visor, setVisor] = useState(null)
  async function refresh() {
    try { const response = await api.get(`/requerimientos/${record.id}`); setRecord(response.data.data); setAction(''); setDocumento(null); setError(''); onChanged() }
    catch (failure) { setError(requirementError(failure)) }
  }
  async function submit(event) {
    event.preventDefault()
    if (busy) return
    const datos = { ...Object.fromEntries(new FormData(event.currentTarget)), accion: action, version: record.version }
    delete datos.documento_seleccionado
    if (action === 'responder') datos.documento_id = documento?.id
    setBusy(true); onBusy(true); setError(''); setSuccess('')
    try { await api.post(`/requerimientos/${record.id}/acciones`, datos); setSuccess('Operación registrada. El estado y el historial se actualizaron.'); await refresh() }
    catch (failure) { setError(requirementError(failure)) } finally { setBusy(false); onBusy(false) }
  }
  return <div className="space-y-5">
    <div className="rounded-xl border border-slate-200 bg-slate-50/70 p-4"><div className="flex flex-wrap items-start justify-between gap-3"><div><p className="text-xs text-slate-500">Destinatario</p><h3 className="mt-1 font-semibold text-slate-900">{personName(record.usuario)}</h3><p className="mt-1 text-xs text-slate-500">Documento: {record.usuario?.numero_doc_identidad || 'Sin dato'}</p></div><RequirementState state={record.estado} /></div><p className="mt-4 text-sm font-semibold text-slate-800">{record.tipo_documento?.nombre || 'Solicitud general sin tipo documental específico'}</p><p className="mt-2 whitespace-pre-wrap break-words text-sm text-slate-600">{record.descripcion}</p><Link to={`/legajos/${record.usuario_id}`} target="_blank" rel="noopener noreferrer" className="mt-3 inline-block text-xs font-semibold text-teal-800 underline">Abrir ficha del destinatario ↗</Link></div>
    <dl className="grid gap-4 text-sm sm:grid-cols-3">{[['Fecha de solicitud', formatDate(record.fecha_requerimiento)], ['Plazo efectivo', formatDate(effectiveDeadline(record))], ['Origen', record.origen || 'Sin registrar']].map(([title, text]) => <div key={title}><dt className="text-xs text-slate-500">{title}</dt><dd className="mt-1 font-medium text-slate-800">{text}</dd></div>)}</dl>{record.prorroga_hasta && <p className="text-xs text-slate-500">Fecha límite original: {formatDate(record.fecha_limite)} · Prórroga hasta {formatDate(record.prorroga_hasta)}.</p>}{isOverdue(record) && <p className="rounded-xl bg-rose-50 p-3 text-sm text-rose-800">El plazo efectivo ya pasó y el requerimiento sigue sin cerrar.</p>}
    {record.documento_id && <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-teal-100 bg-teal-50/40 p-4"><div><h3 className="text-sm font-semibold text-teal-900">Respuesta vinculada</h3><p className="mt-1 text-xs text-teal-800">{record.documento?.nombre || record.documento?.num_resolucion || 'Documento del legajo'} · {record.documento?.estado || 'Estado no disponible'}</p></div><button type="button" className={button} onClick={() => setVisor({ id: record.documento_id })}>Ver PDF<Icon name="eye" size={16} /></button></div>}
    {success && <p role="status" className="rounded-xl bg-teal-50 p-4 text-sm text-teal-800">{success}</p>}{error && <p role="alert" className="rounded-xl bg-rose-50 p-4 text-sm text-rose-800">{error} <button type="button" className="font-semibold underline" disabled={busy} onClick={refresh}>Actualizar requerimiento</button></p>}
    {isClosed(record) ? <p className="rounded-xl bg-slate-50 p-4 text-sm text-slate-600">Este requerimiento está cerrado. Puedes consultar la respuesta y el historial; no admite nuevas acciones.</p> : <section className="space-y-4 rounded-xl border border-slate-200 p-4"><h3 className="text-sm font-semibold text-slate-900">¿Qué necesitas hacer?</h3><div className="flex flex-wrap gap-2">{availableActions(record).map(key => <button key={key} type="button" className={action === key ? primary : button} aria-pressed={action === key} disabled={busy} onClick={() => { setAction(key); setDocumento(null); setError(''); setSuccess('') }}>{actionNames[key]}</button>)}</div>{action && <form key={`${record.version}:${action}`} onSubmit={submit} aria-busy={busy}><fieldset disabled={busy} className="space-y-4 border-t border-slate-100 pt-4"><p className="text-sm text-slate-600">{actionHelp[action]}</p>
      {action === 'editar' && <WorkflowField name="descripcion" label="Descripción corregida" type="textarea" defaultValue={record.descripcion} maxLength={300} />}
      {action === 'responder' && <DocumentOptions record={record} selected={documento} onSelect={setDocumento} />}
      {action === 'prorrogar' && <WorkflowField name="prorroga_hasta" label="Nueva fecha límite" type="date" min={extensionMin(record)} />}
      {['editar', 'observar', 'prorrogar', 'cancelar'].includes(action) && <WorkflowField name="motivo" label={action === 'observar' ? '¿Qué debe corregirse en la respuesta?' : 'Motivo de la operación'} type="textarea" maxLength={1000} />}
      <div className="flex flex-wrap gap-3"><button className={primary} disabled={busy || (action === 'responder' && !documento)}>{busy ? 'Guardando…' : { responder: 'Presentar documento como respuesta', cumplir: 'Confirmar cumplimiento', observar: 'Guardar observación', editar: 'Guardar corrección', prorrogar: 'Confirmar prórroga', cancelar: 'Confirmar cancelación' }[action]}</button><button type="button" className={button} disabled={busy} onClick={() => setAction('')}>Cancelar operación</button></div>
    </fieldset></form>}</section>}
    <History key={record.version} id={record.id} />
    {visor && <VisorPdf usuario={record.usuario_id} documento={visor} titulo="Documento de respuesta" onClose={() => setVisor(null)} />}
  </div>
}

export function RequirementManagement({ id, onClose, onChanged }) {
  const dialog = useRef(null)
  const titulo = useId()
  const [locked, setLocked] = useState(false)
  const request = useManualQuery(`/requerimientos/${id}`)
  useEffect(() => { if (!dialog.current.open) dialog.current.showModal() }, [])
  return <dialog ref={dialog} onClose={onClose} onCancel={event => { if (locked) event.preventDefault() }} aria-labelledby={titulo} className="m-auto max-h-[90dvh] w-[calc(100%_-_2rem)] max-w-4xl overflow-y-auto rounded-2xl border-0 bg-white p-0 shadow-xl backdrop:bg-slate-900/45"><header className="sticky top-0 z-10 flex items-center justify-between gap-3 border-b border-slate-200 bg-white px-5 py-4 sm:px-6"><div><p className="text-[10px] font-semibold uppercase tracking-wider text-teal-700">Seguimiento documental</p><h2 id={titulo} className="mt-1 text-lg font-semibold text-slate-900">Gestionar requerimiento e historial</h2></div><button type="button" aria-label="Cerrar detalle del requerimiento" disabled={locked} onClick={() => dialog.current.close()} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 disabled:opacity-40"><Icon name="close" /></button></header><div className="p-5 sm:p-6"><QueryStatus request={request} />{request.data && <ManagementForm initial={request.data.data} onBusy={setLocked} onChanged={onChanged} />}</div></dialog>
}
