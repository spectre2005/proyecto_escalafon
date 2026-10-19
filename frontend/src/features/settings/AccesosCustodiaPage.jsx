import { usePuede } from '../auth/session'
import ModulosSistemaConfig from './ModulosSistemaConfig'
import FuncionalidadesConfig from './FuncionalidadesConfig'
import EquipoAccesosConfig from './EquipoAccesosConfig'

export default function AccesosCustodiaPage() {
  const canConfigure = usePuede('configuracion.modulos')
  const canAdminister = usePuede('sistema.administrar')
  const canManageTeam = usePuede('equipo.gestionar')

  if (!canConfigure && !canAdminister && !canManageTeam) return <p role="alert" className="rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">Esta sección requiere permisos de administración.</p>

  return <div>
    <p className="text-[10px] font-semibold uppercase tracking-[.2em] text-teal-700">Administración</p>
    <h1 className="mt-2 text-3xl font-bold tracking-tight text-slate-800">Accesos y custodia</h1>
    <p className="mt-3 text-sm text-slate-500">Gestiona el equipo de trabajo, los módulos del menú principal y las funcionalidades del legajo de esta institución.</p>
    <div className="mt-6 space-y-6">{canManageTeam && <EquipoAccesosConfig />}{canConfigure
      ? <><ModulosSistemaConfig /><FuncionalidadesConfig /></>
      : <p role="alert" className="rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">Necesitas el permiso de configuración de módulos para administrar las funcionalidades.</p>}
    </div>
  </div>
}
