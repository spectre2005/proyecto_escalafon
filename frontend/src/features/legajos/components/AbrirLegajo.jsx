import { useState } from 'react'
import { apiErrorMessage } from '../../../axios'
import { usePuede } from '../../auth/session'
import { abrirLegajo } from '../api/documentosApi'

// Abrir el legajo de una persona sin legajo activo. El número y la fecha los asigna el backend.
export default function AbrirLegajo({ usuario, className = '', legajoActivo = false }) {
  const puede = usePuede('legajo.abrir')
  const [confirmando, setConfirmando] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  if (!puede) return <p className="text-xs text-slate-600">Tu cuenta no tiene permiso para abrir legajos. Solicita la apertura a administración.</p>

  async function abrir() {
    if (busy || legajoActivo) return
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
    {legajoActivo ? <div className="space-y-2"><button type="button" disabled className="rounded-xl bg-slate-200 px-4 py-2 text-sm font-semibold text-slate-500">Abrir nuevo legajo</button><p className="max-w-lg text-xs text-slate-600">Hay un legajo activo. Primero registra el cese en la sección 11 y pulsa «Finalizar y cerrar legajo». Después se habilitará este botón.</p></div> : confirmando ? <div className="flex flex-wrap items-center gap-3 text-xs text-slate-600">
      Se abrirá un nuevo legajo activo con otro número correlativo para esta misma persona. Los legajos anteriores y sus documentos se conservarán sin reabrirlos.
      <button type="button" disabled={busy} onClick={abrir} className="rounded-lg bg-teal-700 px-3 py-1.5 font-semibold text-white disabled:opacity-50">{busy ? 'Abriendo…' : 'Confirmar apertura'}</button>
      <button type="button" disabled={busy} onClick={() => setConfirmando(false)} className="font-semibold text-slate-600">Cancelar</button>
    </div> : <button type="button" onClick={() => { setError(''); setConfirmando(true) }} className="rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white">Abrir nuevo legajo</button>}
    {error && <p role="alert" className="mt-2 text-xs text-rose-700">{error}</p>}
  </div>
}
