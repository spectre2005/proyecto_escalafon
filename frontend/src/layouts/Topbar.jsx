import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import Icon from '../components/ui/Icon'
import AccountMenu from './AccountMenu'

export default function Topbar({ title, institution, onOpenMenu }) {
  const [search, setSearch] = useState('')
  const navigate = useNavigate()
  function submit(event) {
    event.preventDefault()
    navigate(`/personal${search.trim() ? `?q=${encodeURIComponent(search.trim())}` : ''}`)
    setSearch('')
  }
  return <header className="flex min-h-20 items-center gap-4 border-b border-slate-200/70 bg-white/90 px-5 sm:px-8">
    <button type="button" onClick={onOpenMenu} aria-label="Abrir menú" className="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden"><Icon name="menu" /></button>
    <span className="hidden text-xs text-slate-400 xl:block">Espacio de trabajo <span className="mx-3">/</span><strong className="font-medium text-slate-700">{title}</strong></span>
    <form onSubmit={submit} role="search" className="flex min-w-0 flex-1 items-center gap-2 rounded-xl border border-slate-200/60 bg-slate-50 px-3 xl:ml-auto xl:max-w-80"><Icon name="search" size={17} /><input aria-label="Buscar personal" value={search} onChange={event => setSearch(event.target.value)} placeholder="Buscar personal por nombre o DNI…" className="min-w-0 flex-1 bg-transparent py-3 text-xs outline-none" /><button type="submit" className="rounded-md p-1 text-slate-500 hover:text-teal-700" aria-label="Buscar"><Icon name="arrow" size={16} /></button></form>
    <div className="hidden h-8 w-px bg-slate-200 sm:block" /><AccountMenu institution={institution} />
  </header>
}
