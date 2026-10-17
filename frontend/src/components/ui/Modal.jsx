import { useEffect, useId, useRef } from 'react'

let ventanasAbiertas = 0
let overflowOriginal = ''

/** Ventana común de los expedientes: foco nativo, Escape y retorno al origen. */
export default function Modal({ titulo, descripcion, onClose, busy = false, children }) {
  const dialogo = useRef(null)
  const heading = useId()
  const description = useId()
  useEffect(() => {
    const element = dialogo.current
    const origen = document.activeElement
    if (ventanasAbiertas === 0) overflowOriginal = document.body.style.overflow
    ventanasAbiertas += 1
    document.body.style.overflow = 'hidden'
    element.showModal()
    return () => {
      element.close()
      ventanasAbiertas -= 1
      if (ventanasAbiertas === 0) document.body.style.overflow = overflowOriginal
      if (origen instanceof HTMLElement && origen.isConnected) origen.focus()
    }
  }, [])
  function cerrar() {
    if (!busy && !dialogo.current.querySelector('form[aria-busy="true"]')) onClose()
  }
  return <dialog ref={dialogo} aria-labelledby={heading} aria-describedby={descripcion ? description : undefined}
    onCancel={event => { event.preventDefault(); event.stopPropagation(); cerrar() }}
    className="m-auto max-h-[92dvh] w-[min(96vw,1280px)] max-w-none overflow-y-auto rounded-2xl border border-slate-200 bg-slate-50 p-0 text-slate-800 shadow-xl backdrop:bg-slate-900/50">
    <header className="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-slate-200 bg-white px-5 py-4 sm:px-6">
      <div className="min-w-0"><h2 id={heading} className="break-words text-lg font-semibold">{titulo}</h2>{descripcion && <p id={description} className="mt-1 text-sm text-slate-500">{descripcion}</p>}</div>
      <button type="button" disabled={busy} onClick={cerrar} className="shrink-0 rounded-xl border border-teal-200 bg-teal-50 px-3 py-2 text-sm font-semibold text-teal-800 hover:bg-teal-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 disabled:opacity-50">Cerrar</button>
    </header>
    <div className="min-w-0 p-4 sm:p-6">{children}</div>
  </dialog>
}
