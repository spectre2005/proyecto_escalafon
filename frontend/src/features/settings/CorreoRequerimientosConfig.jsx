import { useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import api, { apiErrorMessage } from '../../axios'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'

const boton = 'rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-teal-800 hover:bg-teal-50 disabled:opacity-50'

export default function CorreoRequerimientosConfig() {
  const request = useLiveQuery('/correo/gmail')
  const data = request.data?.data
  const [params] = useSearchParams()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function conectar() {
    setBusy(true); setError('')
    try {
      const { data } = await api.post('/correo/gmail/autorizar')
      window.location.assign(data.url)
    } catch (e) { setError(e.response?.data?.message || apiErrorMessage(e)); setBusy(false) }
  }
  async function desconectar() {
    setBusy(true); setError('')
    try { await api.delete('/correo/gmail'); await request.retry() } catch (e) { setError(apiErrorMessage(e)) } finally { setBusy(false) }
  }
  const retorno = { conectado: 'Cuenta institucional conectada. Los envíos son manuales y las respuestas se reciben automáticamente si concediste el permiso de lectura.', cancelado: 'Cancelaste la conexión con Google. Se conserva la configuración anterior.', error: 'No se pudo conectar Gmail. Revisa las credenciales de Google y concede el permiso para enviar correos.' }[params.get('gmail')]
  return <section className="mt-6 space-y-4 rounded-2xl border border-slate-200 bg-white p-6" aria-label="Correo de requerimientos">
    <div><h2 className="font-semibold text-slate-800">Correo de requerimientos</h2><p className="mt-2 text-sm text-slate-600">Conecta la cuenta institucional desde la que se enviarán los requerimientos. Los docentes solo necesitan tener su correo registrado en Personal; no conectan su cuenta ni ingresan al sistema.</p></div>
    {retorno && <p role="status" className="rounded-xl bg-teal-50 p-3 text-sm text-teal-900">{retorno}</p>}
    {(error || request.error) && <p role="alert" className="text-sm text-rose-800">{error || apiErrorMessage(request.error)} <button className="underline" onClick={request.retry}>Reintentar</button></p>}
    {!data && !request.error && <p role="status" className="text-sm text-slate-500">Consultando conexión…</p>}
    {data && <>
      <p className="rounded-xl bg-slate-50 p-3 text-sm"><strong>{data.conectado ? 'Gmail conectado' : 'Gmail sin conectar'}</strong>{data.email && <span className="ml-2">{data.email}</span>}</p>
      {data.conectado && !data.lectura_respuestas && <p role="status" className="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">Falta autorizar la lectura de respuestas. Pulsa «Autorizar recepción automática» y concede a Google el permiso para leer mensajes y adjuntos del buzón conectado.</p>}
      {data.lectura_respuestas && <p className="text-sm text-teal-800">Recepción automática habilitada.{data.recepcion_revisada_en ? ` Última revisión: ${new Date(data.recepcion_revisada_en).toLocaleString('es-PE')}.` : ' Esperando la primera revisión.'}</p>}
      {data.recepcion_error && <p role="alert" className="text-sm text-rose-800">{data.recepcion_error}</p>}
      {!data.credenciales_configuradas && <div className="space-y-2 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950"><p>Primero hay que preparar el acceso de Google en el servidor:</p><ol className="list-decimal space-y-2 pl-5"><li>Crea un proyecto en Google Cloud y habilita Gmail API.</li><li>Configura la pantalla de consentimiento y un cliente OAuth de tipo «Aplicación web».</li><li>Registra esta URL exacta como URI de redirección autorizada: <code className="mt-1 block break-all rounded bg-white p-2">{data.redirect_uri}</code></li><li>Guarda el ID y el secreto del cliente en <code>GMAIL_CLIENT_ID</code> y <code>GMAIL_CLIENT_SECRET</code> del servidor. Después actualiza la configuración de Laravel.</li></ol><p>Si Google mantiene la aplicación en modo de prueba, añade la cuenta institucional como usuario de prueba.</p></div>}
      {data.credenciales_configuradas && <p className="text-xs text-slate-600">URL de retorno que debe coincidir con la configurada en Google: <code className="break-all">{data.redirect_uri}</code></p>}
      <p className="text-sm text-slate-600">Conectar la cuenta permite enviar desde Requerimientos al pulsar Registrar y enviar. Los pendientes antiguos no se envían automáticamente. Las respuestas con la referencia del requerimiento se importan cada minuto junto con sus PDF. Recibirlas no envía correos al docente ni aprueba los documentos.</p>
      <div className="flex flex-wrap gap-3"><button className={boton} disabled={busy || !data.credenciales_configuradas} onClick={conectar}>{busy ? 'Procesando…' : data.conectado ? (data.lectura_respuestas ? 'Cambiar cuenta de Gmail' : 'Autorizar recepción automática') : 'Conectar Gmail institucional'}</button>{data.email && <button className={boton} disabled={busy} onClick={desconectar}>Desconectar Gmail y detener envíos</button>}</div>
    </>}
  </section>
}
