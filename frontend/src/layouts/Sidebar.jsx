import { Link } from 'react-router-dom'
import Icon from '../components/ui/Icon'
import { navigation } from '../config/navigation'
import NavItem from './NavItem'
import { puede, useSesion } from '../features/auth/session'
import { activa } from '../shared/funcionalidadesEstado'
import { useFuncionalidades } from '../shared/useFuncionalidades'

function Content({ institution, onNavigate }) {
  const sesion = useSesion()
  const funcionalidades = useFuncionalidades()
  const items = navigation.filter(item => (Array.isArray(item.permiso) ? item.permiso.some(permiso => puede(sesion, permiso)) : puede(sesion, item.permiso))
    && (!item.modulo || funcionalidades.modulos_sistema?.[item.modulo] === true)
    && (!item.funcionalidades || item.funcionalidades.some(codigo => activa(funcionalidades, codigo))))
  return <div className="flex h-full flex-col px-5 py-7">
    <Link to="/" onClick={onNavigate} className="mb-9 flex items-center gap-3 px-1"><span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-teal-700 text-sm font-bold text-white">{institution.logo ? <img src={institution.logo} alt={`Logo de ${institution.name}`} className="size-full rounded-2xl bg-white object-contain p-1" /> : institution.initials}</span><span className="min-w-0"><strong className="block truncate text-sm text-slate-800">{institution.name}</strong><span className="text-[11px] text-slate-500">{institution.area}</span></span></Link>
    <nav aria-label="Navegación principal" className="space-y-7">{['Principal', 'Administración'].filter(group => items.some(item => item.group === group)).map(group => <div key={group}><p className="mb-3 px-3 text-[10px] font-semibold uppercase tracking-[.16em] text-slate-400">{group}</p><div className="space-y-1">{items.filter(item => item.group === group).map(item => <NavItem key={item.label} item={item} onNavigate={onNavigate} />)}</div></div>)}</nav>
    <p className="mt-auto px-2 pt-8 text-[10px] text-slate-400">Portal de gestión institucional</p>
  </div>
}

export default function Sidebar({ institution, drawerRef }) {
  return <>
    <aside className="fixed inset-y-0 left-0 z-20 hidden w-64 overflow-y-auto border-r border-slate-200/80 bg-white lg:block"><Content institution={institution} /></aside>
    <dialog ref={drawerRef} aria-label="Menú principal" className="fixed inset-y-0 left-0 m-0 h-dvh max-h-none w-80 max-w-[90vw] border-0 bg-white p-0 text-slate-700 backdrop:bg-slate-900/25"><button type="button" autoFocus onClick={() => drawerRef.current.close()} className="absolute top-2 right-2 rounded-lg p-2 hover:bg-slate-100" aria-label="Cerrar menú"><Icon name="close" /></button><Content institution={institution} onNavigate={() => drawerRef.current.close()} /></dialog>
  </>
}
