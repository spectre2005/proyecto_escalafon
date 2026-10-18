import { Navigate, useSearchParams } from 'react-router-dom'
import { usePuede } from '../auth/session'
import InstitucionConfig from './InstitucionConfig'

export default function SettingsPage() {
  const [params] = useSearchParams()
  const canEdit = usePuede('sistema.administrar')

  if (params.get('pestana') === 'funcionalidades') return <Navigate to="/accesos-y-custodia" replace />
  if (!canEdit) return <p role="alert" className="rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">La configuración institucional requiere permisos de administración.</p>

  return <div>
    <p className="text-[10px] font-semibold uppercase tracking-[.2em] text-teal-700">Administración</p>
    <h1 className="mt-2 text-3xl font-bold tracking-tight text-slate-800">Configuración</h1>
    <div className="mt-6"><InstitucionConfig /></div>
  </div>
}
