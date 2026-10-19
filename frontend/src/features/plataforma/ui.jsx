import { useEffect, useId, useRef } from 'react'
import { TARJETA, boton } from './estilos'

/**
 * Tarjeta de una sección del panel: título, descripción o detalle opcionales y acciones a la derecha.
 * detalle: contenido bajo el título (indicadores, textos con su propio formato).
 */
export function Seccion({ titulo, descripcion, detalle, acciones, children, className = 'mt-6', ...props }) {
  const id = useId()
  return <section aria-labelledby={id} className={`${TARJETA} ${className}`} {...props}>
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div className="min-w-0">
        <h2 id={id} className="font-semibold text-slate-800">{titulo}</h2>
        {descripcion && <p className="mt-1 text-xs text-slate-500">{descripcion}</p>}
        {detalle}
      </div>
      {acciones && <div className="flex flex-wrap items-center gap-2">{acciones}</div>}
    </div>
    {children}
  </section>
}

/**
 * Diálogo modal con un formulario: título, contenido (el aviso de error lo pone cada uno donde corresponde), Cancelar y
 * el botón de confirmar. Se abre al montarse; al cerrarse (Cancelar, Escape) llama a onCerrar. Con bloquearCierre,
 * Escape no lo cierra mientras está ocupado (busy).
 */
export function Dialogo({ titulo, onCerrar, onEnviar, busy = false, textoBoton, textoOcupado = 'Guardando…', peligro = false, bloquearCierre = false, children }) {
  const ref = useRef(null)
  const id = useId()
  useEffect(() => { ref.current?.showModal() }, [])
  return <dialog ref={ref} onClose={onCerrar} onCancel={bloquearCierre ? event => busy && event.preventDefault() : undefined} aria-labelledby={id} className="m-auto w-full max-w-md rounded-2xl p-0 backdrop:bg-slate-900/40">
    <form onSubmit={onEnviar} aria-busy={busy} className="space-y-4 p-6">
      <h2 id={id} className="text-lg font-bold text-slate-900">{titulo}</h2>
      {children}
      <div className="flex justify-end gap-3">
        <button type="button" onClick={() => ref.current.close()} disabled={busy} className={boton('secundario')}>Cancelar</button>
        <button type="submit" disabled={busy} className={boton(peligro ? 'peligro' : 'principal')}>{busy ? textoOcupado : textoBoton}</button>
      </div>
    </form>
  </dialog>
}

/**
 * «Ver todos» / «Mostrar menos» de una lista acotada (historial.js), con su conteo como descripción del botón. Solo
 * aparece si hay más registros que los visibles en la vista compacta. controla: id de la lista.
 */
export function VerTodos({ hayMas, expandido, onCambiar, total, conteo, controla }) {
  const id = useId()
  if (!hayMas) return null
  return <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
    <p id={id} aria-live="polite" className="text-xs text-slate-500">{conteo}</p>
    <button type="button" aria-expanded={expandido} aria-controls={controla} aria-describedby={id} onClick={() => onCambiar(!expandido)}
      className={boton('secundario', 'pequeno')}>{expandido ? 'Mostrar menos' : `Ver todos (${total})`}</button>
  </div>
}

/**
 * Pestañas (tablist): solo la activa entra en el orden de tabulación; flechas, Inicio y Fin cambian de pestaña y mueven
 * el foco. pestanas: [{ clave, titulo }]; idPestana(clave) e idPanel(clave): los id de cada pestaña y de su panel.
 * Si no caben, solo la barra se desplaza en horizontal.
 */
export function Pestanas({ pestanas, activa, onCambiar, etiqueta, idPestana, idPanel }) {
  function teclado(event, indice) {
    const destino = { ArrowRight: indice + 1, ArrowLeft: indice - 1, Home: 0, End: pestanas.length - 1 }[event.key]
    if (destino === undefined) return
    event.preventDefault()
    const siguiente = (destino + pestanas.length) % pestanas.length
    onCambiar(pestanas[siguiente].clave)
    event.currentTarget.parentElement.children[siguiente]?.focus()
  }
  return <div role="tablist" aria-label={etiqueta} className="mt-8 flex gap-1 overflow-x-auto border-b border-slate-200">
    {pestanas.map(({ clave, titulo }, indice) => {
      const seleccionada = clave === activa
      return <button key={clave} type="button" role="tab" id={idPestana(clave)} aria-selected={seleccionada} aria-controls={idPanel(clave)}
        tabIndex={seleccionada ? 0 : -1} onClick={() => onCambiar(clave)} onKeyDown={event => teclado(event, indice)}
        className={`-mb-px shrink-0 whitespace-nowrap border-b-2 px-4 py-2.5 text-sm ${seleccionada ? 'border-teal-700 font-semibold text-teal-800' : 'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-800'}`}>{titulo}</button>
    })}
  </div>
}

/** Panel de una pestaña: siempre montado (conserva su estado); el inactivo se oculta con hidden. */
export function PanelPestana({ id, idPestana, activo, children }) {
  return <div role="tabpanel" id={id} aria-labelledby={idPestana} hidden={!activo} tabIndex={0} className="focus-visible:outline-offset-8">{children}</div>
}

/**
 * Etiqueta de un campo de formulario con su control (children) y una ayuda opcional debajo. content-start: en una
 * cuadrícula, si la celda vecina es más alta (p. ej. tiene ayuda), la etiqueta se estira pero su control no crece.
 */
export function Campo({ etiqueta, ayuda, children, className = '' }) {
  return <label className={`grid content-start gap-2 text-xs font-medium text-slate-600 ${className}`}>{etiqueta}{children}{ayuda && <span className="font-normal text-slate-500">{ayuda}</span>}</label>
}
