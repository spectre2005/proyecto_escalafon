import { useState } from 'react'
import { apiErrorMessage } from '../../../axios'
import { puede, useSesion } from '../../auth/session'
import { reabrirLegajo } from '../api/documentosApi'

export default function ReabrirLegajo({ usuario, legajo }) {
  const sesion = useSesion()
  const [confirmando, setConfirmando] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  if (!puede(sesion, 'legajo.abrir')) return null

  async function reabrir() {
    if (busy) return
    setBusy(true)
    setError('')
    try {
      await reabrirLegajo(usuario, legajo)
      setConfirmando(false)
    } catch (failure) {
      setError(failure.response?.status === 409 ? failure.response.data.message : apiErrorMessage(failure))
    } finally {
      setBusy(false)
    }
  }

  return <div>
    {confirmando ? <div className="flex flex-wrap items-center gap-2">
      <p className="basis-full">Solo para corregir un cierre equivocado. Para un reingreso, cancela y usa «Abrir nuevo legajo». La reapertura conserva el número y los documentos.</p>
      <button type="button" disabled={busy} onClick={reabrir} className="rounded-lg bg-teal-700 px-3 py-1.5 font-semibold text-white disabled:opacity-50">{busy ? 'Reabriendo…' : 'Confirmar reapertura'}</button>
      <button type="button" disabled={busy} onClick={() => setConfirmando(false)} className="font-semibold text-teal-800">Cancelar</button>
    </div> : <button type="button" onClick={() => { setError(''); setConfirmando(true) }} className="rounded-lg border border-teal-200 bg-white px-3 py-1.5 font-semibold text-teal-800">Reabrir por corrección</button>}
    {error && <p role="alert" className="mt-2 text-rose-700">{error}</p>}
  </div>
}
