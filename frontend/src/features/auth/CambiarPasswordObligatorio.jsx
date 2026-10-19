import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import api, { apiErrorMessage } from '../../axios'

const entrada = 'rounded-xl border border-slate-200 px-3 py-2.5 text-sm text-slate-800'

/**
 * Cuenta con contraseña temporal (debe_cambiar_password): antes de usar el sistema debe cambiarla. El backend bloquea
 * el resto de la API hasta entonces (ExigeCambioPassword). onCambiada(sesion) recibe la sesión ya liberada.
 */
export default function CambiarPasswordObligatorio({ usuario, onCambiada, obligatorio = true, guardar }) {
  const navigate = useNavigate()
  const Heading = obligatorio ? 'h1' : 'h2'
  const [datos, setDatos] = useState({ password_actual: '', password: '', password_confirmation: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState(false)
  const set = campo => event => setDatos({ ...datos, [campo]: event.target.value })
  async function cambiar(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    setSuccess(false)
    try {
      const sesion = guardar ? await guardar(datos) : (await api.put('/auth/password', datos)).data.data
      setDatos({ password_actual: '', password: '', password_confirmation: '' })
      setSuccess(true)
      onCambiada?.(sesion)
    } catch (failure) {
      setError(apiErrorMessage(failure))
    } finally {
      setBusy(false)
    }
  }
  async function salir() {
    await api.post('/auth/logout').catch(() => {})
    navigate('/login_principal', { replace: true })
  }
  return <section className={obligatorio ? "grid min-h-dvh place-items-center bg-slate-50 px-4 py-10 text-slate-700 antialiased" : "mt-6"}>
    <form onSubmit={cambiar} aria-busy={busy} className="w-full max-w-sm rounded-3xl bg-white p-8 shadow-xl">
      <p className="text-[10px] font-semibold uppercase tracking-[.16em] text-slate-400">{obligatorio ? 'Primer ingreso' : 'Seguridad de la cuenta'}</p>
      <Heading className="mt-2 text-2xl font-bold text-slate-900">Cambia tu contraseña</Heading>
      <p className="mt-2 text-xs text-slate-500">{obligatorio ? `${usuario?.nombres ? `${usuario.nombres}, tu` : 'Tu'} contraseña es temporal. Elige una nueva, con letras y números, para continuar.` : 'Confirma tu contraseña actual y elige una nueva. Las otras sesiones deberán volver a iniciar sesión.'}</p>
      {success && <p role="status" className="mt-5 rounded-lg bg-teal-50 p-3 text-sm text-teal-800">Contraseña actualizada.</p>}
      {error && <p role="alert" className="mt-5 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
      <label className="mt-6 grid gap-2 text-xs font-medium text-slate-600">{obligatorio ? 'Contraseña temporal' : 'Contraseña actual'}
        <input type="password" required autoComplete="current-password" value={datos.password_actual} onChange={set('password_actual')} disabled={busy} className={entrada} />
      </label>
      <label className="mt-4 grid gap-2 text-xs font-medium text-slate-600">Nueva contraseña
        <input type="password" required minLength={8} autoComplete="new-password" value={datos.password} onChange={set('password')} disabled={busy} className={entrada} />
      </label>
      <label className="mt-4 grid gap-2 text-xs font-medium text-slate-600">Repite la nueva contraseña
        <input type="password" required minLength={8} autoComplete="new-password" value={datos.password_confirmation} onChange={set('password_confirmation')} disabled={busy} className={entrada} />
      </label>
      <button type="submit" disabled={busy} className="mt-6 w-full rounded-xl bg-teal-700 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50">{busy ? 'Guardando…' : (obligatorio ? 'Cambiar contraseña y continuar' : 'Actualizar contraseña')}</button>
      {obligatorio && <button type="button" onClick={salir} disabled={busy} className="mt-3 w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm">Cerrar sesión</button>}
    </form>
  </section>
}
