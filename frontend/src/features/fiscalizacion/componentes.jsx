import { useState } from 'react'
import api from '../../axios'
import { mensajeError } from './api'
import { estados, resultados, tecnicos, nombreDocumento } from './config'

export const panel = 'rounded-2xl border border-slate-200 bg-white p-5 sm:p-6'
export const campo = 'w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 outline-none focus:ring-2 focus:ring-teal-600 disabled:opacity-60'
export const boton = 'rounded-xl bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50'
export const secundario = 'rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50'
export function Campo({ titulo, children, amplio = false }) { return <label className={`grid content-start gap-2 text-xs font-medium text-slate-600 ${amplio ? 'sm:col-span-2' : ''}`}>{titulo}{children}</label> }
export function Estado({ value }) {
  const alerta = ['observado', 'no_autentico', 'bloqueado', 'no_disponible'].includes(value)
  return <span className={`inline-block rounded-full px-3 py-1 text-xs font-medium ${alerta ? 'bg-rose-50 text-rose-800' : ['pendiente', 'en_revision', 'no_concluyente', 'no_configurado'].includes(value) ? 'bg-amber-50 text-amber-800' : 'bg-teal-50 text-teal-800'}`}>{estados[value] || resultados[value] || tecnicos[value] || value}</span>
}
export function DocumentosSelect({ documentos, name = 'evidencia_documento_id', excluir, required = true }) {
  return <select required={required} name={name} className={campo}><option value="">Seleccionar documento del legajo</option>{documentos.filter(doc => doc.id !== excluir).map(doc => <option key={doc.id} value={doc.id}>{nombreDocumento(doc)}</option>)}</select>
}
export function ErrorConsulta({ request }) {
  if (!request.error) return null
  return <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">{request.error.response?.status === 404 ? 'El proceso no existe o no tienes acceso a sus documentos.' : mensajeError(request.error)} <button className="font-semibold underline" onClick={request.retry}>Reintentar</button></div>
}
export function Formulario({ url, children, preparar, onSaved, onBusyChange, disabled = false, titulo = 'Guardar registro' }) {
  const [guardando, setGuardando] = useState(false)
  const [error, setError] = useState('')
  async function enviar(event) {
    event.preventDefault()
    if (guardando || disabled) return
    const datos = Object.fromEntries(new FormData(event.currentTarget))
    setGuardando(true); onBusyChange?.(true); setError('')
    try {
      const response = await api.post(url, preparar ? preparar(datos) : datos)
      onSaved(response.data)
    } catch (err) { setError(mensajeError(err)) } finally { setGuardando(false); onBusyChange?.(false) }
  }
  return <form onSubmit={enviar} className="grid gap-4"><fieldset disabled={guardando || disabled} className="grid gap-4 sm:grid-cols-2">{children}</fieldset>{error && <p role="alert" className="text-sm text-rose-700">{error}</p>}<button disabled={guardando || disabled} className={`${boton} justify-self-start`}>{guardando ? 'Guardando…' : titulo}</button></form>
}
export function Servicios({ servicios = [] }) {
  return <details className={panel}><summary className="cursor-pointer text-sm font-semibold text-slate-700">Fuentes y servicios de verificación</summary><p className="mt-3 text-xs leading-relaxed text-slate-500">Las consultas técnicas aportan evidencia. La evaluación y el cierre corresponden a las personas autorizadas. Un servicio no disponible o una consulta sin coincidencias no acredita falsedad.</p><div className="mt-4 grid gap-3 sm:grid-cols-2">{servicios.map(servicio => <div key={servicio.codigo} className="rounded-xl border border-slate-200 p-4"><div className="flex flex-wrap justify-between gap-2"><h3 className="text-sm font-semibold">{servicio.nombre}</h3><span className="text-xs text-slate-500">{{ disponible: 'Disponible', configurado: 'Configurado por TI', no_configurado: 'Sin configurar', consulta_asistida: 'Consulta asistida' }[servicio.estado]}</span></div><p className="mt-2 text-xs leading-relaxed text-slate-500">{servicio.descripcion}</p>{servicio.url && <a className="mt-3 inline-block text-xs font-semibold text-teal-700 underline" href={servicio.url} target="_blank" rel="noreferrer">Abrir fuente oficial ↗</a>}</div>)}</div></details>
}
