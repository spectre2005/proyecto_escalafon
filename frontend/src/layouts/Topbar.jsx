import api from '../axios'
import { useId, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import Icon from '../components/ui/Icon'
import AccountMenu from './AccountMenu'
import { usePuede } from '../features/auth/session'
import { useUsers } from '../features/users/hooks/useUsers'
import { filterUsers } from '../features/users/utils/usuariosFiltering'

function CoincidenciasPersonal({ query, id, onClose, puedeVerLegajo }) {
  const { users, loading, loadError, refreshUsers } = useUsers()
  const encontrados = filterUsers(users, { query: query.trim() })
  return <div id={id} className="absolute inset-x-0 top-full z-50 mt-2 max-h-96 overflow-y-auto rounded-xl border border-slate-200 bg-white p-2 shadow-xl" aria-label="Personas encontradas">
    {loading && !users.length && <p role="status" className="p-3 text-sm text-slate-500">Buscando personas…</p>}
    {loadError && <p role="alert" className="p-3 text-sm text-rose-800">No se pudieron cargar las personas. <button type="button" onClick={refreshUsers} className="underline">Reintentar</button></p>}
    {!loading && !loadError && !encontrados.length && <p role="status" className="p-3 text-sm text-slate-500">No hay personas que coincidan.</p>}
    <ul>{encontrados.slice(0, 8).map(persona => <li key={persona.id}><Link onClick={onClose} to={puedeVerLegajo ? `/legajos/${persona.id}` : `/personal?q=${encodeURIComponent(persona.numero_doc_identidad)}`} className="block rounded-lg px-3 py-2 hover:bg-teal-50 focus:bg-teal-50"><span className="block text-sm font-semibold text-slate-800">{[persona.nombres, persona.apellido_paterno, persona.apellido_materno].filter(Boolean).join(' ')}</span><span className="text-xs text-slate-500">{persona.numero_doc_identidad} · {persona.email_institucional || 'Sin correo'}</span></Link></li>)}</ul>
    {!!encontrados.length && <Link to={`/personal?q=${encodeURIComponent(query.trim())}`} onClick={onClose} className="mt-1 block border-t border-slate-100 p-3 text-xs font-semibold text-teal-800">Ver todas las coincidencias ({encontrados.length})</Link>}
  </div>
}

export default function Topbar({ title, institution, onOpenMenu, user }) {
  const [closing, setClosing] = useState(false)
  const [error, setError] = useState('')
  async function logout() {
    if (closing) return
    setError('')
    setClosing(true)
    try {
      await api.post('/auth/logout')
    } catch (failure) {
      // 401/419: la sesión ya estaba cerrada o vencida; igual se sale.
      if (![401, 419].includes(failure.response?.status)) { setError('No se pudo cerrar la sesi\u00f3n. Intenta nuevamente.'); setClosing(false); return }
    }
    window.location.assign('/login_principal')
  }
  const esAdministrador = usePuede('sistema.administrar')
  const puedeBuscarPersonal = usePuede('personal.ver')
  const puedeVerLegajo = usePuede('legajo.ver')
  const resultsId = useId()
  const [openSearch, setOpenSearch] = useState(false)
  const [search, setSearch] = useState('')
  const navigate = useNavigate()
  function submit(event) {
    event.preventDefault()
    navigate(`/personal${search.trim() ? `?q=${encodeURIComponent(search.trim())}` : ''}`)
    setSearch('')
    setOpenSearch(false)
  }
  return <header className="flex min-h-20 items-center gap-4 border-b border-slate-200/70 bg-white/90 px-5 sm:px-8">
    <button type="button" onClick={onOpenMenu} aria-label="Abrir menú" className="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden"><Icon name="menu" /></button>
    <span className="hidden text-xs text-slate-400 xl:block">Espacio de trabajo <span className="mx-3">/</span><strong className="font-medium text-slate-700">{title}</strong></span>
    {puedeBuscarPersonal && <div data-busqueda-personal className="relative min-w-0 flex-1 xl:ml-auto xl:max-w-80" onBlur={event => { if (!event.currentTarget.contains(event.relatedTarget)) setOpenSearch(false) }} onKeyDown={event => {
      if (event.key === 'Escape') setOpenSearch(false)
      if (event.key === 'ArrowDown' && event.target.tagName === 'INPUT') {
        const primero = event.currentTarget.querySelector('a')
        if (primero) { event.preventDefault(); primero.focus() }
      }
    }}>
      <form onSubmit={submit} role="search" className="flex items-center gap-2 rounded-xl border border-slate-200/60 bg-slate-50 px-3"><Icon name="search" size={17} /><input type="search" aria-label="Buscar personal" aria-expanded={openSearch && !!search.trim()} aria-controls={openSearch && search.trim() ? resultsId : undefined} value={search} onFocus={() => setOpenSearch(true)} onChange={event => { setSearch(event.target.value); setOpenSearch(true) }} placeholder="Buscar personal por nombre o DNI…" maxLength={120} className="min-w-0 flex-1 bg-transparent py-3 text-xs outline-none" /><button type="submit" className="rounded-md p-1 text-slate-500 hover:text-teal-700" aria-label="Buscar"><Icon name="arrow" size={16} /></button></form>
      {openSearch && !!search.trim() && <CoincidenciasPersonal id={resultsId} query={search} puedeVerLegajo={puedeVerLegajo} onClose={() => { setOpenSearch(false); setSearch('') }} />}
    </div>}
    <div className="hidden h-8 w-px bg-slate-200 sm:block" />
    <AccountMenu institution={institution} account={{
      name: [user.nombres, user.apellido_paterno, user.apellido_materno].filter(Boolean).join(' '),
      initials: [user.nombres, user.apellido_paterno].filter(Boolean).map(value => value.charAt(0)).join('').toUpperCase(),
      email: user.email_institucional,
      role: esAdministrador ? 'Administrador' : 'Personal autorizado',
    }} onLogout={logout} closing={closing} />
    {error && <p role="alert" className="text-xs text-rose-700">{error}</p>}
  </header>
}
