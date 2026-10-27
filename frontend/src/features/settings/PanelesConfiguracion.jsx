import { useId, useState } from 'react'
import Icon from '../../components/ui/Icon'

export default function PanelesConfiguracion({ secciones, inicial = null }) {
  const id = useId()
  const [abierta, setAbierta] = useState(inicial)
  const [visitadas, setVisitadas] = useState(inicial ? [inicial] : [])

  function alternar(clave) {
    setAbierta(actual => actual === clave ? null : clave)
    setVisitadas(actuales => actuales.includes(clave) ? actuales : [...actuales, clave])
  }

  return <div className="mt-6 space-y-3">
    {secciones.map(seccion => <div key={seccion.id}>
      <button id={`${id}-${seccion.id}-boton`} type="button" aria-expanded={abierta === seccion.id} aria-controls={`${id}-${seccion.id}-panel`} onClick={() => alternar(seccion.id)} className={`flex w-full items-center gap-3 rounded-2xl border p-4 text-left transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 ${abierta === seccion.id ? 'border-teal-600 bg-teal-50 ring-1 ring-teal-600' : 'border-slate-200 bg-white hover:border-teal-300 hover:bg-teal-50/40'}`}>
        <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-teal-50 text-teal-700"><Icon name={seccion.icono} /></span>
        <span className="min-w-0 flex-1"><span className="block text-sm font-semibold text-slate-800">{seccion.titulo}</span><span className="mt-1 block text-xs text-slate-500">{seccion.descripcion}</span></span>
        <svg aria-hidden="true" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.8" className={`size-4 shrink-0 text-teal-700 transition-transform ${abierta === seccion.id ? 'rotate-180' : ''}`}><path d="m5 8 5 5 5-5" /></svg>
      </button>
      <div id={`${id}-${seccion.id}-panel`} role="region" aria-labelledby={`${id}-${seccion.id}-boton`} hidden={abierta !== seccion.id} className="mt-3 [&>section]:mt-0">
        {visitadas.includes(seccion.id) && seccion.contenido}
      </div>
    </div>)}
  </div>
}
