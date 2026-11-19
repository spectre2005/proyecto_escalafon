import { useEffect, useRef, useState } from 'react'

// Lista desplegable con opciones de dos líneas (título y ayuda) y una etiqueta opcional («Sugerido», «Sin nivel»), que
// un <select> nativo no puede mostrar. Cerrada muestra la elección con su ayuda en gris. Accesible como «select-only
// combobox» (WAI-ARIA): flechas, Inicio/Fin, Enter o Espacio para elegir, Escape o Tab para cerrar; clic fuera la cierra.
// opciones: [{ valor, titulo, descripcion?, etiqueta?, tono?: 'sugerido' | 'aviso' }]

const TONOS = { sugerido: 'bg-teal-700 text-white', aviso: 'bg-amber-50 text-amber-800' }

function Etiqueta({ opcion }) {
  return opcion.etiqueta ? <span className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-bold ${TONOS[opcion.tono] || TONOS.aviso}`}>{opcion.etiqueta}</span> : null
}

function Flecha({ abierta }) {
  return <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true" className="shrink-0">
    <path d={abierta ? 'm6 15 6-6 6 6' : 'm6 9 6 6 6-6'} />
  </svg>
}

export default function ListaDesplegable({ id, etiqueta, requerido = false, opciones, valor, onElegir, vacio = 'Seleccionar', disabled = false, error, ayuda }) {
  const [abierta, setAbierta] = useState(false)
  const [activa, setActiva] = useState(-1)
  const caja = useRef(null)
  const elegida = opciones.find(opcion => opcion.valor === valor)
  const indiceElegida = opciones.findIndex(opcion => opcion.valor === valor)

  useEffect(() => {
    if (!abierta) return undefined
    const fuera = event => { if (!caja.current?.contains(event.target)) setAbierta(false) }
    document.addEventListener('mousedown', fuera)
    return () => document.removeEventListener('mousedown', fuera)
  }, [abierta])

  function abrir() {
    if (disabled || !opciones.length) return
    setActiva(indiceElegida >= 0 ? indiceElegida : 0)
    setAbierta(true)
  }
  function elegir(indice) {
    const opcion = opciones[indice]
    if (opcion) onElegir(opcion.valor)
    setAbierta(false)
  }
  function teclado(event) {
    if (!abierta) {
      if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) { event.preventDefault(); abrir() }
      return
    }
    const ultimo = opciones.length - 1
    const mover = { ArrowDown: Math.min(activa + 1, ultimo), ArrowUp: Math.max(activa - 1, 0), Home: 0, End: ultimo }[event.key]
    if (mover !== undefined) { event.preventDefault(); setActiva(mover); return }
    if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); elegir(activa); return }
    if (event.key === 'Escape') { event.preventDefault(); setAbierta(false); return }
    if (event.key === 'Tab') setAbierta(false)
  }

  return <div ref={caja} className="relative grid min-w-0 content-start gap-1.5">
    <p id={`${id}-etiqueta`} className="text-[13px] font-semibold text-slate-700">{etiqueta}{requerido && ' *'}</p>
    <button type="button" role="combobox" aria-haspopup="listbox" aria-expanded={abierta} aria-controls={`${id}-lista`} aria-labelledby={`${id}-etiqueta ${id}-valor`}
      aria-activedescendant={abierta && activa >= 0 ? `${id}-opcion-${activa}` : undefined} aria-invalid={error ? true : undefined} aria-required={requerido || undefined}
      disabled={disabled} onClick={() => (abierta ? setAbierta(false) : abrir())} onKeyDown={teclado}
      className={`flex min-w-0 items-center justify-between gap-2.5 rounded-xl bg-white px-3.5 py-2.5 text-left text-[15px] disabled:cursor-not-allowed disabled:bg-slate-50 ${abierta ? 'border-2 border-teal-700 text-teal-700' : error ? 'border border-rose-400 text-slate-500' : 'border border-slate-300 text-slate-500'}`}>
      <span id={`${id}-valor`} className="min-w-0 truncate">
        {elegida
          ? <><span className="font-medium text-slate-900">{elegida.titulo}</span>{elegida.descripcion && <span className="text-slate-500"> · {elegida.descripcion}</span>}</>
          : <span className="text-slate-500">{vacio}</span>}
      </span>
      <span className="flex shrink-0 items-center gap-2">{elegida && <Etiqueta opcion={elegida} />}<Flecha abierta={abierta} /></span>
    </button>
    {abierta && <ul id={`${id}-lista`} role="listbox" aria-labelledby={`${id}-etiqueta`}
      className="absolute top-full right-0 left-0 z-30 mt-1.5 grid max-h-72 gap-0.5 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg">
      {opciones.map((opcion, indice) => {
        const seleccionada = opcion.valor === valor
        return <li key={opcion.valor} id={`${id}-opcion-${indice}`} role="option" aria-selected={seleccionada}
          onMouseDown={event => event.preventDefault()} onMouseEnter={() => setActiva(indice)} onClick={() => elegir(indice)}
          className={`flex cursor-pointer items-center justify-between gap-3 rounded-lg px-3 py-2.5 ${seleccionada ? 'bg-teal-50' : indice === activa ? 'bg-slate-50' : ''}`}>
          <span className="min-w-0">
            <span className={`block text-sm font-bold ${seleccionada ? 'text-teal-900' : 'text-slate-800'}`}>{opcion.titulo}</span>
            {opcion.descripcion && <span className={`block text-xs ${seleccionada ? 'text-teal-800' : 'text-slate-500'}`}>{opcion.descripcion}</span>}
          </span>
          <span className="flex shrink-0 items-center gap-2">
            <Etiqueta opcion={opcion} />
            {seleccionada && <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" className="text-teal-700"><path d="M20 6 9 17l-5-5" /></svg>}
          </span>
        </li>
      })}
    </ul>}
    {ayuda && <small className="text-xs font-normal text-slate-500">{ayuda}</small>}
    {error && <span className="text-xs font-normal text-rose-700">{[].concat(error).join(' ')}</span>}
  </div>
}
