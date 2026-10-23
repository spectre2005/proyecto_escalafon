import { useEffect, useRef, useState } from 'react'
import api from '../../axios'
import DocumentoModal from '../legajos/components/documentos/DocumentoModal'
import { Campo, campo, secundario } from './componentes'
import { fuentes, hoy, nombreDocumento } from './config'
import { mensajeError } from './api'
import { cambiarMedioConsulta, mediosConsulta } from './mediosConsulta'
import { datosConsultaIniciales, propuestasConsulta } from './consultaEmisor'

const etiquetasComunes = { entidad_consultada: 'Entidad consultada', fuente: 'Fuente', medio: 'Medio', numero_oficio: 'Número de oficio o referencia', fecha_envio: 'Fecha de envío o consulta' }
const medios = { oficio: 'Oficio', correo: 'Correo institucional', registro_oficial: 'Registro oficial' }

export default function ConsultaEmisorCampos({ proceso, alcance, documentos, onBusy, onCompose }) {
  const [datos, setDatos] = useState(() => datosConsultaIniciales(alcance, documentos))
  const [leyendo, setLeyendo] = useState(false)
  const [abierto, setAbierto] = useState(false)
  const [lectura, setLectura] = useState(null)
  const [seleccion, setSeleccion] = useState({})
  const [confirmado, setConfirmado] = useState(false)
  const [error, setError] = useState('')
  const peticion = useRef(null)
  useEffect(() => () => peticion.current?.abort(), [])
  const inicio = proceso.fecha_inicio.slice(0, 10)
  const config = mediosConsulta[datos.medio]
  const etiquetas = { ...etiquetasComunes, ...Object.fromEntries(Object.entries(mediosConsulta[lectura?.campos.medio || datos.medio]?.campos || {}).map(([key, field]) => [key, field.label])) }
  const comprobante = documentos.find(d => d.id === datos.evidencia_documento_id)
  const pareceTitulo = comprobante && /t[ií]tulo|licenciado|bachiller|ingenier|certificado.*estudios/i.test(comprobante.nombre || '') && !/oficio|correo|consulta|constancia|reporte|captura|cargo/i.test(comprobante.nombre || '')
  const propuesta = propuestasConsulta(lectura?.campos || {}, inicio, hoy())
  const evidencias = documentos.filter(d => d.id !== datos.documento_id)
  function cambiar(key, value) { setDatos(prev => ({ ...prev, [key]: value })) }
  async function reconocer() {
    if (leyendo || !datos.documento_id || !datos.evidencia_documento_id) return
    setLeyendo(true); onBusy(true); setAbierto(true); setError(''); setLectura(null); setConfirmado(false)
    const controller = new AbortController()
    peticion.current = controller
    try {
      const response = await api.post(`/fiscalizacion/procesos/${proceso.id}/consultas/reconocer`, { documento_id: datos.documento_id, evidencia_documento_id: datos.evidencia_documento_id }, { timeout: 60000, signal: controller.signal, sinAvisoCambios: true })
      if (controller.signal.aborted) return
      setLectura(response.data.data)
      setSeleccion(Object.fromEntries(Object.keys(propuestasConsulta(response.data.data.campos, inicio, hoy())).map(key => [key, true])))
    } catch (err) { if (!controller.signal.aborted) setError(err.response?.status === 503 ? err.response.data.message : mensajeError(err)) }
    finally { if (!controller.signal.aborted) { setLeyendo(false); onBusy(false) } }
  }
  return <>
    <p className="rounded-xl bg-teal-50 p-3 text-xs leading-relaxed text-teal-900 sm:col-span-2">Se propone el documento cuando hay uno solo y su entidad emisora. Selecciona la constancia enviada para reconocer la referencia, el medio, el destinatario y la fecha que figuren en ella.</p>
    <Campo titulo="Documento incluido en el alcance" amplio><select required name="documento_id" className={campo} value={datos.documento_id} onChange={e => {
      const id = e.target.value
      const seleccionado = alcance.find(a => a.documento_id === id)
      setDatos(prev => ({ ...prev, documento_id: id, entidad_consultada: seleccionado?.datos_snapshot.emisor || '', fuente: 'emisor', evidencia_documento_id: prev.evidencia_documento_id === id ? '' : prev.evidencia_documento_id }))
      setLectura(null)
    }}><option value="">Seleccionar</option>{alcance.map(a => <option key={a.id} value={a.documento_id}>{nombreDocumento(a.datos_snapshot)}</option>)}</select></Campo>
    <Campo titulo="Entidad consultada"><input required maxLength={200} name="entidad_consultada" className={campo} value={datos.entidad_consultada} onChange={e => cambiar('entidad_consultada', e.target.value)} /></Campo>
    <Campo titulo="Fuente"><select name="fuente" className={campo} value={datos.fuente} onChange={e => cambiar('fuente', e.target.value)}>{['emisor', 'sunedu', 'minedu', 'rnssc', 'otro'].map(f => <option key={f} value={f}>{fuentes[f]}</option>)}</select></Campo>
    <Campo titulo="Medio"><select required name="medio" className={campo} value={datos.medio} onChange={e => setDatos(prev => cambiarMedioConsulta(prev, e.target.value))}><option value="">Seleccionar</option>{Object.entries(medios).map(([key, value]) => <option key={key} value={key}>{value}</option>)}</select></Campo>
    <Campo titulo={config?.referencia || 'Número de oficio o referencia'}><input required={datos.medio === 'oficio'} maxLength={40} name="numero_oficio" className={campo} value={datos.numero_oficio} onChange={e => cambiar('numero_oficio', e.target.value)} /></Campo>
    {datos.medio === 'correo' && onCompose && <div className="rounded-xl border border-teal-200 bg-white p-3 sm:col-span-2"><p className="mb-3 text-xs text-slate-600">¿Todavía no enviaste el correo? Puedes redactarlo y enviarlo desde la cuenta institucional conectada.</p><button type="button" className={secundario} onClick={() => onCompose(datos)}>Redactar y enviar correo</button></div>}
    {config && <><div className="rounded-xl border border-teal-200 bg-white p-3 sm:col-span-2"><h3 className="text-sm font-semibold text-teal-900">Datos de {config.nombre.toLowerCase()}</h3><p className="mt-2 text-xs leading-relaxed text-slate-600">{config.ayuda}</p></div>{Object.entries(config.campos).map(([key, field]) => <Campo key={`${datos.medio}:${key}`} titulo={field.label} amplio={field.amplio}>{field.opciones ? <select required={!field.opcional} name={key} className={campo} value={datos[key] || ''} onChange={e => cambiar(key, e.target.value)}><option value="">Seleccionar</option>{Object.entries(field.opciones).map(([code, label]) => <option key={code} value={code}>{label}</option>)}</select> : field.textarea ? <textarea required={!field.opcional} name={key} rows={3} maxLength={field.max} className={campo} value={datos[key] || ''} onChange={e => cambiar(key, e.target.value)} /> : <input required={!field.opcional} type={field.type || 'text'} name={key} maxLength={field.max} className={campo} value={datos[key] || ''} onChange={e => cambiar(key, e.target.value)} />}</Campo>)}</>}
    <Campo titulo={config?.fecha || 'Fecha de envío o consulta'}><input required type="date" name="fecha_envio" min={inicio} max={hoy()} className={campo} value={datos.fecha_envio} onChange={e => cambiar('fecha_envio', e.target.value)} /></Campo>
    <Campo titulo={config?.evidencia || 'Oficio o constancia de consulta incorporada'}><select required name="evidencia_documento_id" className={campo} value={datos.evidencia_documento_id} onChange={e => { cambiar('evidencia_documento_id', e.target.value); setLectura(null) }}><option value="">Seleccionar documento del legajo</option>{evidencias.map(d => <option key={d.id} value={d.id}>{nombreDocumento(d)}</option>)}</select></Campo>
    {pareceTitulo && <p role="alert" className="rounded-xl bg-amber-50 p-3 text-xs text-amber-900 sm:col-span-2">El archivo seleccionado parece ser un título o certificado de estudios. Selecciona la evidencia de la comunicación o consulta realizada, en lugar de otro documento académico.</p>}
    <div className="space-y-2 sm:col-span-2"><button type="button" disabled={leyendo || !datos.documento_id || !datos.evidencia_documento_id} className={secundario} onClick={reconocer}>Reconocer datos de la constancia</button><p className="text-xs text-slate-500">No se inventan referencias ni fechas. La lectura es local y no consume créditos. Revisa el destinatario real antes de guardar.</p></div>
    <p className="rounded-xl bg-amber-50 p-3 text-xs leading-relaxed text-amber-900 sm:col-span-2">Este formulario registra una consulta ya realizada. No envía correos ni oficios. Para reiterar, registra otra actuación con su propia constancia.</p>
    {abierto && <DocumentoModal titulo="Datos detectados de la consulta" compacto busy={leyendo} onCancel={() => setAbierto(false)}>
      {leyendo && <p role="status" className="p-4 text-sm text-teal-800">Leyendo oficio o constancia…</p>}
      {error && <p role="alert" className="p-4 text-sm text-rose-700">{error}</p>}
      {lectura && <div className="space-y-3">
        {lectura.avisos.map((aviso, i) => <p key={i} className="rounded-lg bg-amber-50 p-3 text-xs text-amber-900">{aviso}</p>)}
        {Object.entries(etiquetas).map(([key, label]) => <div key={key} className="rounded-xl border border-slate-200 bg-white p-3 text-sm"><label className="flex items-start gap-2"><input type="checkbox" disabled={!propuesta[key]} checked={!!seleccion[key] && !!propuesta[key]} onChange={e => { setSeleccion(prev => ({ ...prev, [key]: e.target.checked })); setConfirmado(false) }} /><span><strong className="block">{label}</strong><span className="mt-1 block text-slate-600">{(key === 'fuente' ? fuentes[propuesta[key]] : key === 'medio' ? medios[propuesta[key]] : mediosConsulta[lectura.campos.medio || datos.medio]?.campos[key]?.opciones?.[propuesta[key]] || propuesta[key]) || (lectura.campos[key] ? 'Fuera del periodo del proceso: revisar manualmente' : 'No detectado: completar manualmente')}</span>{propuesta[key] && datos[key] && datos[key] !== propuesta[key] && <span className="mt-1 block text-xs text-amber-800">Reemplazará: {datos[key]}</span>}</span></label>{lectura.evidencias[key] && <details className="mt-2 text-xs text-slate-500"><summary>Texto de referencia</summary><p className="mt-2 whitespace-pre-wrap">{lectura.evidencias[key]}</p></details>}</div>)}
        <details className="text-xs text-slate-600"><summary>Texto completo leído</summary><pre className="mt-2 max-h-64 overflow-auto whitespace-pre-wrap font-sans">{lectura.texto}</pre></details>
        <label className="flex gap-2 text-xs"><input type="checkbox" checked={confirmado} onChange={e => setConfirmado(e.target.checked)} />Revisé los datos y corresponden a la consulta enviada.</label>
        <button type="button" className={secundario} disabled={!confirmado || !Object.keys(propuesta).some(key => seleccion[key])} onClick={() => { setDatos(prev => ({ ...(seleccion.medio && propuesta.medio ? cambiarMedioConsulta(prev, propuesta.medio) : prev), ...Object.fromEntries(Object.entries(propuesta).filter(([key]) => seleccion[key])) })); setAbierto(false) }}>Aplicar al formulario</button>
      </div>}
    </DocumentoModal>}
  </>
}
