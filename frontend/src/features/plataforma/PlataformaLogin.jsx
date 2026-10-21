import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import Icon from '../../components/ui/Icon'
import { iniciarSesion } from './api'
import { EntradaPassword } from './ui'

/** Acceso al panel de plataforma. Cuentas propias (no las de una institución); sin registro público. */
export default function PlataformaLogin() {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const navigate = useNavigate()
  async function entrar(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    try {
      await iniciarSesion(email.trim(), password)
      navigate('/plataforma', { replace: true })
    } catch (failure) {
      const status = failure.response?.status
      setError(status === 422 ? 'Correo o contraseña incorrectos.' : status === 429 ? (failure.response?.data?.message || 'Demasiados intentos. Espera un momento.') : 'No se pudo iniciar sesión. Comprueba la conexión e inténtalo de nuevo.')
      setPassword('')
    } finally {
      setBusy(false)
    }
  }
  return <main className="grid min-h-dvh place-items-center bg-slate-900 px-4 py-10 font-['DM_Sans',sans-serif] text-slate-700 antialiased">
    <form onSubmit={entrar} aria-busy={busy} className="w-full max-w-sm rounded-3xl bg-white p-8 shadow-xl">
      <span className="grid size-12 place-items-center rounded-2xl bg-slate-900 text-white"><Icon name="shield" size={22} /></span>
      <p className="mt-6 text-[10px] font-semibold uppercase tracking-[.16em] text-slate-400">Administración de la plataforma</p>
      <h1 className="mt-2 font-['Manrope',sans-serif] text-2xl font-bold text-slate-900">Iniciar sesión</h1>
      <p className="mt-2 text-xs text-slate-500">Acceso solo para la administración de la plataforma. Las cuentas de una institución entran por su propio portal.</p>
      {error && <p role="alert" className="mt-5 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
      <label className="mt-6 grid gap-2 text-xs font-medium text-slate-600">Correo
        <input type="email" required autoComplete="username" value={email} onChange={event => setEmail(event.target.value)} disabled={busy} className="rounded-xl border border-slate-200 px-3 py-2.5 text-sm text-slate-800" />
      </label>
      <label className="mt-4 grid gap-2 text-xs font-medium text-slate-600">Contraseña
        <EntradaPassword required autoComplete="current-password" value={password} onChange={event => setPassword(event.target.value)} disabled={busy} className="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm text-slate-800" />
      </label>
      <button type="submit" disabled={busy} className="mt-6 w-full rounded-xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50">{busy ? 'Entrando…' : 'Entrar'}</button>
    </form>
  </main>
}
