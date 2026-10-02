import { useState } from 'react'
import Icon from '../../../components/ui/Icon'

const control = 'rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:ring-2 focus:ring-teal-600 disabled:opacity-40'

export default function Seccion07Filtros({ tipo, params, opciones, onChange }) {
  const [search, setSearch] = useState(params.get('q') || '')
  const anios = opciones?.anios?.[tipo.key] || []
  const unidades = opciones?.unidades || []
  return <section className="mb-6 rounded-2xl border border-slate-200 bg-white p-5" aria-label="Filtros de la sección 07">
    <form onSubmit={event => { event.preventDefault(); onChange('q', search.trim()) }} className="flex flex-wrap gap-3">
      <label className="flex min-w-60 flex-1 items-center gap-2 rounded-xl border border-slate-200 px-3"><Icon name="search" size={18} /><input className="w-full py-3 text-sm outline-none" aria-label="Buscar por nombre o DNI" placeholder="Nombre o DNI" value={search} onChange={event => setSearch(event.target.value)} /></label>
      <select aria-label={tipo.anio} className={control} value={params.get('anio') || ''} onChange={event => onChange('anio', event.target.value)}>
        <option value="">{tipo.anio === 'Ciclo' ? 'Todos los ciclos' : 'Todos los años'}</option>
        {anios.map(anio => <option key={anio} value={anio}>{tipo.anio} {anio}</option>)}
      </select>
      <select aria-label="Unidad orgánica" className={`${control} max-w-64`} value={params.get('unidad_organica_id') || ''} onChange={event => onChange('unidad_organica_id', event.target.value)}>
        <option value="">Todas las unidades</option>
        {unidades.map(unidad => <option key={unidad.id} value={unidad.id}>{unidad.nombre}</option>)}
      </select>
      {tipo.key === 'evaluaciones' && <label className="flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2.5 text-sm text-slate-700"><input type="checkbox" className="size-4 accent-teal-700" checked={params.get('plan_mejora') === '1'} onChange={event => onChange('plan_mejora', event.target.checked ? '1' : '')} />Solo con plan de mejora</label>}
      <button className="rounded-xl bg-teal-700 px-5 py-3 text-sm font-medium text-white hover:bg-teal-800">Buscar</button>
    </form>
  </section>
}
