import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import api, { apiErrorMessage } from '../../axios'

const entrada = 'rounded-xl border border-slate-200 px-3 py-2.5 text-sm text-slate-800'

/**
 * Cuenta con contraseña temporal (debe_cambiar_password): antes de usar el sistema debe cambiarla. El backend bloquea
 * el resto de la API hasta entonces (ExigeCambioPassword). onCambiada(sesion) recibe la sesión ya liberada.
 */
export default function CambiarPasswordObligatorio({ usuario, venceEn, onCambiada }) {
  const navigate = useNavigate()
  const [datos, setDatos] = useState({ password_actual: '', password: '', password_confirmation: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const set = campo => event => setDatos({ ...datos, [campo]: event.target.value })
  async function cambiar(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    try {
      onCambiada((await api.put('/auth/password', datos)).data.data)
    } catch (failure) {
      setError(apiErrorMessage(failure))
      setBusy(false)
    }
  }
  async function salir() {
    await api.post('/auth/logout').catch(() => {})
    navigate('/login_principal', { replace: true })
  }
  return <main className="grid min-h-dvh place-items-center bg-slate-50 px-4 py-10 text-slate-700 antialiased">
    <form onSubmit={cambiar} aria-busy={busy} className="w-full max-w-sm rounded-3xl bg-white p-8 shadow-xl">
      <p className="text-[10px] font-semibold uppercase tracking-[.16em] text-slate-400">Primer ingreso</p>
      <h1 className="mt-2 text-2xl font-bold text-slate-900">Cambia tu contraseña</h1>
      <p className="mt-2 text-xs text-slate-500">{usuario?.nombres ? `${usuario.nombres}, tu` : 'Tu'} contraseña es temporal. Elige una nueva, con letras y números, para continuar.</p>
      {venceEn && <p className="mt-3 rounded-xl bg-amber-50 p-3 text-xs text-amber-900">Cámbiala antes del {new Date(venceEn).toLocaleString('es-PE', { timeZone: 'America/Lima' })} (hora de Lima). Después necesitarás una nueva contraseña temporal.</p>}
      {error && <p role="alert" className="mt-5 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
      <label className="mt-6 grid gap-2 text-xs font-medium text-slate-600">Contraseña temporal
        <input type="password" required autoComplete="current-password" value={datos.password_actual} onChange={set('password_actual')} disabled={busy} className={entrada} />
      </label>
      <label className="mt-4 grid gap-2 text-xs font-medium text-slate-600">Nueva contraseña
        <input type="password" required minLength={8} autoComplete="new-password" value={datos.password} onChange={set('password')} disabled={busy} className={entrada} />
      </label>
      <label className="mt-4 grid gap-2 text-xs font-medium text-slate-600">Repite la nueva contraseña
        <input type="password" required minLength={8} autoComplete="new-password" value={datos.password_confirmation} onChange={set('password_confirmation')} disabled={busy} className={entrada} />
      </label>
      <button type="submit" disabled={busy} className="mt-6 w-full rounded-xl bg-teal-700 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50">{busy ? 'Guardando…' : 'Cambiar contraseña y continuar'}</button>
      <button type="button" onClick={salir} disabled={busy} className="mt-3 w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm">Cerrar sesión</button>
    </form>
  </main>
}
