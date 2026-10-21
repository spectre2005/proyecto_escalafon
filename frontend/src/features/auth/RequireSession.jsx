import { useEffect, useState } from 'react'
import { Navigate, useLocation } from 'react-router-dom'
import api, { mensajeInstitucion } from '../../axios'
import AdminLayout from '../../layouts/AdminLayout'
import CambiarPasswordObligatorio from './CambiarPasswordObligatorio'
import { SessionContext } from './session'

export default function RequireSession() {
  const location = useLocation()
  const [session, setSession] = useState({ loading: true })
  const [attempt, setAttempt] = useState(0)
  useEffect(() => {
    const controller = new AbortController()
    api.get('/auth/me', { signal: controller.signal }).then(response => setSession({ data: response.data.data })).catch(error => {
      if (controller.signal.aborted) return
      // Sin sesión o institución no disponible: al login, que muestra el motivo al intentar entrar.
      setSession([401, 403, 419].includes(error.response?.status) || mensajeInstitucion(error) ? { data: null } : { error: true })
    })
    return () => controller.abort()
  }, [attempt])
  if (session.loading) return <p role="status" className="p-8">Comprobando sesión…</p>
  if (session.error) return <div role="alert" className="p-8">No se pudo comprobar la sesión. <button onClick={() => { setSession({ loading: true }); setAttempt(attempt + 1) }}>Reintentar</button></div>
  if (!session.data) return <Navigate to="/login_principal" replace />
  // Contraseña temporal: nada del sistema hasta cambiarla (el backend también lo exige).
  if (session.data.debe_cambiar_password) return <CambiarPasswordObligatorio usuario={session.data.usuario} onCambiada={data => setSession({ data })} />
  if (location.pathname === '/' && session.data.permisos.includes('solicitudes.propias') && !session.data.permisos.includes('legajo.ver')) return <Navigate to="/requerimientos?clase=actualizacion" replace />
  return <SessionContext.Provider value={session.data}><AdminLayout user={session.data.usuario} avisoServicio={session.data.aviso_servicio} /></SessionContext.Provider>
}
