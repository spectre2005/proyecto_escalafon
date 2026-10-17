import Modal from '../../../../components/ui/Modal'
import { useState } from 'react'

// Confirmación de anulación con motivo (no se elimina). La usan las secciones 01 y 02.
export function AnularForm({ record, titulo, onCancel, onConfirm, busy }) {
  const [motivo, setMotivo] = useState('')
  return <Modal titulo="Anular registro" onClose={onCancel} busy={busy}><form aria-busy={busy} aria-label="Anular registro" onSubmit={event => { event.preventDefault(); onConfirm(motivo.trim()) }} className="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4">
    <p className="text-sm text-rose-900">Anular «{titulo(record)}». El registro no se borra: queda en el legajo marcado como anulado, con el motivo y tu nombre.</p>
    <label className="mt-3 grid gap-2 text-xs text-rose-900">Motivo de la anulación *<textarea required minLength={5} maxLength={1000} rows={2} value={motivo} onChange={event => setMotivo(event.target.value)} className="rounded-lg border border-rose-200 bg-white px-3 py-2 text-sm text-slate-800" /></label>
    <div className="mt-3 flex gap-3"><button type="button" disabled={busy} onClick={onCancel} className="rounded-lg border border-rose-200 bg-white px-3 py-2 text-sm">Cancelar</button><button disabled={busy || motivo.trim().length < 5} className="rounded-lg bg-rose-700 px-3 py-2 text-sm text-white disabled:opacity-50">{busy ? 'Anulando…' : 'Confirmar anulación'}</button></div>
  </form></Modal>
}
