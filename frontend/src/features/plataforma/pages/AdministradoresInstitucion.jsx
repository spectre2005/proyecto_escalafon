import { useEffect, useState } from 'react'
import { agregarAdministrador, cambiarActivoAdministrador, listarAdministradores, mensajeError, restablecerAdministrador, sesionTerminada } from '../api'
import { Aviso, Credenciales } from '../componentes'
import { fecha } from '../estado'
import { ENTRADA, boton } from '../estilos'
import { useSesionPlataforma } from '../sesion'
import { Campo, Dialogo, Seccion } from '../ui'

/** Agregar un administrador: nombres, apellido y correo; recibe una contraseña temporal. */
function Agregar({ onGuardar, onCerrar }) {
  const [datos, setDatos] = useState({ nombres: '', apellido_paterno: '', email: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function guardar(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    try {
      await onGuardar({ ...datos, email: datos.email.trim() })
    } catch (failure) {
      setError(mensajeError(failure))
      setBusy(false)
    }
  }
  const campo = (nombre, etiqueta, props = {}) => <Campo etiqueta={etiqueta}><input value={datos[nombre]} onChange={event => setDatos({ ...datos, [nombre]: event.target.value })} disabled={busy} required autoComplete="off" className={ENTRADA} {...props} /></Campo>
  return <Dialogo titulo="Agregar administrador" textoBoton="Agregar" busy={busy} onCerrar={onCerrar} onEnviar={guardar}>
    <p className="text-sm text-slate-600">Tendrá todos los permisos de administración de la institución. Recibe una contraseña temporal que deberá cambiar al ingresar.</p>
    <Aviso>{error}</Aviso>
    {campo('nombres', 'Nombres *', { minLength: 2, maxLength: 100 })}
    {campo('apellido_paterno', 'Apellido paterno *', { minLength: 2, maxLength: 100 })}
    {campo('email', 'Correo de acceso *', { type: 'email', maxLength: 255 })}
  </Dialogo>
}

/** Confirmación de restablecer la contraseña, desactivar o reactivar a un administrador. */
function Confirmar({ accion, admin, onConfirmar, onCerrar }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const textos = {
    restablecer: ['Dar contraseña temporal nueva', 'Su contraseña actual deja de servir. Recibirá una temporal y deberá cambiarla al ingresar.', 'Dar contraseña temporal'],
    desactivar: ['Desactivar administrador', 'No podrá iniciar sesión hasta que se reactive. Sus datos y su historial se conservan.', 'Desactivar'],
    reactivar: ['Reactivar administrador', 'Podrá volver a iniciar sesión con su contraseña actual.', 'Reactivar'],
  }[accion]
  async function confirmar(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    try {
      await onConfirmar()
    } catch (failure) {
      setError(mensajeError(failure))
      setBusy(false)
    }
  }
  return <Dialogo titulo={textos[0]} textoBoton={textos[2]} peligro={accion === 'desactivar'} busy={busy} onCerrar={onCerrar} onEnviar={confirmar}>
    <p className="text-sm text-slate-600"><strong>{admin.nombres} {admin.apellido_paterno}</strong> ({admin.email_institucional}). {textos[1]}</p>
    <Aviso>{error}</Aviso>
  </Dialogo>
}

/**
 * Administradores de una institución aprovisionada: solo cuentas de administración (nombre, correo, estado y último
 * acceso), nunca personas ni legajos. onCambio() recarga la institución (su auditoría). onLista (opcional) recibe lo
 * que se cargó, { lista } o { error: true }, para la franja de resumen: sin otra petición.
 */
export default function AdministradoresInstitucion({ institucion, onCambio, onLista }) {
  const { terminada } = useSesionPlataforma()
  const [estado, setEstado] = useState({ loading: true })
  const [intento, setIntento] = useState(0)
  const [dialogo, setDialogo] = useState(null)
  const [credenciales, setCredenciales] = useState(null)
  const [aviso, setAviso] = useState('')
  useEffect(() => {
    const controller = new AbortController()
    listarAdministradores(institucion.id, controller.signal).then(data => { setEstado({ data }); onLista?.({ lista: data }) }).catch(failure => {
      if (controller.signal.aborted) return
      if (sesionTerminada(failure)) terminada()
      else { setEstado({ error: mensajeError(failure) }); onLista?.({ error: true }) }
    })
    return () => controller.abort()
  }, [institucion.id, intento, terminada, onLista])
  async function ejecutar(operacion, mensaje) {
    try {
      const data = await operacion()
      if (data.clave_temporal) setCredenciales({ email: data.email_institucional, clave_temporal: data.clave_temporal, titulo: mensaje })
      else setAviso(mensaje)
      setDialogo(null)
      setIntento(valor => valor + 1)
      onCambio()
    } catch (failure) {
      if (sesionTerminada(failure)) terminada()
      throw failure
    }
  }
  const lista = estado.data || []
  return <Seccion titulo="Administradores de la institución" descripcion="Cuentas con acceso de administración. Si alguien olvidó su contraseña, dale una temporal nueva."
    acciones={<button type="button" onClick={() => { setAviso(''); setDialogo({ accion: 'agregar' }) }} className={boton('secundario')}>Agregar administrador</button>}>
    <div className="mt-4 space-y-3">
      <Aviso tipo="exito">{aviso}</Aviso>
      {credenciales && <Credenciales titulo={credenciales.titulo} credenciales={credenciales} onCerrar={() => setCredenciales(null)} />}
      {estado.error && <Aviso>{estado.error}</Aviso>}
    </div>
    {estado.loading ? <p role="status" className="mt-4 text-sm text-slate-500">Cargando…</p> : !estado.error && <ul className="mt-4 divide-y divide-slate-100">
      {lista.length === 0 && <li className="py-3 text-sm text-slate-500">Sin administradores.</li>}
      {lista.map(admin => <li key={admin.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
        <div className="min-w-0">
          <p className="text-sm font-medium text-slate-800">{admin.nombres} {admin.apellido_paterno}</p>
          <p className="break-all text-xs text-slate-500">{admin.email_institucional} · Último acceso: {fecha(admin.ultimo_acceso)}</p>
          <div className="mt-1 flex flex-wrap gap-2">
            {!admin.activo && <span className="inline-flex rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-semibold text-rose-700 ring-1 ring-inset ring-rose-200">Desactivado</span>}
            {admin.debe_cambiar_password && <span className="inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-800 ring-1 ring-inset ring-amber-200">Contraseña temporal sin cambiar</span>}
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          <button type="button" onClick={() => { setAviso(''); setDialogo({ accion: 'restablecer', admin }) }} className={boton('secundario', 'pequeno')}>Contraseña temporal</button>
          <button type="button" onClick={() => { setAviso(''); setDialogo({ accion: admin.activo ? 'desactivar' : 'reactivar', admin }) }} className={boton(admin.activo ? 'peligroContorno' : 'secundario', 'pequeno')}>{admin.activo ? 'Desactivar' : 'Reactivar'}</button>
        </div>
      </li>)}
    </ul>}
    {dialogo?.accion === 'agregar' && <Agregar onCerrar={() => setDialogo(null)} onGuardar={datos => ejecutar(() => agregarAdministrador(institucion.id, datos), 'Administrador agregado.')} />}
    {dialogo && dialogo.accion !== 'agregar' && <Confirmar accion={dialogo.accion} admin={dialogo.admin} onCerrar={() => setDialogo(null)} onConfirmar={() => ejecutar(
      () => dialogo.accion === 'restablecer' ? restablecerAdministrador(institucion.id, dialogo.admin.id) : cambiarActivoAdministrador(institucion.id, dialogo.admin.id, dialogo.accion === 'reactivar'),
      { restablecer: 'Contraseña temporal nueva.', desactivar: 'Administrador desactivado.', reactivar: 'Administrador reactivado.' }[dialogo.accion])} />}
  </Seccion>
}
