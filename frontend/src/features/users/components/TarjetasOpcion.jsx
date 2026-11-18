import { useRef } from 'react'

// Elección única en tarjetas (tipo de personal, ley…): cada opción con su título y una línea de ayuda, todas a la vista.
// Accesible como un grupo de radio: Tab entra a la elegida (o a la primera) y las flechas cambian la elección.
// No son <label>: el formulario del registro da estilo de columna a todos sus <label>.
// conMarca: el círculo de radio a la izquierda (tipo de personal). Sin él, tarjetas compactas (ley).
export default function TarjetasOpcion({ id, etiqueta, ayuda, opciones, valor, onElegir, error, disabled = false, vacio = '', columnas = 'sm:grid-cols-2', conMarca = true }) {
  const botones = useRef([])
  const indiceElegido = opciones.findIndex(opcion => opcion.valor === valor)

  function teclado(event, indice) {
    const paso = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key]
    if (!paso) return
    event.preventDefault()
    const siguiente = (indice + paso + opciones.length) % opciones.length
    onElegir(opciones[siguiente].valor)
    botones.current[siguiente]?.focus()
  }

  return <div className="grid min-w-0 gap-2">
    <p id={`${id}-etiqueta`} className="text-[13px] font-semibold text-slate-700">{etiqueta}{ayuda && <span className="ml-1.5 font-normal text-slate-500">{ayuda}</span>}</p>
    {opciones.length === 0
      ? <p className="rounded-xl border border-dashed border-slate-200 px-4 py-3 text-xs text-slate-500">{vacio}</p>
      : <div role="radiogroup" aria-labelledby={`${id}-etiqueta`} aria-invalid={error ? true : undefined} className={`grid gap-2.5 ${columnas}`}>
        {opciones.map((opcion, indice) => {
          const elegida = opcion.valor === valor
          return <button key={opcion.valor} ref={elemento => { botones.current[indice] = elemento }} type="button" role="radio" aria-checked={elegida} disabled={disabled}
            tabIndex={elegida || (indiceElegido < 0 && indice === 0) ? 0 : -1} onClick={() => onElegir(opcion.valor)} onKeyDown={event => teclado(event, indice)}
            className={`flex min-w-0 items-start gap-3 rounded-xl text-left transition-colors disabled:cursor-not-allowed disabled:opacity-60 ${conMarca ? 'p-4' : 'px-3.5 py-3'} ${elegida ? 'border-2 border-teal-700 bg-teal-50' : 'border border-slate-200 bg-white hover:border-teal-300'}`}>
            {conMarca && <span aria-hidden="true" className={`mt-0.5 size-[18px] shrink-0 rounded-full ${elegida ? 'border-[5px] border-teal-700 bg-white' : 'border-2 border-slate-400'}`} />}
            <span className="min-w-0">
              <span className={`block ${conMarca ? 'text-[15px] font-semibold' : 'text-sm font-bold'} ${elegida ? 'text-teal-900' : 'text-slate-800'}`}>{opcion.titulo}</span>
              {opcion.descripcion && <span className={`mt-0.5 block ${conMarca ? 'text-[13px]' : 'text-xs'} ${elegida ? 'text-teal-800' : 'text-slate-500'}`}>{opcion.descripcion}</span>}
            </span>
          </button>
        })}
      </div>}
    {error && <span className="text-xs text-rose-700">{[].concat(error).join(' ')}</span>}
  </div>
}
