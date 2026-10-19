import { useEffect, useState } from 'react'
import { cambiarMiPassword, mensajeError, sesionTerminada } from '../api'
import { Aviso } from '../componentes'
import { ENTRADA, TARJETA, boton } from '../estilos'
import { useSesionPlataforma } from '../sesion'
import { Campo } from '../ui'
const vacio = { password_actual: '', password: '', password_confirmation: '' }

/**
 * Cambio de la propia contraseña (con la actual). Con una contraseña temporal es la única pantalla disponible
 * (obligatorio). El backend valida las reglas; esta sesión sigue abierta y las demás de la cuenta se cierran.
 */
export default function MiCuentaPage({ obligatorio = false }) {
  const { administrador, terminada, actualizar } = useSesionPlataforma()
  const [datos, setDatos] = useState(vacio)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [aviso, setAviso] = useState('')
  useEffect(() => { document.title = 'Mi cuenta · Plataforma' }, [])
  async function enviar(event) {
    event.preventDefault()
    if (busy) return
    if (datos.password !== datos.password_confirmation) { setError('Las contraseñas nuevas no coinciden.'); return }
    setBusy(true)
    setError('')
    setAviso('')
    try {
      await cambiarMiPassword(datos)
      setDatos(vacio)
      setAviso('Contraseña cambiada. Tus otras sesiones abiertas se cerraron.')
      if (obligatorio) actualizar()
    } catch (failure) {
      if (sesionTerminada(failure)) terminada()
      else setError(mensajeError(failure))
    } finally {
      setBusy(false)
    }
  }
  const campo = (nombre, etiqueta, autoComplete) => <Campo etiqueta={etiqueta}>
    <input type="password" required maxLength={255} value={datos[nombre]} onChange={event => setDatos({ ...datos, [nombre]: event.target.value })} disabled={busy} autoComplete={autoComplete} className={ENTRADA} /></Campo>
  return <section aria-labelledby="titulo-cuenta">
    <h1 id="titulo-cuenta" className="text-2xl font-bold text-slate-900">Mi cuenta</h1>
    <p className="mt-1 text-sm text-slate-500">{administrador.nombre} · {administrador.email}</p>
    {obligatorio && <div className="mt-5"><Aviso tipo="info">Tu contraseña es temporal. Cámbiala para usar el panel.</Aviso></div>}
    <form onSubmit={enviar} aria-busy={busy} className={`${TARJETA} mt-6 max-w-lg space-y-4`}>
      <h2 className="font-semibold text-slate-800">Cambiar contraseña</h2>
      <Aviso>{error}</Aviso>
      <Aviso tipo="exito">{aviso}</Aviso>
      {campo('password_actual', 'Contraseña actual *', 'current-password')}
      {campo('password', 'Nueva contraseña *', 'new-password')}
      {campo('password_confirmation', 'Repite la nueva contraseña *', 'new-password')}
      <p className="text-xs text-slate-500">Al menos 12 caracteres, con letras y números. Distinta de la actual.</p>
      <div className="flex justify-end"><button type="submit" disabled={busy} className={boton('principal')}>{busy ? 'Guardando…' : 'Cambiar contraseña'}</button></div>
    </form>
  </section>
}
