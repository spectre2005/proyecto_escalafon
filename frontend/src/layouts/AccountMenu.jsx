import { useEffect, useRef, useState } from 'react'
import Icon from '../components/ui/Icon'
import { Link } from 'react-router-dom'
import { usePuede } from '../features/auth/session'

const options = [
  { label: 'Mi perfil', icon: 'user' },
  { label: 'Configuración', icon: 'settings' },
  { label: 'Cerrar sesión', icon: 'logout' },
]

export default function AccountMenu({ institution, account, onLogout, closing = false }) {
  const [open, setOpen] = useState(false)
  const canConfigure = usePuede('sistema.administrar')
  const rootRef = useRef(null)
  const buttonRef = useRef(null)
  useEffect(() => {
    if (!open) return
    function closeOnOutside(event) {
      if (!rootRef.current.contains(event.target)) setOpen(false)
    }
    function closeOnEscape(event) {
      if (event.key !== 'Escape') return
      setOpen(false)
      buttonRef.current.focus()
    }
    document.addEventListener('pointerdown', closeOnOutside)
    document.addEventListener('keydown', closeOnEscape)
    return () => {
      document.removeEventListener('pointerdown', closeOnOutside)
      document.removeEventListener('keydown', closeOnEscape)
    }
  }, [open])
  return <div ref={rootRef} className="relative">
    <button ref={buttonRef} type="button" onClick={() => setOpen(value => !value)} aria-expanded={open} aria-controls="menu-cuenta" aria-label="Abrir menú de cuenta" className="flex items-center gap-3 rounded-xl p-1 text-left hover:bg-slate-50"><span className="grid size-9 place-items-center rounded-full border border-teal-100 bg-teal-50 text-xs font-semibold text-teal-800">{account.initials}</span><span className="hidden sm:block"><span className="block text-xs font-semibold text-slate-700">{account.name}</span><span className="mt-0.5 block text-[10px] text-slate-500">Entorno local</span></span></button>
    {open && <div id="menu-cuenta" className="absolute top-full right-0 z-30 mt-2 w-72 max-w-[calc(100vw-2rem)] rounded-2xl border border-slate-200 bg-white p-2 shadow-lg">
      <div className="flex flex-col items-center px-4 pt-5 pb-4 text-center"><span className="grid size-16 place-items-center rounded-full border border-teal-100 bg-teal-50 text-lg font-semibold text-teal-800">{account.initials}</span><p className="mt-3 text-sm font-semibold text-slate-800">{account.name}</p><p className="mt-0.5 max-w-full truncate text-xs text-slate-500">{account.email}</p><span className="mt-3 rounded-full bg-teal-50 px-2.5 py-1 text-[10px] font-medium text-teal-800 ring-1 ring-inset ring-teal-100">{account.role}</span></div>
      <div className="space-y-1 border-t border-slate-100 pt-2">{options.filter(option => option.icon !== 'settings' || canConfigure).map(option => option.icon === 'logout' ? <button key={option.label} type="button" disabled={closing} onClick={onLogout} className="flex w-full items-center gap-3 rounded-xl px-3.5 py-2.5 text-left text-[13px] text-slate-700 hover:bg-slate-50 disabled:opacity-50"><Icon name={option.icon} size={18} /><span>{closing ? 'Cerrando…' : option.label}</span></button> : option.icon === 'settings' ? <Link key={option.label} to="/configuracion" onClick={() => setOpen(false)} className="flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-[13px] text-slate-700 hover:bg-slate-100"><Icon name="settings" size={18} /><span>{option.label}</span></Link> : <div key={option.label} aria-disabled="true" title="Módulo pendiente de implementación" className="flex w-full items-center gap-3 rounded-xl px-3.5 py-2.5 text-[13px] text-slate-500"><Icon name={option.icon} size={18} /><span>{option.label}</span><span className="ml-auto text-[9px] text-slate-400">Próximo</span></div>)}</div>
      <div className="mt-2 border-t border-slate-100 px-3.5 pt-3 pb-2 text-[10px] text-slate-400"><p className="truncate">{institution.name}</p><p className="mt-0.5">Entorno local</p></div>
    </div>}
  </div>
}
