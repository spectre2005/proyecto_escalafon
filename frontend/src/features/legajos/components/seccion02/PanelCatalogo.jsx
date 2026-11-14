import { useEffect, useRef } from 'react'
import { createPortal } from 'react-dom'

/**
 * Panel encima del formulario para agregar un elemento nuevo («+ Nuevo…») sin que ocupe espacio en la página. Es un
 * <dialog> abierto con showModal(): queda en la capa superior del navegador, por encima de otra ventana modal abierta
 * (p. ej. «Agregar personal»). Escape o «Cancelar» lo cierran sin cerrar la ventana de fondo.
 */
export default function PanelCatalogo({ titulo, onCerrar, children }) {
  const ref = useRef(null)
  useEffect(() => {
    const dialogo = ref.current
    const anterior = document.activeElement
    if (dialogo && !dialogo.open) dialogo.showModal?.()
    return () => {
      if (dialogo?.open) dialogo.close()
      anterior?.focus?.()
    }
  }, [])

  return createPortal(<dialog ref={ref} aria-label={titulo}
    onCancel={event => { event.preventDefault(); onCerrar() }}
    onMouseDown={event => { if (event.target === event.currentTarget) onCerrar() }}
    className="m-auto w-[calc(100%_-_2rem)] max-w-xl overflow-visible bg-transparent p-0 backdrop:bg-slate-900/40">
    {children}
  </dialog>, document.body)
}
