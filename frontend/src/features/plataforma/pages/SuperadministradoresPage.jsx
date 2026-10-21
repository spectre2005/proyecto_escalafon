import { limiteInicial } from '../plazoPassword'
import { useEffect, useState } from 'react'
import { accionSuperadmin, crearSuperadmin, listarSuperadmins, mensajeError, sesionTerminada } from '../api'
import { Aviso, Credenciales, ResumenCuentas, PlazoPassword, LimitePassword } from '../componentes'
import { ACCION_SUPERADMIN, accionesSuperadmin, fecha } from '../estado'
import { ENTRADA, TARJETA, boton } from '../estilos'
import { useSesionPlataforma } from '../sesion'
import { Campo, Seccion } from '../ui'
import CampoDni from '../CampoDni'
import { useIdentidadDni } from '../useIdentidadDni'

/** Alta de un superadministrador: el backend le da una contraseña temporal, que se muestra una sola vez. */
function NuevaCuenta({ onCreada, onSesionTerminada }) {
  const [datos, setDatos] = useState({ dni: '', nombre: '', email: '', vence_en: limiteInicial() })
  const consulta = useIdentidadDni(datos.dni, setDatos)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function enviar(event) {
    event.preventDefault()
    if (busy || consulta.consultando) return
    setBusy(true)
    setError('')
    try {
      const { dni, nombre, email, vence_en } = datos
      onCreada(await crearSuperadmin({ dni, nombre, email, vence_en }))
      setDatos({ dni: '', nombre: '', email: '', vence_en: limiteInicial() })
    } catch (failure) {
      if (sesionTerminada(failure)) onSesionTerminada()
      else setError(mensajeError(failure))
    } finally {
      setBusy(false)
    }
  }
  return <form onSubmit={enviar} aria-busy={busy} className={`${TARJETA} mt-6`}>
    <h2 className="font-semibold text-slate-800">Nueva cuenta</h2>
    <p className="mt-1 text-xs text-slate-500">Se crea con una contraseña temporal que deberá cambiar al ingresar.</p>
    <div className="mt-3"><Aviso>{error}</Aviso></div>
    <div className="mt-4 max-w-md"><CampoDni value={datos.dni} consulta={consulta} disabled={busy} /></div>
    <div className="mt-4 max-w-md"><PlazoPassword value={datos.vence_en} onChange={valor => setDatos({ ...datos, vence_en: valor })} disabled={busy} /></div>
    <div className="mt-3 grid gap-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
      <Campo etiqueta="Nombres y apellidos *"><input required minLength={3} maxLength={150} value={datos.nombre} onChange={event => setDatos({ ...datos, nombre: event.target.value })} disabled={busy || consulta.consultando} autoComplete="off" className={ENTRADA} /></Campo>
      <Campo etiqueta="Correo de acceso *"><input required type="email" maxLength={255} value={datos.email} onChange={event => setDatos({ ...datos, email: event.target.value })} disabled={busy} autoComplete="off" className={ENTRADA} /></Campo>
      <button type="submit" disabled={busy || consulta.consultando} className={boton('principal', 'campo')}>{busy ? 'Creando…' : 'Crear cuenta'}</button>
    </div>
  </form>
}

