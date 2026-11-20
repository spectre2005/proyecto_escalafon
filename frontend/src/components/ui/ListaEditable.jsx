import { useId, useState } from 'react'
import { normalizar } from '../../features/legajos/config/catalogosVinculo'

// Campo de texto con lista desplegable: se escribe libremente o se elige una opción. Al abrir (clic o flecha) muestra
// toda la lista; al escribir, filtra sin mayúsculas ni tildes. Lo usan el puesto y la unidad orgánica del vínculo, cuyas
// listas aprenden de lo escrito. Campo (seccion01) le pasa className y aria-invalid, que van al input.
export default function ListaEditable({ value, onChange, opciones = [], vacio = 'Aún no hay opciones: escribe una nueva.', className = '', ...props }) {
  const [abierta, setAbierta] = useState(false)
  const [activa, setActiva] = useState(-1)
  const id = useId()
  const buscado = normalizar(value)
  const exacta = opciones.some(opcion => normalizar(opcion) === buscado)
  const visibles = !buscado || exacta ? opciones : opciones.filter(opcion => normalizar(opcion).includes(buscado))

  function elegir(opcion) {
    onChange(opcion)
    setAbierta(false)
    setActiva(-1)
  }
  function teclas(event) {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault()
      if (!abierta) return setAbierta(true)
      const paso = event.key === 'ArrowDown' ? 1 : -1
      setActiva(actual => visibles.length ? (actual + paso + visibles.length) % visibles.length : -1)
    } else if (event.key === 'Enter' && abierta && activa >= 0 && visibles[activa]) {
      // Elegir con Enter no envía ni avanza el formulario.
      event.preventDefault()
      event.stopPropagation()
      elegir(visibles[activa])
    } else if (event.key === 'Escape' && abierta) {
      event.preventDefault()
      event.stopPropagation()
      setAbierta(false)
    }
  }

  return <div className="relative min-w-0">
    <input {...props} role="combobox" aria-expanded={abierta} aria-controls={`${id}-lista`} aria-autocomplete="list" autoComplete="off"
      aria-activedescendant={abierta && activa >= 0 ? `${id}-${activa}` : undefined}
      className={`${className} w-full pr-9`} value={value}
      onChange={event => { onChange(event.target.value); setAbierta(true); setActiva(-1) }}
      onClick={() => setAbierta(true)} onBlur={() => setAbierta(false)} onKeyDown={teclas} />
    <button type="button" tabIndex={-1} disabled={props.disabled} aria-label={abierta ? 'Cerrar lista' : 'Mostrar lista'}
      onMouseDown={event => { event.preventDefault(); setAbierta(actual => !actual) }}
      className="absolute inset-y-0 right-0 grid w-9 place-items-center text-slate-400 hover:text-slate-600 disabled:opacity-40">
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className={abierta ? 'rotate-180' : ''}><path d="m6 9 6 6 6-6" /></svg>
    </button>
    {/* Dentro de un <label> (Campo), el clic en una opción se reenviaría al input y reabriría la lista. */}
    {abierta && !props.disabled && <ul id={`${id}-lista`} role="listbox" onClick={event => event.preventDefault()} className="absolute inset-x-0 z-30 mt-1 max-h-56 overflow-auto rounded-xl border border-slate-200 bg-white py-1 text-sm text-slate-700 shadow-lg">
      {visibles.map((opcion, indice) => <li key={opcion} id={`${id}-${indice}`} role="option" aria-selected={normalizar(opcion) === buscado}
        onMouseDown={event => { event.preventDefault(); elegir(opcion) }} onMouseEnter={() => setActiva(indice)}
        className={`cursor-pointer px-3 py-2 ${indice === activa ? 'bg-teal-50 text-teal-800' : ''} ${normalizar(opcion) === buscado ? 'font-semibold' : ''}`}>{opcion}</li>)}
      {visibles.length === 0 && <li className="px-3 py-2 text-xs text-slate-500">{opciones.length ? 'Ninguna coincide: se agregará como nueva al guardar.' : vacio}</li>}
    </ul>}
  </div>
}
