import { useId, useRef, useState } from 'react'
import api from '../../axios'
import Icon from '../../components/ui/Icon'
import LegajoSearch from '../../shared/LegajoSearch'
import { useManualQuery } from '../../shared/queries/useManualQuery'
import { QueryStatus, WorkflowField } from '../../shared/WorkflowControls'
import { todayLima, formatDate } from '../../shared/dates'
import { personName } from './requirementUtils'
import { requirementError } from './requirementApi'
import { panel, primary, button, input, RequirementSection } from './RequirementUI'
import DocumentoModal from '../legajos/components/documentos/DocumentoModal'

function DocumentType({ request, value, onChange }) {
  const [mostrarTodos, setMostrarTodos] = useState(false)
  const types = request.data?.data || []
  const registrados = types.filter(type => type.documentos_registrados > 0)
  const visible = mostrarTodos ? types : registrados
  const selected = types.find(type => type.id === value)
  const groups = [
    ['Ya registrados', visible.filter(type => type.documentos_registrados > 0)],
    ['Sin documentos registrados', visible.filter(type => !type.documentos_registrados)],
  ]
  const nombre = type => `${type.nombre}${type.documentos_registrados > 0 ? ` · ${type.documentos_registrados} registrado(s)` : ''}`
  return <div className="space-y-2"><QueryStatus request={request} />{request.data && <>
    <label className="grid gap-2 text-sm text-slate-700">Tipo documental<select name="tipo_documento_id" className={input} value={value} onChange={event => onChange(event.target.value)}><option value="">Solicitud general · sin tipo específico</option>{selected && !visible.some(type => type.id === selected.id) && <option value={selected.id}>{nombre(selected)}</option>}{groups.filter(([, items]) => items.length).map(([label, items]) => <optgroup key={label} label={label}>{items.map(type => <option key={type.id} value={type.id}>{nombre(type)}</option>)}</optgroup>)}</select></label>
    <div className="flex flex-wrap items-center justify-between gap-2 text-xs"><span className="text-slate-500">{registrados.length ? 'Tipos registrados en el legajo de la persona.' : 'Esta persona aún no tiene documentos disponibles.'}</span><button type="button" aria-expanded={mostrarTodos} className="font-semibold text-teal-800 underline" onClick={() => setMostrarTodos(current => !current)}>{mostrarTodos ? 'Ver solo registrados' : 'Solicitar otro tipo'}</button></div>
  </>}</div>
}

