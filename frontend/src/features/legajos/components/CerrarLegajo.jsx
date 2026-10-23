import { useState } from 'react'
import { apiErrorMessage } from '../../../axios'
import { usePuede } from '../../auth/session'
import { cerrarLegajo } from '../api/documentosApi'

export default function CerrarLegajo({ usuario, legajo }) {
  const puede = usePuede('legajo.editar')
  const [confirmando, setConfirmando] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  if (!puede) return null

  async function cerrar() {
    if (busy) return
    setBusy(true)
    setError('')
    try {
      await cerrarLegajo(usuario, legajo)
      setConfirmando(false)
    } catch (failure) {
      setError(failure.response?.status === 409 ? failure.response.data.message : apiErrorMessage(failure))
    } finally {
      setBusy(false)
    }
  }

  return <div>
    {confirmando ? <div className="flex flex-wrap items-center gap-2">
      <p className="basis-full">Todos los vínculos deben tener cese registrado. El legajo quedará histórico, disponible para visualizar y descargar. En una reincorporación se abrirá otro legajo; este no se reabrirá.</p>
      <button type="button" disabled={busy} onClick={cerrar} className="rounded-lg bg-teal-700 px-3 py-1.5 font-semibold text-white disabled:opacity-50">{busy ? 'Cerrando…' : 'Confirmar cierre'}</button>
      <button type="button" disabled={busy} onClick={() => setConfirmando(false)} className="font-semibold text-teal-800">Cancelar</button>
    </div> : <button type="button" onClick={() => { setError(''); setConfirmando(true) }} className="rounded-lg border border-teal-200 bg-white px-3 py-1.5 font-semibold text-teal-800">Finalizar y cerrar legajo</button>}
    {error && <p role="alert" className="mt-2 text-rose-700">{error}</p>}
  </div>
}
