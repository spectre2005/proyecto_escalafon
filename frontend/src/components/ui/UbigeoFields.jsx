import { useSyncExternalStore } from 'react'
import api from '../../axios'
import { createQueryStore } from '../../shared/queries/queryStore'

const catalogUrl = '/ubicaciones?completo=1'
const catalog = createQueryStore((url, signal) => api.get(url, { signal }).then(response => response.data.data))
const subscribe = listener => catalog.subscribe(catalogUrl, listener)
const snapshot = () => catalog.snapshot(catalogUrl)
const selectClass = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-teal-600 disabled:bg-slate-50 disabled:text-slate-400'

export default function UbigeoFields({ value, onChange, disabled = false, required = false }) {
  const request = useSyncExternalStore(subscribe, snapshot)
  const rows = request.data || []
  const regions = [...new Set(rows.map(row => row.region))]
  const provinces = [...new Set(rows.filter(row => row.region === value.region).map(row => row.provincia))]
  const districts = rows.filter(row => row.region === value.region && row.provincia === value.provincia)
  function change(field, selected) {
    const next = { ...value, [field]: selected }
    if (field === 'region') { next.provincia = ''; next.distrito = '' }
    if (field === 'provincia') next.distrito = ''
    const match = rows.find(row => row.region === next.region && row.provincia === next.provincia && row.distrito === next.distrito)
    onChange(next, match?.id || '')
  }
  return <div className="col-span-full">
    <div className="grid gap-4 sm:grid-cols-3">
      {[
        ['region', 'Región', regions, false],
        ['provincia', 'Provincia', provinces, !value.region],
        ['distrito', 'Distrito', districts.map(row => row.distrito), !value.provincia],
      ].map(([field, title, options, needsParent]) => <label key={field} className="flex flex-col gap-2 text-xs font-medium text-slate-600">
        {title}{required ? ' *' : ''}
        <select name={field} className={selectClass} value={value[field]} required={required} disabled={disabled || needsParent || request.loading || !!request.error} onChange={event => change(field, event.target.value)}>
          <option value="">Selecciona {title.toLowerCase()}</option>
          {value[field] && !options.includes(value[field]) && <option value={value[field]}>{value[field]} (valor actual fuera del catálogo)</option>}
          {options.map(option => <option key={option} value={option}>{option}</option>)}
        </select>
      </label>)}
    </div>
    {request.loading && <p role="status" className="mt-3 text-xs text-slate-500">Cargando ubicaciones del Perú…</p>}
    {request.error && <p role="alert" className="mt-3 text-xs text-rose-700">No se pudo cargar el catálogo. <button type="button" className="underline" onClick={() => catalog.refresh(catalogUrl)}>Reintentar</button></p>}
    {!request.loading && !request.error && !rows.length && <p role="alert" className="mt-3 text-xs text-amber-700">El catálogo de ubicaciones está vacío.</p>}
  </div>
}
