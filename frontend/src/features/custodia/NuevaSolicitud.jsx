import { custodiaError } from './custodiaApi'
import { useRef, useState } from 'react'
import api from '../../axios'
import Icon from '../../components/ui/Icon'
import LegajoSearch from '../../shared/LegajoSearch'
import { WorkflowField } from '../../shared/WorkflowControls'
import DocumentScopePicker from './DocumentScopePicker'
import DocumentoModal from '../legajos/components/documentos/DocumentoModal'
import { formas, contieneSensibles, nombreTitular } from './custodiaUtils'
import { button, primary, panel, FormSection } from './CustodiaUI'

export default function NuevaSolicitud({ onCreated }) {
  const trigger = useRef(null)
  const [open, setOpen] = useState(false)
  const [legajo, setLegajo] = useState(null)
  const [search, setSearch] = useState('')
  const [documents, setDocuments] = useState([])
  const [tipo, setTipo] = useState('interno')
  const [identidad, setIdentidad] = useState({ nombre: '', documento: '' })
  const [forma, setForma] = useState('consulta')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState(false)
  const sensibles = documents.some(contieneSensibles)
  function seleccionar(value) {
    setLegajo(value); setSearch(''); setDocuments([])
    if (tipo === 'titular') setIdentidad({ nombre: nombreTitular(value), documento: value?.usuario?.numero_doc_identidad || '' })
  }
  function cambiarTipo(value) {
    setTipo(value)
    if (value === 'titular') setIdentidad({ nombre: nombreTitular(legajo), documento: legajo?.usuario?.numero_doc_identidad || '' })
    else if (tipo === 'titular') setIdentidad({ nombre: '', documento: '' })
  }
  function cerrar() { setOpen(false); setError(''); requestAnimationFrame(() => trigger.current?.focus()) }
  async function submit(event) {
    event.preventDefault()
    if (busy || !legajo || !documents.length || (sensibles && tipo !== 'titular')) return
    const form = new FormData(event.currentTarget)
    const datos = { ...Object.fromEntries(form), legajo_id: legajo.id, documentos_autorizados: documents.map(doc => doc.id), incluye_datos_sensibles: form.has('incluye_datos_sensibles'), compromiso_confidencialidad: form.has('compromiso_confidencialidad') }
    setBusy(true); setError('')
    try {
      const response = await api.post('/custodia/solicitudes', datos)
      setOpen(false); setLegajo(null); setDocuments([]); setSearch(''); setTipo('interno'); setForma('consulta'); setIdentidad({ nombre: '', documento: '' }); setSuccess(true)
      onCreated(response.data.data.id); requestAnimationFrame(() => trigger.current?.focus())
    } catch (failure) { setError(custodiaError(failure)) } finally { setBusy(false) }
  }
  return <section className={panel}>
    <div className="flex flex-wrap items-center justify-between gap-4 p-5 sm:px-6"><div className="flex items-start gap-3"><span className="grid size-10 place-items-center rounded-xl bg-teal-50 text-teal-700"><Icon name="shield" /></span><div><h2 className="text-sm font-semibold text-slate-900">Nueva solicitud de acceso</h2><p className="mt-1 text-xs text-slate-500">Define quién solicita el acceso, qué documentos necesita y para qué.</p></div></div><button ref={trigger} className={primary} type="button" disabled={busy} aria-haspopup="dialog" aria-expanded={open} aria-controls="nueva-solicitud-custodia" onClick={() => { setOpen(true); setSuccess(false) }}><Icon name="plus" size={17} />Crear solicitud</button></div>
    {success && <p role="status" className="mx-5 mb-5 rounded-xl bg-teal-50 p-4 text-sm text-teal-800">Solicitud registrada. La encontrarás en el listado de pendientes para aprobar o rechazar su alcance.</p>}
    {open && <DocumentoModal titulo="Nueva solicitud de acceso" busy={busy} onCancel={cerrar}><form id="nueva-solicitud-custodia" onSubmit={submit} aria-busy={busy} className="border-t border-slate-100"><fieldset disabled={busy} className="space-y-7 p-5 sm:p-6">
      <FormSection step="1" title="Identifica el legajo" description="Busca al titular por nombre, apellidos, documento o número de expediente."><LegajoSearch search={search} legajo={legajo} onSearch={setSearch} onSelect={seleccionar} onClear={() => seleccionar(null)} /></FormSection>
      {legajo ? <>
        <FormSection step="2" title="Identifica al solicitante" description="El titular es la persona a quien pertenece el legajo; un solicitante interno o externo es un tercero."><div className="grid gap-4 sm:grid-cols-2"><WorkflowField name="tipo_solicitante" label="Tipo de solicitante" value={tipo} onChange={event => cambiarTipo(event.target.value)}><option value="interno">Interno · otra persona de la institución</option><option value="externo">Externo · otra entidad o particular</option><option value="titular">Titular del legajo</option></WorkflowField><WorkflowField name="entidad_procedencia" label="Entidad de procedencia" maxLength={200} required={false} placeholder="Ej.: Oficina de Recursos Humanos" /><WorkflowField name="solicitante_nombre" label="Nombres y apellidos del solicitante" maxLength={200} value={identidad.nombre} readOnly={tipo === 'titular'} onChange={event => setIdentidad(current => ({ ...current, nombre: event.target.value }))} /><WorkflowField name="solicitante_documento" label="Documento de identidad del solicitante" maxLength={20} value={identidad.documento} readOnly={tipo === 'titular'} onChange={event => setIdentidad(current => ({ ...current, documento: event.target.value }))} /></div>{tipo === 'titular' && <p className="rounded-lg bg-slate-50 p-3 text-xs text-slate-600">La identidad se toma del legajo seleccionado y se verifica al registrar la solicitud.</p>}</FormSection>
        <FormSection step="3" title="Selecciona los documentos" description="La autorización se limitará a los PDF que marques. Puedes seleccionar documentos de varias páginas."><DocumentScopePicker key={legajo.id} legajo={legajo} value={documents} onChange={setDocuments} tipoSolicitante={tipo} />{sensibles && tipo !== 'titular' && <p role="alert" className="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">El alcance contiene datos sensibles. Quita esos documentos o selecciona al titular del legajo como solicitante.</p>}</FormSection>
        <FormSection step="4" title="Finalidad y forma de entrega" description="Registrar una solicitud todavía no autoriza la entrega. Primero deberá aprobarse con una fecha de vigencia."><WorkflowField name="forma_entrega" label="Forma de entrega" value={forma} onChange={event => setForma(event.target.value)}>{Object.entries(formas).map(([key, text]) => <option key={key} value={key}>{text}</option>)}</WorkflowField>{forma === 'prestamo' && <p className="rounded-lg bg-sky-50 p-3 text-xs text-sky-800">Después de aprobar, registra la entrega del ejemplar y su devolución prevista. El seguimiento se realiza en Préstamos.</p>}<div className="grid gap-4 lg:grid-cols-2"><WorkflowField name="detalle_requerido" label="¿Qué necesita consultar o recibir?" type="textarea" maxLength={3000} placeholder="Describe los documentos y el propósito del acceso." /><WorkflowField name="sustento" label="Sustento de la solicitud" type="textarea" maxLength={5000} placeholder="Indica la solicitud, oficio o motivo que respalda el acceso." /></div>
          <div className="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4"><label className="flex items-start gap-3 text-sm text-slate-700"><input type="checkbox" name="incluye_datos_sensibles" required={sensibles} className="mt-1 size-4 accent-teal-700" />Declaro que el alcance incluye datos sensibles o de salud, cuando corresponda. Su entrega se limita al titular.</label><label className="flex items-start gap-3 text-sm text-slate-700"><input type="checkbox" name="compromiso_confidencialidad" required className="mt-1 size-4 accent-teal-700" />Confirmo que se ha registrado el compromiso de confidencialidad del solicitante.</label></div>
        </FormSection>
      </> : <p className="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-5 text-sm text-slate-500">Selecciona un legajo para continuar con el solicitante y los documentos.</p>}
      {error && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">{error}</p>}
      <div className="flex flex-wrap items-center justify-between gap-4 border-t border-slate-100 pt-5"><p className="text-xs text-slate-500">{legajo ? `${legajo.numero_legajo} · ${documents.length} documento(s) en el alcance · ${formas[forma]}` : 'Completa la selección del expediente.'}</p><div className="flex gap-3"><button type="button" className={button} disabled={busy} onClick={cerrar}>Cancelar</button><button className={primary} disabled={busy || !legajo || !documents.length || (sensibles && tipo !== 'titular')}>{busy ? 'Registrando…' : 'Registrar solicitud'}<Icon name="arrow" size={17} /></button></div></div>
    </fieldset></form></DocumentoModal>}
  </section>
}
