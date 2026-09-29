import { Link } from 'react-router-dom'
import Icon from '../components/ui/Icon'
import { navigation } from '../config/navigation'
import NavItem from './NavItem'

function Content({ institution, onNavigate }) {
  return <div className="flex h-full flex-col px-5 py-7">
    <Link to="/" onClick={onNavigate} className="mb-9 flex items-center gap-3 px-1"><span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-teal-700 text-sm font-bold text-white">{institution.initials}</span><span className="min-w-0"><strong className="block truncate text-sm text-slate-800">{institution.name}</strong><span className="text-[11px] text-slate-500">{institution.area}</span></span></Link>
    <nav aria-label="Navegación principal" className="space-y-7">{['Principal', 'Administración'].map(group => <div key={group}><p className="mb-3 px-3 text-[10px] font-semibold uppercase tracking-[.16em] text-slate-400">{group}</p><div className="space-y-1">{navigation.filter(item => item.group === group).map(item => <NavItem key={item.label} item={item} onNavigate={onNavigate} />)}</div></div>)}</nav>
    <div className="mt-auto pt-8"><div className="rounded-2xl border border-teal-100 bg-teal-50/70 p-4"><Icon name="building" /><p className="mt-3 text-xs font-semibold text-teal-900">Un espacio para tu institución</p><p className="mt-2 text-[11px] leading-relaxed text-teal-800/80">Personaliza el nombre y la identidad de tu panel.</p><Link to="/configuracion" onClick={onNavigate} className="mt-3 inline-flex items-center gap-2 text-xs font-semibold text-teal-800">Personalizar <Icon name="arrow" size={14} /></Link></div><p className="mt-5 px-2 text-[10px] text-slate-400">Portal de gestión institucional</p></div>
  </div>
}

export default function Sidebar({ institution, drawerRef }) {
  return <>
    <aside className="fixed inset-y-0 left-0 z-20 hidden w-64 overflow-y-auto border-r border-slate-200/80 bg-white lg:block"><Content institution={institution} /></aside>
    <dialog ref={drawerRef} aria-label="Menú principal" className="fixed inset-y-0 left-0 m-0 h-dvh max-h-none w-80 max-w-[90vw] border-0 bg-white p-0 text-slate-700 backdrop:bg-slate-900/25"><button type="button" autoFocus onClick={() => drawerRef.current.close()} className="absolute top-2 right-2 rounded-lg p-2 hover:bg-slate-100" aria-label="Cerrar menú"><Icon name="close" /></button><Content institution={institution} onNavigate={() => drawerRef.current.close()} /></dialog>
  </>
}
