import { useState } from 'react'
import { apiErrorMessage } from '../../../axios'
import { usePuede } from '../../auth/session'
import { abrirLegajo } from '../api/documentosApi'

// Abrir el legajo de una persona sin legajo activo. El número y la fecha los asigna el backend.
export default function AbrirLegajo({ usuario, className = '' }) {
  const puede = usePuede('legajo.abrir')
  const [confirmando, setConfirmando] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  if (!puede) return null

  async function abrir() {
    if (busy) return
    setBusy(true)
    setError('')
    try {
      await abrirLegajo(usuario)
      setConfirmando(false)
    } catch (failure) {
      setError(failure.response?.status === 409 ? failure.response.data.message : apiErrorMessage(failure))
    } finally {
      setBusy(false)
    }
  }

  return <div className={className}>
    {confirmando ? <div className="flex flex-wrap items-center gap-3 text-xs text-slate-600">
      Se creará el legajo activo con sus 13 secciones y un número correlativo.
      <button type="button" disabled={busy} onClick={abrir} className="rounded-lg bg-teal-700 px-3 py-1.5 font-semibold text-white disabled:opacity-50">{busy ? 'Abriendo…' : 'Confirmar apertura'}</button>
      <button type="button" disabled={busy} onClick={() => setConfirmando(false)} className="font-semibold text-slate-600">Cancelar</button>
    </div> : <button type="button" onClick={() => { setError(''); setConfirmando(true) }} className="rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white">Abrir legajo</button>}
    {error && <p role="alert" className="mt-2 text-xs text-rose-700">{error}</p>}
  </div>
}
