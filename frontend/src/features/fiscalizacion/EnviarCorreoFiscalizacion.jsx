import { useRef, useState } from 'react'
import api from '../../axios'
import { useManualQuery } from '../../shared/queries/useManualQuery'
import DocumentoModal from '../legajos/components/documentos/DocumentoModal'
import { boton, campo, Campo, secundario } from './componentes'
import { fuentes, nombreDocumento } from './config'
import { mensajeError } from './api'

export default function EnviarCorreoFiscalizacion({ proceso, alcance, inicial = {}, onSaved, onCancel }) {
  const conexion = useManualQuery('/fiscalizacion/correo-institucional')
  const cuenta = conexion.data?.data
  const [datos, setDatos] = useState(() => ({ documento_id: inicial.documento_id || (alcance.length === 1 ? alcance[0].documento_id : ''), entidad_consultada: inicial.entidad_consultada || (alcance.length === 1 ? alcance[0].datos_snapshot.emisor || '' : ''), fuente: inicial.fuente || 'emisor', destinatario: inicial.destinatario || '', asunto: inicial.asunto || '', cuerpo: '' }))
  const [ocupado, setOcupado] = useState(false)
  const [error, setError] = useState('')
  const [intentado, setIntentado] = useState(false)
  const [conciliar, setConciliar] = useState(false)
  const solicitud = useRef(null)
  const bloqueo = useRef(false)
  function cambiar(key) { return e => setDatos(prev => ({ ...prev, [key]: e.target.value })) }
  async function enviar(e) {
    e.preventDefault()
    if (bloqueo.current || !cuenta?.configurado || intentado) return
    bloqueo.current = true; setOcupado(true); setError(''); setIntentado(true)
    solicitud.current = { ...datos, remitente: cuenta.remitente, clave_envio: crypto.randomUUID() }
    try {
      const response = await api.post(`/fiscalizacion/procesos/${proceso.id}/correos`, solicitud.current, { timeout: 65000 })
      onSaved(response.data)
    } catch (err) {
      setError([409, 422, 502].includes(err.response?.status) ? err.response.data.message : mensajeError(err))
      if ([409, 422, 403, 404, 429].includes(err.response?.status)) setIntentado(false)
    } finally { bloqueo.current = false; setOcupado(false) }
  }
  async function comprobar() {
    if (bloqueo.current || !solicitud.current) return
    bloqueo.current = true; setOcupado(true)
    try {
      const response = await api.get(`/fiscalizacion/procesos/${proceso.id}`)
      const estado = response.data.correos?.find(c => c.id === solicitud.current.clave_envio)
      if (estado?.estado === 'enviado') onSaved({ message: 'El proveedor confirmó el envío y la consulta quedó registrada.' })
      else {
        setConciliar(!!estado?.mensaje_id)
        setError(estado?.estado === 'no_confirmado' ? estado.error : estado ? 'El envío sigue en curso o su registro está pendiente. Revisa la carpeta Enviados y el historial antes de repetirlo.' : 'No se encontró un envío registrado. Revisa la carpeta Enviados antes de volver a redactar el mensaje.')
      }
    } catch (err) { setError(mensajeError(err)) }
    finally { bloqueo.current = false; setOcupado(false) }
  }
  async function completarRegistro() {
    if (bloqueo.current || !conciliar) return
    bloqueo.current = true; setOcupado(true)
    try {
      const response = await api.post(`/fiscalizacion/procesos/${proceso.id}/correos`, { ...solicitud.current, solo_conciliar: true })
      onSaved(response.data)
    } catch (err) { setError(mensajeError(err)) }
    finally { bloqueo.current = false; setOcupado(false) }
  }
  return <DocumentoModal titulo="Enviar consulta por correo institucional" compacto busy={ocupado} onCancel={onCancel}>
    <form onSubmit={enviar} className="space-y-4">
      <p className="rounded-xl bg-teal-50 p-3 text-xs leading-relaxed text-teal-900">Este formulario sí envía un correo real desde la cuenta configurada y registra la consulta cuando el proveedor confirma el envío. Revisa el destinatario y el mensaje antes de pulsar Enviar.</p>
      {conexion.loading && <p role="status" className="text-sm text-slate-500">Comprobando cuenta institucional…</p>}
      {(conexion.error || (cuenta && !cuenta.configurado)) && <p role="alert" className="text-sm text-rose-700">La cuenta institucional no está disponible. Revisa su conexión en Configuración. <button type="button" className="underline" onClick={conexion.retry}>Comprobar otra vez</button></p>}
      <fieldset disabled={ocupado || intentado} className="grid gap-4 sm:grid-cols-2">
        <Campo titulo="Documento que se verificará" amplio><select required className={campo} value={datos.documento_id} onChange={e => { const id = e.target.value; setDatos(prev => ({ ...prev, documento_id: id, entidad_consultada: alcance.find(a => a.documento_id === id)?.datos_snapshot.emisor || '' })) }}><option value="">Selecciona el documento</option>{alcance.map(a => <option key={a.id} value={a.documento_id}>{nombreDocumento(a.datos_snapshot)}</option>)}</select></Campo>
        <Campo titulo="Entidad a la que consultas"><input required maxLength={200} className={campo} value={datos.entidad_consultada} onChange={cambiar('entidad_consultada')} /></Campo>
        <Campo titulo="Fuente"><select className={campo} value={datos.fuente} onChange={cambiar('fuente')}>{['emisor', 'sunedu', 'minedu', 'rnssc', 'otro'].map(f => <option key={f} value={f}>{fuentes[f]}</option>)}</select></Campo>
        <Campo titulo="Remitente · cuenta institucional"><input readOnly type="email" className={`${campo} bg-slate-100`} value={cuenta?.configurado ? cuenta.remitente || '' : ''} /><span className="text-xs text-slate-500">Se utiliza la cuenta conectada; no se puede reemplazar desde este formulario.</span></Campo>
        <Campo titulo="Correo del destinatario"><input required type="email" maxLength={254} className={campo} value={datos.destinatario} onChange={cambiar('destinatario')} placeholder="Correo oficial del área consultada" /></Campo>
        <Campo titulo="Asunto del correo" amplio><input required maxLength={250} className={campo} value={datos.asunto} onChange={cambiar('asunto')} /></Campo>
        <Campo titulo="Mensaje" amplio><textarea required rows={7} maxLength={10000} className={campo} value={datos.cuerpo} onChange={cambiar('cuerpo')} placeholder="Escribe la solicitud de verificación y los datos necesarios para identificar el documento." /></Campo>
      </fieldset>
      <p className="text-xs leading-relaxed text-slate-500">Se enviará el texto del mensaje, sin adjuntar archivos automáticamente. No necesitas un PDF de correo enviado para realizar este envío. Las respuestas se registran después en el proceso.</p>
      {error && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm text-rose-800">{error}</p>}
      {conciliar && <button type="button" className={secundario} disabled={ocupado} onClick={completarRegistro}>Completar registro del envío confirmado · sin reenviar</button>}
      <div className="flex flex-wrap justify-between gap-3"><button type="button" className={secundario} disabled={ocupado} onClick={onCancel}>Cancelar</button>{intentado ? <button type="button" className={secundario} disabled={ocupado} onClick={comprobar}>{ocupado ? 'Procesando…' : 'Comprobar estado del envío'}</button> : <button className={boton} disabled={ocupado || conexion.loading || !cuenta?.configurado}>Enviar correo y registrar consulta</button>}</div>
    </form>
  </DocumentoModal>
}
