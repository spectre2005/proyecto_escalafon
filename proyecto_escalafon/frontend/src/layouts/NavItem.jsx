import { NavLink } from 'react-router-dom'
import Icon from '../components/ui/Icon'

export default function NavItem({ item, onNavigate }) {
  const base = 'flex w-full items-center gap-3 rounded-xl px-3.5 py-3 text-[13px] transition-colors'
  if (!item.to) return <div className={`${base} text-slate-500`} title="Módulo pendiente de implementación"><Icon name={item.icon} size={19} /><span>{item.label}</span><span className="ml-auto text-[9px] text-slate-400">Próximo</span></div>
  return <NavLink to={item.to} end={item.to === '/'} onClick={onNavigate} className={({ isActive }) => `${base} ${isActive ? 'bg-teal-50 font-semibold text-teal-800 ring-1 ring-inset ring-teal-100' : 'text-slate-600 hover:bg-slate-50 hover:text-teal-800'}`}><Icon name={item.icon} size={19} />{item.label}</NavLink>
}