/** Cuentas de superadministrador: activas y desactivadas, con sus acciones (nunca sobre la propia). */
export default function SuperadministradoresPage() {
  const { terminada } = useSesionPlataforma()
  const [estado, setEstado] = useState({ loading: true })
  const [intento, setIntento] = useState(0)
  const [confirmar, setConfirmar] = useState(null)
  const [fechaLimite, setFechaLimite] = useState(limiteInicial)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [aviso, setAviso] = useState('')
  const [credenciales, setCredenciales] = useState(null)
  const [busqueda, setBusqueda] = useState('')
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
    setCredenciales(null)
    try {
      const data = await accionSuperadmin(confirmar.cuenta.id, confirmar.accion, fechaLimite)
      setAviso(ACCION_SUPERADMIN[confirmar.accion].exito)
      if (data.clave_temporal) setCredenciales({ ...data, email: data.email, clave_temporal: data.clave_temporal, titulo: ACCION_SUPERADMIN[confirmar.accion].exito })
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
  const visibles = lista.filter(cuenta => `${cuenta.dni || ""} ${cuenta.nombre} ${cuenta.email}`.toLocaleLowerCase('es').includes(busqueda.trim().toLocaleLowerCase('es')))
  return <section aria-labelledby="titulo-superadmins" aria-busy={Boolean(estado.loading)}>
    <h1 id="titulo-superadmins" className="text-2xl font-bold text-slate-900">Superadministradores</h1>
    <p className="mt-1 text-sm text-slate-500">Cuentas que administran la plataforma. No se borran: se desactivan, y sus operaciones anteriores se conservan en la auditoría.</p>
    {estado.data && <ResumenCuentas cuentas={lista} />}
    <div className="mt-5 space-y-3">
      <Aviso tipo="exito">{aviso}</Aviso>
      {credenciales && <Credenciales key={credenciales.clave_temporal} titulo={credenciales.titulo} credenciales={credenciales} onCerrar={() => setCredenciales(null)} />}
    </div>
    <NuevaCuenta onSesionTerminada={terminada} onCreada={data => { setAviso('Cuenta creada.'); setCredenciales({ ...data, email: data.email, clave_temporal: data.clave_temporal, titulo: 'Cuenta creada.' }); recargar() }} />
    <Seccion titulo="Cuentas">
      {estado.data && <label className="mt-4 grid max-w-md gap-2 text-xs font-medium text-slate-600">Buscar por DNI, nombre o correo<input type="search" value={busqueda} onChange={event => setBusqueda(event.target.value)} className={ENTRADA} placeholder="Escribe un DNI, nombre o correo" /></label>}
      {estado.loading && <p role="status" className="mt-3 text-sm text-slate-500">Cargando…</p>}
      {estado.error && <div className="mt-3 space-y-3"><Aviso>{estado.error}</Aviso><button type="button" onClick={() => { setEstado({ loading: true }); recargar() }} className={boton('secundario')}>Reintentar</button></div>}
      {estado.data && <ul className="mt-3 divide-y divide-slate-100">
        {visibles.length === 0 && <li className="py-6 text-sm text-slate-500">No hay cuentas que coincidan con la búsqueda.</li>}
        {visibles.map(cuenta => <li key={cuenta.id} className="grid gap-2 py-4 text-sm">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="font-semibold text-slate-800">{cuenta.nombre}{cuenta.es_actual && <span className="font-normal text-slate-500"> (tú)</span>}</p>
              <p className="break-all text-xs text-slate-500">{cuenta.dni && <>DNI {cuenta.dni} · </>}{cuenta.email} · último acceso {cuenta.ultimo_acceso ? fecha(cuenta.ultimo_acceso) : 'nunca'}</p>
              <LimitePassword cuenta={cuenta} />
              <div className="mt-2 flex flex-wrap gap-2">
                <span className={`inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset ${cuenta.activo ? 'bg-teal-50 text-teal-800 ring-teal-200' : 'bg-slate-100 text-slate-600 ring-slate-200'}`}>{cuenta.activo ? 'Activa' : 'Desactivada'}</span>
                {cuenta.activo && cuenta.debe_cambiar_password && <span className="inline-flex rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-800 ring-1 ring-inset ring-amber-200">Contraseña temporal pendiente de cambio</span>}
              </div>
            </div>
            <div className="flex flex-wrap gap-2">{accionesSuperadmin(cuenta).map(accion => <button key={accion} type="button" disabled={busy}
              onClick={() => { setAviso(''); setError(''); setConfirmar({ cuenta, accion }) }}
              className={boton(accion === 'desactivar' ? 'peligroContorno' : 'secundario', 'pequeno')}>{ACCION_SUPERADMIN[accion].boton}</button>)}</div>
          </div>
          {confirmar?.cuenta.id === cuenta.id && <div role="alertdialog" aria-label={`${ACCION_SUPERADMIN[confirmar.accion].boton}: ${cuenta.nombre}`} className="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <p className="text-sm text-slate-700"><strong>{ACCION_SUPERADMIN[confirmar.accion].boton}</strong> a {cuenta.nombre}. {ACCION_SUPERADMIN[confirmar.accion].pregunta}</p>
            {confirmar.accion !== 'desactivar' && <div className="mt-3 max-w-md"><PlazoPassword value={fechaLimite} onChange={setFechaLimite} disabled={busy} /></div>}
            <div className="mt-2"><Aviso>{error}</Aviso></div>
            <div className="mt-3 flex justify-end gap-3"><button type="button" onClick={() => setConfirmar(null)} disabled={busy} className={boton('secundario')}>Cancelar</button><button type="button" onClick={ejecutar} disabled={busy} className={boton(confirmar.accion === 'desactivar' ? 'peligro' : 'principal')}>{busy ? 'Aplicando…' : 'Confirmar'}</button></div>
          </div>}
        </li>)}
      </ul>}
    </Seccion>
  </section>
}
