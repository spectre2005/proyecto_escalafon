import { useSearchParams } from 'react-router-dom'
import { usePuede } from '../auth/session'
import FuncionalidadesConfig from './FuncionalidadesConfig'
import InstitucionConfig from './InstitucionConfig'

const cardClass = 'rounded-2xl border border-slate-200 bg-white p-6 sm:p-8'

// Configuración en pestañas (?pestana=...). Cada una exige su propio permiso: la identidad institucional
// sistema.administrar y las funcionalidades configuracion.modulos (asignación explícita). El backend valida ambos.
export default function SettingsPage() {
  const [params, setParams] = useSearchParams()
  const canEdit = usePuede('sistema.administrar')
  const canConfigure = usePuede('configuracion.modulos')
  const pestanas = [
    canEdit && { key: 'institucion', label: 'Institución' },
    canConfigure && { key: 'funcionalidades', label: 'Funcionalidades' },
  ].filter(Boolean)
  if (!pestanas.length) return <p role="alert" className={cardClass}>La configuración institucional requiere permisos de administración.</p>
  // Una pestaña sin permiso o desconocida en la URL abre la primera permitida.
  const actual = pestanas.find(pestana => pestana.key === params.get('pestana')) || pestanas[0]

  function cambiar(key) {
    const next = new URLSearchParams(params)
    next.set('pestana', key)
    setParams(next)
  }

  return <div>
    <p className="text-[10px] font-semibold uppercase tracking-[.2em] text-teal-700">Administración</p>
    <h1 className="mt-2 text-3xl font-bold tracking-tight text-slate-800">Configuración</h1>
    {pestanas.length > 1 && <div role="tablist" aria-label="Configuración" className="mt-6 flex gap-2 overflow-x-auto pb-1">
      {pestanas.map(pestana => <button key={pestana.key} type="button" role="tab" id={`pestana-${pestana.key}`} aria-controls={`panel-${pestana.key}`} aria-selected={pestana.key === actual.key} onClick={() => cambiar(pestana.key)} className={`whitespace-nowrap rounded-xl px-4 py-2.5 text-sm font-medium ${pestana.key === actual.key ? 'bg-teal-700 text-white' : 'border border-slate-200 bg-white text-slate-600 hover:bg-slate-50'}`}>{pestana.label}</button>)}
    </div>}
    {/* Institución queda montada y oculta para no perder un borrador sin guardar al cambiar de pestaña. */}
    {canEdit && <div role="tabpanel" id="panel-institucion" aria-labelledby={pestanas.length > 1 ? 'pestana-institucion' : undefined} hidden={actual.key !== 'institucion'} className="mt-6"><InstitucionConfig /></div>}
    {/* Funcionalidades solo se monta (y consulta) en su pestaña. */}
    {canConfigure && actual.key === 'funcionalidades' && <div role="tabpanel" id="panel-funcionalidades" aria-labelledby={pestanas.length > 1 ? 'pestana-funcionalidades' : undefined} className="mt-6"><FuncionalidadesConfig /></div>}
  </div>
}
