import { useEffect, useRef, useState } from 'react'
import { Navigate, NavLink, Outlet, useNavigate } from 'react-router-dom'
import Icon from '../../components/ui/Icon'
import { cerrarSesion, sesionActual } from './api'
import { SesionPlataforma } from './sesion'

const menu = [
  { to: '/plataforma', label: 'Resumen', icon: 'home', end: true },
  { to: '/plataforma/mi-cuenta', label: 'Mi cuenta', icon: 'user' },
  { to: '/plataforma/cuentas', label: 'Cuentas', icon: 'users' },
  { to: '/plataforma/auditoria', label: 'Auditoría', icon: 'search' },
  { to: '/plataforma/instituciones', label: 'Instituciones', icon: 'building' },
]

function Navegacion({ onNavigate }) {
  return <nav aria-label="Navegación de la plataforma" className="space-y-1">
    {menu.map(item => <NavLink key={item.to} to={item.to} end={item.end} onClick={onNavigate} className={({ isActive }) => `flex items-center gap-3 rounded-xl px-3.5 py-3 text-[13px] transition-colors ${isActive ? 'bg-white/10 font-semibold text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white'}`}><Icon name={item.icon} size={19} />{item.label}</NavLink>)}
  </nav>
}

function Lateral({ administrador, salir, saliendo, errorSalida, onNavigate }) {
  return <div className="flex h-full flex-col px-5 py-7">
    <div className="mb-9 flex items-center gap-3 px-1"><span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-white/10 text-white"><Icon name="shield" /></span><span className="min-w-0"><strong className="block text-sm text-white">Plataforma</strong><span className="text-[11px] text-slate-400">Administración</span></span></div>
    <Navegacion onNavigate={onNavigate} />
    <div className="mt-auto pt-8 text-[11px] text-slate-400"><p className="truncate font-semibold text-slate-200">{administrador.nombre}</p><p className="truncate">{administrador.email}</p>
      <button type="button" onClick={salir} disabled={saliendo} className="mt-4 inline-flex items-center gap-2 rounded-xl border border-white/15 px-3 py-2 text-xs font-semibold text-slate-100 hover:bg-white/10"><Icon name="logout" size={15} />{saliendo ? 'Cerrando…' : 'Cerrar sesión'}</button>
      {errorSalida && <p role="alert" className="mt-2 text-rose-300">{errorSalida}</p>}
    </div>
  </div>
}

/** Comprueba la sesión de plataforma y muestra su layout. Sin sesión, al login de plataforma (no al institucional). */
export default function RequierePlataforma() {
  const [sesion, setSesion] = useState({ loading: true })
  const [intento, setIntento] = useState(0)
  const [saliendo, setSaliendo] = useState(false)
  const [errorSalida, setErrorSalida] = useState('')
  const drawerRef = useRef(null)
  const navigate = useNavigate()
  useEffect(() => {
    const controller = new AbortController()
    sesionActual(controller.signal).then(data => setSesion({ data })).catch(error => {
      if (controller.signal.aborted) return
      setSesion([401, 403, 419].includes(error.response?.status) ? { data: null } : { error: true })
    })
    return () => controller.abort()
  }, [intento])
  async function salir() {
    setSaliendo(true)
    setErrorSalida('')
    try {
      await cerrarSesion()
      navigate('/plataforma/login', { replace: true })
    } catch {
      setErrorSalida('No se pudo cerrar la sesión. Inténtalo de nuevo.')
    } finally {
      setSaliendo(false)
    }
  }
  const terminada = () => navigate('/plataforma/login', { replace: true })
  if (sesion.loading) return <p role="status" className="p-8 text-sm text-slate-600">Comprobando sesión…</p>
  if (sesion.error) return <div role="alert" className="p-8 text-sm">No se pudo comprobar la sesión. <button type="button" className="underline" onClick={() => { setSesion({ loading: true }); setIntento(intento + 1) }}>Reintentar</button></div>
  if (!sesion.data) return <Navigate to="/plataforma/login" replace />
  const { administrador } = sesion.data
  return <SesionPlataforma.Provider value={{ administrador, terminada }}>
    <div className="min-h-dvh bg-[#f6f8fa] font-['DM_Sans',sans-serif] text-slate-700 antialiased [&_button]:cursor-pointer [&_button:disabled]:cursor-not-allowed [&_button:disabled]:opacity-50 [&_:focus-visible]:outline-2 [&_:focus-visible]:outline-offset-4 [&_:focus-visible]:outline-teal-600 [&_h1]:font-['Manrope',sans-serif] [&_h2]:font-['Manrope',sans-serif]">
      <aside className="fixed inset-y-0 left-0 z-20 hidden w-64 overflow-y-auto bg-slate-900 lg:block"><Lateral administrador={administrador} salir={salir} saliendo={saliendo} errorSalida={errorSalida} /></aside>
      <dialog ref={drawerRef} aria-label="Menú de la plataforma" className="fixed inset-y-0 left-0 m-0 h-dvh max-h-none w-80 max-w-[90vw] border-0 bg-slate-900 p-0 backdrop:bg-slate-900/40"><button type="button" onClick={() => drawerRef.current.close()} className="absolute top-2 right-2 rounded-lg p-2 text-slate-200 hover:bg-white/10" aria-label="Cerrar menú"><Icon name="close" /></button><Lateral administrador={administrador} salir={salir} saliendo={saliendo} errorSalida={errorSalida} onNavigate={() => drawerRef.current.close()} /></dialog>
      <div className="lg:pl-64">
        <header className="flex items-center gap-3 border-b border-slate-200 bg-white px-4 py-3 lg:hidden"><button type="button" onClick={() => drawerRef.current.showModal()} className="rounded-lg p-2 hover:bg-slate-100" aria-label="Abrir menú"><Icon name="menu" /></button><strong className="text-sm text-slate-800">Plataforma</strong></header>
        <main className="mx-auto max-w-6xl px-4 py-7 sm:px-8 sm:py-9"><Outlet /></main>
      </div>
    </div>
  </SesionPlataforma.Provider>
}
