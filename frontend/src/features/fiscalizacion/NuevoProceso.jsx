import { useRef, useState } from 'react'
import api from '../../axios'
import { useManualQuery } from '../../shared/queries/useManualQuery'
import DocumentoModal from '../legajos/components/documentos/DocumentoModal'
import { boton, campo, Campo, ErrorConsulta, secundario } from './componentes'
import { fecha, hoy, nombreDocumento, persona } from './config'
import { mensajeError } from './api'

const pasos = ['Persona y documentos', 'Motivo y fechas', 'Revisar y abrir']
const tipos = { dirigida: 'Dirigida, con motivo', integral: 'Integral', aleatoria: 'Aleatoria, con acta externa' }

export default function NuevoProceso({ onSaved, onCancel }) {
  const [paso, setPaso] = useState(0)
  const [busqueda, setBusqueda] = useState('')
  const [seleccionado, setSeleccionado] = useState(null)
  const [documentos, setDocumentos] = useState([])
  const [datos, setDatos] = useState({ tipo_muestra: 'dirigida', fecha_vinculacion: '', fecha_inicio: hoy(), fecha_limite: '', motivo: '', fundamento: '' })
  const [ocupado, setOcupado] = useState(false)
  const guardando = useRef(false)
  const [error, setError] = useState('')
  const encabezado = useRef(null)
  const legajo = seleccionado?.id || ''
  const opciones = useManualQuery(`/fiscalizacion/opciones?q=${encodeURIComponent(busqueda)}${legajo ? `&legajo_id=${legajo}` : ''}`, 250)
  const disponibles = opciones.data?.documentos || []
  const puedeElegir = doc => !!doc.archivo && !['anulado', 'falso'].includes(doc.estado) && doc.archivo.estado_antivirus !== 'infectado'
  function cambiar(key) { return event => setDatos(prev => ({ ...prev, [key]: event.target.value })) }
  function ir(destino) {
    setError(''); setPaso(destino)
    requestAnimationFrame(() => encabezado.current?.focus())
  }
  async function continuar(event) {
    event.preventDefault()
    if (guardando.current) return
    if (paso === 0) {
      if (!legajo || !documentos.length) { setError('Selecciona una persona y al menos un documento para revisar.'); return }
      if (opciones.loading || opciones.error) { setError('Espera a que terminen de cargar los documentos o reintenta la consulta.'); return }
      ir(1); return
    }
    if (paso === 1) { ir(2); return }
    guardando.current = true; setOcupado(true); setError('')
    try {
      const response = await api.post('/fiscalizacion/procesos', { ...datos, motivo: datos.motivo.trim(), fundamento: datos.fundamento.trim(), legajo_id: legajo, documentos })
      onSaved(response.data.data)
    } catch (err) {
      const campos = err.response?.data?.errors || {}
      if (Object.keys(campos).some(key => key === 'legajo_id' || key.startsWith('documentos'))) setPaso(0)
      else if (Object.keys(campos).length) setPaso(1)
      setError(mensajeError(err))
    } finally { guardando.current = false; setOcupado(false) }
  }
  return <DocumentoModal titulo="Abrir proceso de fiscalización" compacto busy={ocupado} onCancel={onCancel}>
    <p className="mb-5 text-sm text-slate-500">Elige qué revisar y confirma los datos. Tú serás el responsable del proceso.</p>
    <ol aria-label="Pasos para abrir el proceso" className="mb-6 grid grid-cols-3 gap-2">{pasos.map((titulo, index) => <li key={titulo} aria-current={paso === index ? 'step' : undefined} className={`rounded-xl border p-3 text-xs ${paso === index ? 'border-teal-600 bg-teal-50 text-teal-900' : 'border-slate-200 bg-white text-slate-500'}`}><span className={`mb-2 flex size-6 items-center justify-center rounded-full font-bold ${paso === index ? 'bg-teal-700 text-white' : 'bg-slate-100 text-slate-600'}`}>{index + 1}</span>{titulo}</li>)}</ol>
    <h3 ref={encabezado} tabIndex={-1} className="mb-4 text-base font-semibold text-slate-800 outline-none">{pasos[paso]}</h3>
    <form onSubmit={continuar} className="space-y-5">
      <fieldset disabled={ocupado} className="grid min-w-0 gap-4 sm:grid-cols-2">
        {paso === 0 && <>
          <Campo titulo="Buscar persona o número de legajo" amplio><input className={campo} maxLength={120} value={busqueda} onChange={e => { setBusqueda(e.target.value); setSeleccionado(null); setDocumentos([]) }} placeholder="Escribe un nombre, apellido o número de legajo" /></Campo>
          <Campo titulo="Persona y legajo activo" amplio><select required className={campo} value={legajo} disabled={opciones.loading} onChange={e => { setSeleccionado(opciones.data?.legajos.find(l => l.id === e.target.value) || null); setDocumentos([]); setError('') }}><option value="">Selecciona una persona</option>{(opciones.data?.legajos || []).map(l => <option key={l.id} value={l.id}>{persona(l.usuario)} · {l.numero_legajo}</option>)}</select></Campo>
          <div className="sm:col-span-2"><ErrorConsulta request={opciones} />
            {opciones.loading && <p role="status" className="py-4 text-sm text-slate-500">{legajo ? 'Cargando documentos…' : 'Buscando personas…'}</p>}
            {legajo && !opciones.loading && !opciones.error && <><div className="mb-3 flex flex-wrap items-center justify-between gap-2"><h4 className="text-sm font-semibold text-slate-700">¿Qué documentos revisarás?</h4><span className="text-xs font-semibold text-teal-800">{documentos.length} seleccionados</span></div><div className="max-h-64 overflow-y-auto rounded-xl border border-slate-200 bg-white">{disponibles.map(doc => <label key={doc.id} className="flex items-start gap-3 border-b border-slate-100 p-3 text-sm last:border-b-0"><input type="checkbox" className="mt-1 accent-teal-700" checked={documentos.includes(doc.id)} disabled={!puedeElegir(doc) || (!documentos.includes(doc.id) && documentos.length >= 100)} onChange={e => setDocumentos(prev => e.target.checked ? [...prev, doc.id] : prev.filter(id => id !== doc.id))} /><span className="min-w-0"><span className="block break-words font-medium text-slate-800">{nombreDocumento(doc)}</span><span className="mt-1 block text-xs text-slate-500">{doc.emisor || 'Emisor no registrado'}{!puedeElegir(doc) && ' · No disponible para seleccionar'}</span></span></label>)}{!disponibles.length && <p className="p-4 text-sm text-slate-500">No hay documentos disponibles. Incorpóralos primero al legajo.</p>}</div><p className="mt-2 text-xs text-slate-500">Selecciona de 1 a 100 documentos. Sus datos y archivos quedarán como referencia de la revisión.</p></>}
          </div>
        </>}
        {paso === 1 && <>
          <div className="rounded-xl bg-teal-50 p-3 text-sm text-teal-900 sm:col-span-2"><strong>{persona(seleccionado?.usuario)}</strong><p className="mt-1 text-xs">{seleccionado?.numero_legajo} · {documentos.length} documento(s) para revisar</p></div>
          <Campo titulo="¿Qué revisarás y por qué?" amplio><textarea required maxLength={3000} className={campo} rows={3} value={datos.motivo} onChange={cambiar('motivo')} placeholder="Ej.: Verificar el título presentado para acreditar la formación del puesto." /></Campo>
          <Campo titulo="Disposición o referencia que sustenta la revisión" amplio><input required maxLength={3000} className={campo} value={datos.fundamento} onChange={cambiar('fundamento')} placeholder="Número de directiva, disposición, acta o expediente real" /></Campo>
          <Campo titulo="Fecha de ingreso del servidor"><input required className={campo} type="date" max={datos.fecha_inicio || hoy()} value={datos.fecha_vinculacion} onChange={cambiar('fecha_vinculacion')} /></Campo>
          <Campo titulo="Inicio de la revisión"><input required className={campo} type="date" min={datos.fecha_vinculacion || undefined} max={hoy()} value={datos.fecha_inicio} onChange={cambiar('fecha_inicio')} /></Campo>
          <Campo titulo="Fecha límite para terminar" amplio><input required className={campo} type="date" min={datos.fecha_inicio || undefined} value={datos.fecha_limite} onChange={cambiar('fecha_limite')} /><span className="text-xs font-normal text-slate-500">Usa el plazo definido para este proceso por tu institución.</span></Campo>
          <details className="rounded-xl border border-slate-200 bg-white p-3 sm:col-span-2"><summary className="cursor-pointer text-xs font-semibold text-slate-600">Tipo de selección: {tipos[datos.tipo_muestra]}</summary><div className="mt-3"><Campo titulo="Cómo se seleccionó este caso"><select className={campo} value={datos.tipo_muestra} onChange={cambiar('tipo_muestra')}>{Object.entries(tipos).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></Campo><p className="mt-2 text-xs text-slate-500">La selección aleatoria necesita acta y regla institucional. El sistema no realiza sorteos.</p></div></details>
        </>}
        {paso === 2 && <>
          <div className="rounded-xl border border-slate-200 bg-white p-4 sm:col-span-2"><p className="text-xs text-slate-500">Persona y legajo</p><p className="mt-1 font-semibold text-slate-800">{persona(seleccionado?.usuario)}</p><p className="mt-1 text-sm text-slate-600">{seleccionado?.numero_legajo}</p><h4 className="mb-2 mt-4 text-xs font-semibold text-slate-600">Documentos incluidos ({documentos.length})</h4><ul className="max-h-40 space-y-2 overflow-y-auto text-sm text-slate-700">{disponibles.filter(doc => documentos.includes(doc.id)).map(doc => <li key={doc.id}>{nombreDocumento(doc)}</li>)}</ul></div>
          <dl className="grid gap-4 rounded-xl border border-slate-200 bg-white p-4 text-sm sm:col-span-2 sm:grid-cols-2">{[['Ingreso del servidor', fecha(datos.fecha_vinculacion)], ['Inicio', fecha(datos.fecha_inicio)], ['Fecha límite', fecha(datos.fecha_limite)], ['Selección', tipos[datos.tipo_muestra]]].map(([label, value]) => <div key={label}><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-1 font-medium text-slate-700">{value}</dd></div>)}</dl>
          <div className="space-y-3 rounded-xl bg-white p-4 text-sm sm:col-span-2"><div><h4 className="text-xs font-semibold text-slate-500">Motivo</h4><p className="mt-1 whitespace-pre-wrap break-words text-slate-700">{datos.motivo}</p></div><div><h4 className="text-xs font-semibold text-slate-500">Referencia institucional</h4><p className="mt-1 whitespace-pre-wrap break-words text-slate-700">{datos.fundamento}</p></div></div>
          <p className="rounded-xl bg-teal-50 p-3 text-xs leading-relaxed text-teal-900 sm:col-span-2">Al confirmar, se abrirá el proceso a tu cargo. Las consultas a fuentes y la evaluación de documentos se realizan después.</p>
        </>}
      </fieldset>
      {error && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm text-rose-800">{error}</p>}
      <div className="sticky bottom-0 flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 py-4"><button type="button" className={secundario} disabled={ocupado} onClick={() => paso === 0 ? onCancel() : ir(paso - 1)}>{paso === 0 ? 'Cancelar' : 'Anterior'}</button><button className={boton} disabled={ocupado || (paso === 0 && (!legajo || !documentos.length || opciones.loading || !!opciones.error))}>{ocupado ? 'Abriendo proceso…' : paso === 2 ? 'Confirmar y abrir proceso' : 'Continuar'}</button></div>
    </form>
  </DocumentoModal>
}
