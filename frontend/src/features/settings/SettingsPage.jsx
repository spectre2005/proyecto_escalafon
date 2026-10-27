import { Navigate, useSearchParams } from 'react-router-dom'
import { usePuede } from '../auth/session'
import PanelesConfiguracion from './PanelesConfiguracion'
import InstitucionConfig from './InstitucionConfig'
import CorreoRequerimientosConfig from './CorreoRequerimientosConfig'

export default function SettingsPage() {
  const [params] = useSearchParams()
  const canEdit = usePuede('sistema.administrar')

  if (params.get('pestana') === 'funcionalidades') return <Navigate to="/accesos-y-custodia" replace />
  if (!canEdit) return <p role="alert" className="rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">La configuración institucional requiere permisos de administración.</p>

  return <div>
    <p className="text-[10px] font-semibold uppercase tracking-[.2em] text-teal-700">Administración</p>
    <h1 className="mt-2 text-3xl font-bold tracking-tight text-slate-800">Configuración</h1>
    <p className="mt-3 text-sm text-slate-500">Selecciona una categoría para ver sus opciones.</p>
    <PanelesConfiguracion inicial={params.has('gmail') ? 'correo' : null} secciones={[
      { id: 'institucion', titulo: 'Institución y apariencia', descripcion: 'Datos, logo, colores y reportes', icono: 'building', contenido: <InstitucionConfig /> },
      { id: 'correo', titulo: 'Correo de requerimientos', descripcion: 'Cuenta institucional y conexión', icono: 'settings', contenido: <CorreoRequerimientosConfig /> },
    ]} />
  </div>
}