function CreateForm({ onCreated, onCancel, onBusy }) {
  const [search, setSearch] = useState('')
  const [person, setPerson] = useState(null)
  const types = useManualQuery(person ? `/requerimientos/tipos?usuario_id=${encodeURIComponent(person.id)}` : null)
  const [tipo, setTipo] = useState('')
  const [origen, setOrigen] = useState('legajo')
  const [descripcion, setDescripcion] = useState('')
  const [solicitud, setSolicitud] = useState(todayLima)
  const [limite, setLimite] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const selectedType = types.data?.data.find(type => type.id === tipo)
  async function submit(event) {
    event.preventDefault()
    if (busy || !person || !types.data) return
    const datos = { ...Object.fromEntries(new FormData(event.currentTarget)), usuario_id: person.id }
    datos.tipo_documento_id ||= null
    setBusy(true); onBusy(true); setError('')
    try { const response = await api.post('/requerimientos', datos); onCreated(response.data.data.id) }
    catch (failure) { setError(requirementError(failure)) } finally { setBusy(false); onBusy(false) }
  }
  return <form onSubmit={submit} aria-busy={busy} className="border-t border-slate-100"><fieldset disabled={busy} className="space-y-7 p-5 sm:p-6">
    <RequirementSection number="1" title="Selecciona al destinatario"><LegajoSearch search={search} legajo={person} mode="persona" endpoint="/requerimientos/personas" onSearch={setSearch} onSelect={value => { setPerson(value); setSearch(''); setTipo('') }} onClear={() => { setPerson(null); setSearch(''); setTipo('') }} /></RequirementSection>
    {person ? <>
      <RequirementSection number="2" title="Define el documento y la solicitud"><DocumentType key={person.id} request={types} value={tipo} onChange={setTipo} /><div className="grid gap-3 sm:grid-cols-2"><WorkflowField name={origen === 'otro' ? undefined : 'origen'} label="Origen de la solicitud" value={origen} onChange={event => setOrigen(event.target.value)}><option value="legajo">Legajo</option><option value="fiscalizacion">Fiscalización</option><option value="otro">Otro origen…</option></WorkflowField>{origen === 'otro' && <WorkflowField name="origen" label="Indica el origen" maxLength={15} placeholder="Máximo 15 caracteres" />}</div><label className="grid gap-2 text-sm text-slate-700">¿Qué debe presentar la persona?<textarea name="descripcion" required maxLength={300} rows={3} value={descripcion} onChange={event => setDescripcion(event.target.value)} className={input} placeholder="Describe el documento solicitado y el motivo de la solicitud." /></label><div className="flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500"><span>{descripcion.length}/300 caracteres</span>{selectedType && <button type="button" className="font-semibold text-teal-800 underline" onClick={() => setDescripcion(`Presentar ${selectedType.nombre.toLowerCase()} para completar la documentación del legajo.`.slice(0, 300))}>Usar descripción sugerida</button>}</div></RequirementSection>
      <RequirementSection number="3" title="Establece las fechas"><div className="grid gap-4 sm:grid-cols-2"><WorkflowField name="fecha_requerimiento" label="Fecha de solicitud" type="date" value={solicitud} max={todayLima()} onChange={event => setSolicitud(event.target.value)} /><WorkflowField name="fecha_limite" label="Fecha límite de entrega" type="date" min={solicitud || undefined} value={limite} onChange={event => setLimite(event.target.value)} /></div><p className="rounded-xl bg-slate-50 p-3 text-xs text-slate-600">Selecciona una fecha límite explícita. El sistema no calcula feriados ni días hábiles automáticamente.</p></RequirementSection>
    </> : <p className="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-5 text-sm text-slate-500">Selecciona a la persona para continuar con el documento y las fechas de entrega.</p>}
    {error && <p role="alert" className="rounded-xl bg-rose-50 p-4 text-sm text-rose-800">{error}</p>}
    <div className="flex flex-wrap items-center justify-between gap-4 border-t border-slate-100 pt-5"><p className="min-w-0 flex-1 text-xs leading-relaxed text-slate-500">{person ? <>{personName(person)} · {selectedType?.nombre || 'Solicitud general'}{limite && <> · Límite: {formatDate(limite)}</>}</> : 'La solicitud quedará registrada como Abierta.'}</p><div className="flex gap-3"><button type="button" className={button} disabled={busy} onClick={onCancel}>Cancelar</button><button className={primary} disabled={busy || !person || !types.data}>{busy ? 'Registrando…' : 'Registrar requerimiento'}<Icon name="arrow" size={17} /></button></div></div>
  </fieldset></form>
}

export default function NewRequirement({ onCreated }) {
  const id = useId()
  const trigger = useRef(null)
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [success, setSuccess] = useState(false)
  function cerrar() { setOpen(false); requestAnimationFrame(() => trigger.current?.focus()) }
  return <section className={panel}><div className="flex flex-wrap items-center justify-between gap-4 p-5 sm:px-6"><div className="flex items-start gap-3"><span className="grid size-10 place-items-center rounded-xl bg-teal-50 text-teal-700"><Icon name="file" /></span><div><h2 className="text-sm font-semibold text-slate-900">Nueva solicitud documental</h2><p className="mt-1 text-xs text-slate-500">Indica a quién solicitarás un documento y cuándo deberá entregarlo.</p></div></div><button ref={trigger} type="button" className={primary} disabled={busy} aria-haspopup="dialog" aria-expanded={open} aria-controls={id} onClick={() => { setOpen(true); setSuccess(false) }}><Icon name="plus" size={17} />Nuevo requerimiento</button></div>{success && <p role="status" className="mx-5 mb-5 rounded-xl bg-teal-50 p-4 text-sm text-teal-800">Requerimiento registrado. Puedes continuar su seguimiento desde el detalle.</p>}{open && <DocumentoModal titulo="Nueva solicitud documental" busy={busy} onCancel={cerrar}><div id={id}><CreateForm onBusy={setBusy} onCancel={cerrar} onCreated={registro => { cerrar(); setSuccess(true); onCreated(registro) }} /></div></DocumentoModal>}</section>
}
