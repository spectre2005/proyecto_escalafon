import { useId } from 'react'
import { valorBandera } from '../../shared/banderas'

const opciones = [[true, 'Sí'], [false, 'No'], [null, 'Sin responder']]
const flechas = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }

// Pregunta Sí / No / Sin responder para una bandera del perfil (true / false / null). Sin responder es el valor por
// defecto: el formulario nunca asume No. Es un radiogroup de botones (sin <label>), así no lo alteran los estilos
// que algunos formularios aplican a sus etiquetas; las flechas cambian la opción, como en un grupo de radios.
export default function TresEstados({ name, label, ayuda, value, onChange, disabled = false, error }) {
  const id = useId()
  const actual = valorBandera(value)
  function teclado(event, indice) {
    const paso = flechas[event.key]
    if (!paso) return
    event.preventDefault()
    const siguiente = (indice + paso + opciones.length) % opciones.length
    onChange(opciones[siguiente][0])
    event.currentTarget.parentElement.children[siguiente].focus()
  }
  return <div className="min-w-0">
    <p id={`${id}-pregunta`} className="text-sm text-slate-700">{label}</p>
    {ayuda && <p id={`${id}-ayuda`} className="mt-1 text-xs text-slate-500">{ayuda}</p>}
    <div role="radiogroup" data-bandera={name} aria-labelledby={`${id}-pregunta`} aria-describedby={ayuda ? `${id}-ayuda` : undefined} aria-invalid={error ? true : undefined}
      className="mt-2 inline-flex flex-wrap gap-1 rounded-xl border border-slate-200 bg-slate-50 p-1">
      {opciones.map(([valor, texto], indice) => {
        const elegida = actual === valor
        const clase = !elegida ? 'text-slate-500 hover:text-slate-800' : valor === null ? 'bg-white text-slate-700 shadow-sm ring-1 ring-slate-200' : 'bg-teal-700 text-white'
        return <button key={texto} type="button" role="radio" aria-checked={elegida} tabIndex={elegida ? 0 : -1} disabled={disabled}
          onClick={() => onChange(valor)} onKeyDown={event => teclado(event, indice)}
          className={`rounded-lg px-3 py-1.5 text-xs font-medium focus-visible:outline-2 focus-visible:outline-teal-600 disabled:cursor-not-allowed disabled:opacity-60 ${clase}`}>{texto}</button>
      })}
    </div>
    {error && <p className="mt-1 text-xs text-rose-700">{[].concat(error).join(' ')}</p>}
  </div>
}
