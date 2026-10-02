import { useEffect, useState } from 'react'
import { Navigate } from 'react-router-dom'
import api from '../../axios'
import AdminLayout from '../../layouts/AdminLayout'

export default function RequireSession() {
  const [session, setSession] = useState({ loading: true })
  const [attempt, setAttempt] = useState(0)
  useEffect(() => {
    const controller = new AbortController()
    api.get('/auth/me', { signal: controller.signal }).then(response => setSession({ user: response.data.data })).catch(error => {
      if (controller.signal.aborted) return
      setSession([401, 403].includes(error.response?.status) ? { user: null } : { error: true })
    })
    return () => controller.abort()
  }, [attempt])
  if (session.loading) return <p role="status" className="p-8">Comprobando sesión…</p>
  if (session.error) return <div role="alert" className="p-8">No se pudo comprobar la sesión. <button onClick={() => { setSession({ loading: true }); setAttempt(attempt + 1) }}>Reintentar</button></div>
  return session.user ? <AdminLayout user={session.user} /> : <Navigate to="/login_principal" replace />
}
