import { useEffect, useState } from 'react'
import { accionSuperadmin, crearSuperadmin, listarSuperadmins, mensajeError, sesionTerminada } from '../api'
import { Aviso, Credenciales } from '../componentes'
import { ACCION_SUPERADMIN, accionesSuperadmin, fecha } from '../estado'
import { useSesionPlataforma } from '../sesion'

const entrada = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-800 disabled:bg-slate-50 disabled:text-slate-500'

/** Alta de un superadministrador: el backend le da una contraseña temporal, que se muestra una sola vez. */
function NuevaCuenta({ onCreada, onSesionTerminada }) {
  const [datos, setDatos] = useState({ nombre: '', email: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function enviar(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    try {
      onCreada(await crearSuperadmin(datos))
      setDatos({ nombre: '', email: '' })
    } catch (failure) {
      if (sesionTerminada(failure)) onSesionTerminada()
      else setError(mensajeError(failure))
    } finally {
      setBusy(false)
    }
  }
  return <form onSubmit={enviar} aria-busy={busy} className="mt-6 rounded-2xl border border-slate-200 bg-white p-6">
    <h2 className="font-semibold text-slate-800">Nueva cuenta</h2>
    <p className="mt-1 text-xs text-slate-500">Se crea con una contraseña temporal que deberá cambiar al ingresar.</p>
    <div className="mt-3"><Aviso>{error}</Aviso></div>
    <div className="mt-3 grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
      <label className="grid gap-2 text-xs font-medium text-slate-600">Nombre *<input required minLength={3} maxLength={150} value={datos.nombre} onChange={event => setDatos({ ...datos, nombre: event.target.value })} disabled={busy} autoComplete="off" className={entrada} /></label>
      <label className="grid gap-2 text-xs font-medium text-slate-600">Correo de acceso *<input required type="email" maxLength={255} value={datos.email} onChange={event => setDatos({ ...datos, email: event.target.value })} disabled={busy} autoComplete="off" className={entrada} /></label>
      <button type="submit" disabled={busy} className="rounded-xl bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white">{busy ? 'Creando…' : 'Crear cuenta'}</button>
    </div>
  </form>
}

/** Cuentas de superadministrador: activas y desactivadas, con sus acciones (nunca sobre la propia). */
export default function SuperadministradoresPage() {
  const { terminada } = useSesionPlataforma()
  const [estado, setEstado] = useState({ loading: true })
  const [intento, setIntento] = useState(0)
  const [confirmar, setConfirmar] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [aviso, setAviso] = useState('')
  const [credenciales, setCredenciales] = useState(null)
  useEffect(() => {
    document.title = 'Superadministradores · Plataforma'
    const controller = new AbortController()
    listarSuperadmins(controller.signal).then(data => setEstado({ data })).catch(failure => {
      if (controller.signal.aborted) return
      if (sesionTerminada(failure)) terminada()
      else setEstado({ error: mensajeError(failure) })
    })
    return () => controller.abort()
  }, [intento, terminada])
  const recargar = () => setIntento(valor => valor + 1)
  async function ejecutar() {
    setBusy(true)
    setError('')
    try {
      const data = await accionSuperadmin(confirmar.cuenta.id, confirmar.accion)
      setAviso(ACCION_SUPERADMIN[confirmar.accion].exito)
      if (data.clave_temporal) setCredenciales({ email: data.email, clave_temporal: data.clave_temporal, titulo: ACCION_SUPERADMIN[confirmar.accion].exito })
      setConfirmar(null)
      recargar()
    } catch (failure) {
      if (sesionTerminada(failure)) terminada()
      else setError(mensajeError(failure))
    } finally {
      setBusy(false)
    }
  }
  const lista = estado.data || []
  return <section aria-labelledby="titulo-superadmins" aria-busy={Boolean(estado.loading)}>
    <h1 id="titulo-superadmins" className="text-2xl font-bold text-slate-900">Superadministradores</h1>
    <p className="mt-1 text-sm text-slate-500">Cuentas que administran la plataforma. No se borran: se desactivan, y sus operaciones anteriores se conservan en la auditoría.</p>
    <div className="mt-5 space-y-3">
      <Aviso tipo="exito">{aviso}</Aviso>
      {credenciales && <Credenciales titulo={credenciales.titulo} credenciales={credenciales} onCerrar={() => setCredenciales(null)} />}
    </div>
    <NuevaCuenta onSesionTerminada={terminada} onCreada={data => { setAviso('Cuenta creada.'); setCredenciales({ email: data.email, clave_temporal: data.clave_temporal, titulo: 'Cuenta creada.' }); recargar() }} />
    <div className="mt-6 rounded-2xl border border-slate-200 bg-white p-6">
      <h2 className="font-semibold text-slate-800">Cuentas</h2>
      {estado.loading && <p role="status" className="mt-3 text-sm text-slate-500">Cargando…</p>}
      {estado.error && <div className="mt-3 space-y-3"><Aviso>{estado.error}</Aviso><button type="button" onClick={() => { setEstado({ loading: true }); recargar() }} className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm">Reintentar</button></div>}
      {estado.data && <ul className="mt-3 divide-y divide-slate-100">
        {lista.map(cuenta => <li key={cuenta.id} className="grid gap-2 py-4 text-sm">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="font-semibold text-slate-800">{cuenta.nombre}{cuenta.es_actual && <span className="font-normal text-slate-500"> (tú)</span>}</p>
              <p className="break-all text-xs text-slate-500">{cuenta.email} · último acceso {cuenta.ultimo_acceso ? fecha(cuenta.ultimo_acceso) : 'nunca'}</p>
              <div className="mt-2 flex flex-wrap gap-2">
                <span className={`inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset ${cuenta.activo ? 'bg-teal-50 text-teal-800 ring-teal-200' : 'bg-slate-100 text-slate-600 ring-slate-200'}`}>{cuenta.activo ? 'Activa' : 'Desactivada'}</span>
                {cuenta.activo && cuenta.debe_cambiar_password && <span className="inline-flex rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-800 ring-1 ring-inset ring-amber-200">Contraseña temporal pendiente de cambio</span>}
              </div>
            </div>
            <div className="flex flex-wrap gap-2">{accionesSuperadmin(cuenta).map(accion => <button key={accion} type="button" disabled={busy}
              onClick={() => { setAviso(''); setError(''); setConfirmar({ cuenta, accion }) }}
              className={`rounded-xl border px-3 py-2 text-xs font-semibold ${accion === 'desactivar' ? 'border-rose-200 text-rose-700' : 'border-slate-200 text-slate-700'} bg-white`}>{ACCION_SUPERADMIN[accion].boton}</button>)}</div>
          </div>
          {confirmar?.cuenta.id === cuenta.id && <div role="alertdialog" aria-label={`${ACCION_SUPERADMIN[confirmar.accion].boton}: ${cuenta.nombre}`} className="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <p className="text-sm text-slate-700"><strong>{ACCION_SUPERADMIN[confirmar.accion].boton}</strong> a {cuenta.nombre}. {ACCION_SUPERADMIN[confirmar.accion].pregunta}</p>
            <div className="mt-2"><Aviso>{error}</Aviso></div>
            <div className="mt-3 flex justify-end gap-3"><button type="button" onClick={() => setConfirmar(null)} disabled={busy} className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm">Cancelar</button><button type="button" onClick={ejecutar} disabled={busy} className={`rounded-xl px-4 py-2 text-sm font-semibold text-white ${confirmar.accion === 'desactivar' ? 'bg-rose-700' : 'bg-teal-700'}`}>{busy ? 'Aplicando…' : 'Confirmar'}</button></div>
          </div>}
        </li>)}
      </ul>}
    </div>
  </section>
}
