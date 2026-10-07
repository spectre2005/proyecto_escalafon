import { useCallback, useEffect, useRef, useState } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { cacheInstitution, normalizeInstitution, readInstitution } from '../config/institution'
import { fetchInstitution, saveInstitution } from '../features/settings/institutionApi'
import { navigation } from '../config/navigation'
import Sidebar from './Sidebar'
import Topbar from './Topbar'

export default function AdminLayout({ user }) {
  const [institution, setInstitution] = useState(readInstitution)
  const [institutionVersion, setInstitutionVersion] = useState('')
  const [institutionLoading, setInstitutionLoading] = useState(true)
  const [institutionError, setInstitutionError] = useState('')
  const [institutionUpdatedAt, setInstitutionUpdatedAt] = useState(null)
  const applyInstitutionSnapshot = useCallback(snapshot => {
    const settings = snapshot.configured ? normalizeInstitution(snapshot.data) : readInstitution()
    setInstitution(settings)
    setInstitutionVersion(snapshot.version)
    setInstitutionUpdatedAt(snapshot.updated_at)
    if (snapshot.configured) cacheInstitution(settings)
  }, [])
  useEffect(() => {
    const controller = new AbortController()
    fetchInstitution(controller.signal).then(snapshot => {
      if (!controller.signal.aborted) applyInstitutionSnapshot(snapshot)
    }).catch(() => {
      if (!controller.signal.aborted) setInstitutionError('No se pudo cargar la configuración institucional. Reintenta antes de guardar.')
    }).finally(() => {
      if (!controller.signal.aborted) setInstitutionLoading(false)
    })
    return () => controller.abort()
  }, [applyInstitutionSnapshot])
  async function reloadInstitution() {
    setInstitutionLoading(true)
    setInstitutionError('')
    try {
      applyInstitutionSnapshot(await fetchInstitution())
    } catch {
      setInstitutionError('No se pudo cargar la configuración institucional. Reintenta antes de guardar.')
    } finally {
      setInstitutionLoading(false)
    }
  }
  const drawerRef = useRef(null)
  const mainRef = useRef(null)
  const { pathname } = useLocation()
  const title = navigation.find(item => item.to === pathname || (item.to && item.to !== '/' && pathname.startsWith(`${item.to}/`)))?.label || 'Página no encontrada'
  useEffect(() => {
    document.title = `${title} · ${institution.name}`
    mainRef.current?.focus({ preventScroll: true })
    window.scrollTo(0, 0)
  }, [pathname, title, institution.name])
  async function updateInstitution(value) {
    const snapshot = await saveInstitution(value, institutionVersion)
    const settings = normalizeInstitution(snapshot.data)
    cacheInstitution(settings)
    setInstitution(settings)
    setInstitutionVersion(snapshot.version)
    setInstitutionUpdatedAt(snapshot.updated_at)
    return settings
  }
  return <div className="min-h-dvh bg-[#f6f8fa] font-['DM_Sans',sans-serif] text-slate-700 antialiased selection:bg-teal-100 [&_button]:cursor-pointer [&_button:disabled]:cursor-not-allowed [&_button:disabled]:opacity-50 [&_:focus-visible]:outline-2 [&_:focus-visible]:outline-offset-4 [&_:focus-visible]:outline-teal-600 [&_h1]:font-['Manrope',sans-serif] [&_h2]:font-['Manrope',sans-serif]">
    <a href="#contenido" className="sr-only z-50 rounded-lg bg-white p-3 focus:not-sr-only focus:fixed focus:top-3 focus:left-3">Saltar al contenido</a>
    <Sidebar institution={institution} drawerRef={drawerRef} />
    <div className="lg:pl-64"><Topbar user={user} institution={institution} title={title} onOpenMenu={() => drawerRef.current.showModal()} /><main id="contenido" ref={mainRef} tabIndex={-1} className="mx-auto max-w-7xl px-4 py-7 outline-none! sm:px-8 sm:py-9"><Outlet context={{ institution, updateInstitution, institutionLoading, institutionError, institutionUpdatedAt, reloadInstitution }} /></main><footer className="mx-auto flex max-w-7xl flex-wrap justify-between gap-2 px-4 pb-6 text-[11px] text-slate-500 sm:px-8"><span>{institution.name} · Gestión institucional</span><span>Información organizada, personas conectadas.</span></footer></div>
  </div>
}
