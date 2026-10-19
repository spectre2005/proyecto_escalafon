import { useState } from 'react'
import { Link } from 'react-router-dom'
import api, { apiErrorMessage } from '../../axios'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'
import { QueryStatus, WorkflowPagination } from '../../shared/WorkflowControls'
import { usePuede } from '../auth/session'

export default function AvisosPage() {
  const puede = usePuede('sistema.administrar')
  return puede ? <Avisos /> : <p role="alert">No tienes permiso para ver estos avisos.</p>
}

function Avisos() {
  const [page, setPage] = useState(1)
  const [soloPendientes, setSoloPendientes] = useState(false)
  const [busy, setBusy] = useState(null)
  const [error, setError] = useState('')
  const [reintento, setReintento] = useState(null)
  const request = useLiveQuery(`/avisos?page=${page}${soloPendientes ? '&leida=0' : ''}`)
  async function leer(id) {
    setBusy(id)
    setError('')
    try { await api.post(`/avisos/${id}/leer`); request.retry() }
    catch (failure) { setError(apiErrorMessage(failure)) }
    finally { setBusy(null) }
  }
  async function reintentar(event) {
    event.preventDefault()
    if (busy !== null) return
    const datos = Object.fromEntries(new FormData(event.currentTarget))
    setBusy(reintento); setError('')
    try { await api.post(`/avisos/${reintento}/reintentar-correo`, datos); setReintento(null); request.retry() }
    catch (failure) { setError(apiErrorMessage(failure)) }
    finally { setBusy(null) }
  }
  return <div className="space-y-5"><header><h1 className="text-2xl font-bold text-slate-900">Avisos administrativos</h1><p className="mt-2 text-sm text-slate-600">Vencimientos, requerimientos, fiscalizaciones y préstamos pendientes de revisión. Los avisos conservan la fecha en que se generaron; abre el panel para comprobar el estado actual.</p></header>
    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={soloPendientes} onChange={event => { setSoloPendientes(event.target.checked); setPage(1) }} />Solo sin leer</label>
    <QueryStatus request={request} />{error && <p role="alert" className="text-rose-800">{error}</p>}
    {reintento && <form onSubmit={reintentar} className="space-y-3 rounded-xl border border-slate-200 bg-white p-4"><label className="grid gap-2 text-sm">Motivo del reintento<input name="motivo" required minLength={5} maxLength={1000} className="rounded-lg border border-slate-300 p-3" /></label><p className="text-sm">Confirma que se corrigió la configuración o el destinatario. Solo se admite un error anterior al envío.</p><button disabled={busy !== null} className="mr-4 font-semibold text-teal-800 underline">Encolar reintento</button><button type="button" disabled={busy !== null} onClick={() => setReintento(null)}>Cancelar</button></form>}
    {request.data && <><p className="text-sm text-slate-600">Correo opcional: {request.data.correo_habilitado ? 'habilitado' : 'desactivado'}. La bandeja interna funciona independientemente de este canal.</p><p className="text-sm" role="status">{request.data.sin_leer} aviso(s) sin leer</p><ul className="space-y-3">{request.data.data.map(aviso => <li key={aviso.id} className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5"><div className="flex flex-wrap justify-between gap-2"><h2 className="font-semibold text-slate-900">{aviso.titulo}</h2><span className="text-xs text-slate-600">{aviso.leida ? 'Leído' : 'Sin leer'}</span></div><p className="text-sm text-slate-700">{aviso.mensaje}</p><p className="text-xs text-slate-500">Correo opcional: {{ pendiente: 'pendiente si el canal está habilitado', enviando: 'entrega iniciada; si persiste, verificar con el operador', enviado: 'aceptado por el transporte', error_previo: 'no enviado: requiere corregir configuración o destinatario', incierto: 'entrega incierta: verificar antes de reenviar' }[aviso.correo_estado] || 'Sin información'}{aviso.correo_error && ` · ${aviso.correo_error}`}</p><time className="block text-xs text-slate-500" dateTime={aviso.created_at}>{new Date(aviso.created_at).toLocaleString('es-PE', { timeZone: 'America/Lima' })}</time><div className="flex flex-wrap gap-4">{['/vencimientos', '/requerimientos', '/fiscalizacion', '/custodia'].includes(aviso.enlace) && <Link className="font-semibold text-teal-800 underline" to={aviso.enlace}>Abrir panel</Link>}{request.data.correo_habilitado && aviso.correo_estado === 'error_previo' && <button type="button" disabled={busy !== null} className="font-semibold text-teal-800 underline" onClick={() => setReintento(aviso.id)}>Reintentar correo</button>}{!aviso.leida && <button type="button" disabled={busy !== null} className="font-semibold text-teal-800 underline disabled:opacity-50" onClick={() => leer(aviso.id)}>{busy === aviso.id ? 'Guardando…' : 'Marcar leído'}</button>}</div></li>)}</ul>{!request.data.data.length && <p className="rounded-2xl border border-slate-200 bg-white p-6">No hay avisos para este filtro.</p>}<WorkflowPagination data={request.data} setPage={setPage} loading={request.loading} /></>}
  </div>
}
