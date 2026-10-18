import { useEffect, useId, useRef } from 'react'
import { createPortal } from 'react-dom'

export default function DocumentoModal({ titulo, busy, onCancel, children }) {
  const dialog = useRef(null)
  const titleId = useId()

  useEffect(() => {
    const modal = dialog.current
    const opener = document.activeElement
    const previousOverflow = document.body.style.overflow
    modal.showModal()
    document.body.style.overflow = 'hidden'
    return () => {
      modal.close()
      document.body.style.overflow = previousOverflow
      if (opener?.isConnected) opener.focus({ preventScroll: true })
    }
  }, [])

  return createPortal(
    <dialog ref={dialog} aria-labelledby={titleId}
      onCancel={event => { event.preventDefault(); if (!busy) onCancel() }}
      className="fixed inset-0 m-auto max-h-[90dvh] w-[calc(100%_-_2rem)] max-w-6xl overflow-y-auto rounded-2xl border-0 bg-slate-50 p-4 text-slate-800 shadow-2xl backdrop:bg-slate-900/50 sm:p-6">
      <div className="mb-4 flex items-start justify-between gap-4">
        <h2 id={titleId} className="text-lg font-semibold">{titulo}</h2>
        <button type="button" disabled={busy} onClick={onCancel} aria-label="Cerrar formulario"
          className="shrink-0 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm disabled:opacity-50">Cerrar</button>
      </div>
      {children}
    </dialog>, document.body,
  )
}
