import { useState } from 'react'
import { filasComparacionIdentidad } from '../config/identidadEdicion'

export default function ComparacionIdentidad({ original, draft, ubicacion, persona, origen, onApply, onDismiss }) {
  const [seleccion, setSeleccion] = useState([])
  const filas = filasComparacionIdentidad(original, draft, ubicacion, persona)
  const elegidas = filas.filter(fila => fila.diferente && seleccion.includes(fila.key)).map(fila => fila.key)
  const diferencias = filas.filter(fila => fila.diferente).length

  return <div className="col-span-full overflow-hidden rounded-xl border border-teal-200 bg-white" aria-labelledby="comparacion-dni-titulo">
    <div className="border-b border-teal-100 bg-teal-50 p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h4 id="comparacion-dni-titulo" className="text-sm font-semibold text-teal-950">Revisar datos del DNI {persona.dni}</h4>
        <span className="rounded-full bg-white px-3 py-1 text-xs font-medium text-teal-800">{origen === 'base_datos' ? 'Consulta guardada' : 'Consultado en Factiliza'}</span>
      </div>
      <p className="mt-2 text-xs leading-relaxed text-teal-900">{diferencias ? `${diferencias} ${diferencias === 1 ? 'dato diferente' : 'datos diferentes'}. Selecciona cuáles deseas llevar al formulario.` : 'Los datos disponibles coinciden con el formulario.'} Ningún campo se reemplaza automáticamente.</p>
    </div>
    <div className="divide-y divide-slate-100">
      {filas.map(fila => <div key={fila.key} className={`p-4 ${elegidas.includes(fila.key) ? 'bg-teal-50/50' : ''}`}>
        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
          <label className={`flex items-center gap-2 text-sm font-semibold ${fila.diferente ? 'cursor-pointer text-slate-800' : 'text-slate-500'}`}>
            <input type="checkbox" checked={elegidas.includes(fila.key)} disabled={!fila.diferente} onChange={event => setSeleccion(actual => event.target.checked ? [...actual, fila.key] : actual.filter(key => key !== fila.key))} className="size-4 accent-teal-700" />
            {fila.label}
          </label>
          <span className={`text-xs ${fila.diferente ? 'text-amber-800' : 'text-slate-500'}`}>{!fila.disponible ? 'Sin dato disponible' : fila.diferente ? 'Diferente' : 'Coincide'}</span>
        </div>
        <div className="grid gap-3 pl-6 text-sm sm:grid-cols-2">
          <div><p className="text-xs text-slate-500">Guardado en la ficha</p><p className="mt-1 break-words text-slate-700">{fila.guardado || 'Sin registrar'}</p>
            {fila.actual !== fila.guardado && <div className="mt-2 rounded-lg bg-amber-50 p-2 text-amber-950"><p className="text-xs">En edición, aún sin guardar</p><p className="mt-1 break-words">{fila.actual || 'Vacío'}</p></div>}
          </div>
          <div><p className="text-xs text-slate-500">Dato consultado</p><p className="mt-1 break-words font-medium text-teal-900">{fila.consultado || 'No se reemplazará este campo'}</p></div>
        </div>
      </div>)}
    </div>
    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 bg-slate-50 p-4">
      <p className="text-xs text-slate-500">Después de aplicar la selección, pulsa «Guardar cambios».</p>
      <div className="flex flex-wrap gap-3">
        <button type="button" onClick={onDismiss} className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 hover:bg-slate-100">Cerrar comparación</button>
        <button type="button" disabled={!elegidas.length} onClick={() => onApply(elegidas)} className="rounded-lg bg-teal-700 px-3 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50">Aplicar selección ({elegidas.length})</button>
      </div>
    </div>
  </div>
}
