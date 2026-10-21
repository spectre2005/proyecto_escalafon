import { useEffect, useState } from 'react'
import { cambiarMiNombre, cambiarMiPassword, cerrarOtrasSesiones, mensajeError, sesionTerminada, verMiCuenta } from '../api'
import { Aviso } from '../componentes'
import { fecha, navegador } from '../estado'
import { ENTRADA, TARJETA, boton } from '../estilos'
import { useSesionPlataforma } from '../sesion'
import { Campo, Dialogo, EntradaPassword, Seccion } from '../ui'
const vacio = { password_actual: '', password: '', password_confirmation: '' }

/** Envío de un formulario de la página: busy, error y la sesión terminada (401/419) llevan al login. */
function useEnvio() {
  const { terminada } = useSesionPlataforma()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function enviar(accion) {
    if (busy) return false
    setBusy(true)
    setError('')
    try {
      await accion()
      return true
    } catch (failure) {
      if (sesionTerminada(failure)) terminada()
      else setError(mensajeError(failure))
      return false
    } finally {
      setBusy(false)
    }
  }
  return { busy, error, setError, enviar }
}

/**
 * Cambio de la propia contraseña (con la actual), para la tarjeta obligatoria y la ventana. La actual no se puede
 * mostrar; las nuevas sí, para revisarlas. guardar() devuelve true si se cambió.
 */
function useCambioPassword() {
  const [datos, setDatos] = useState(vacio)
  const { busy, error, setError, enviar } = useEnvio()
  async function guardar(event) {
    event.preventDefault()
    if (datos.password !== datos.password_confirmation) { setError('Las contraseñas nuevas no coinciden.'); return false }
    const cambiada = await enviar(() => cambiarMiPassword(datos))
    if (cambiada) setDatos(vacio)
    return cambiada
  }
  const campo = (nombre, etiqueta, autoComplete, conVer = true) => {
    const props = { required: true, maxLength: 255, value: datos[nombre], onChange: event => setDatos({ ...datos, [nombre]: event.target.value }), disabled: busy, autoComplete }
    return <Campo etiqueta={etiqueta}>{conVer ? <EntradaPassword {...props} /> : <input type="password" {...props} className={ENTRADA} />}</Campo>
  }
  const campos = <>
    <Aviso>{error}</Aviso>
    {campo('password_actual', 'Contraseña actual *', 'current-password', false)}
    {campo('password', 'Nueva contraseña *', 'new-password')}
    {campo('password_confirmation', 'Repite la nueva contraseña *', 'new-password')}
    <p className="text-xs text-slate-500">Al menos 12 caracteres, con letras y números. Distinta de la actual. Tus otras sesiones abiertas se cerrarán.</p>
  </>
  return { busy, guardar, campos }
}

/** Con una contraseña temporal el cambio es obligatorio: el formulario va abierto, sin ventana. */
function CambioObligatorio({ onCambiada }) {
  const { busy, guardar, campos } = useCambioPassword()
  return <form onSubmit={async event => { if (await guardar(event)) onCambiada() }} aria-busy={busy} className={`${TARJETA} mt-6 max-w-lg space-y-4`}>
    <h2 className="font-semibold text-slate-800">Cambiar contraseña</h2>
    {campos}
    <div className="flex justify-end"><button type="submit" disabled={busy} className={boton('principal')}>{busy ? 'Guardando…' : 'Cambiar contraseña'}</button></div>
  </form>
}

function CambioPasswordDialogo({ onCambiada, onCerrar }) {
  const { busy, guardar, campos } = useCambioPassword()
  return <Dialogo titulo="Cambiar contraseña" textoBoton="Cambiar contraseña" busy={busy} bloquearCierre onCerrar={onCerrar}
    onEnviar={async event => { if (await guardar(event)) onCambiada() }}>
    {campos}
  </Dialogo>
}

/** Cierra las demás sesiones de la cuenta, con la contraseña actual (no se muestra). Esta sesión sigue abierta. */
function CerrarSesionesDialogo({ onCerradas, onCerrar }) {
  const [password, setPassword] = useState('')
  const { busy, error, enviar } = useEnvio()
  async function confirmar(event) {
    event.preventDefault()
    if (await enviar(() => cerrarOtrasSesiones(password))) onCerradas()
  }
  return <Dialogo titulo="Cerrar mis otras sesiones" textoBoton="Cerrar las otras sesiones" textoOcupado="Cerrando…" busy={busy} bloquearCierre onCerrar={onCerrar} onEnviar={confirmar}>
    <Aviso>{error}</Aviso>
    <p className="text-sm text-slate-600">Se cerrarán tus sesiones abiertas en otros equipos o navegadores. Esta sesión sigue abierta y tu contraseña no cambia.</p>
    <Campo etiqueta="Contraseña actual *"><input type="password" required maxLength={255} autoComplete="current-password" value={password} onChange={event => setPassword(event.target.value)} disabled={busy} className={ENTRADA} /></Campo>
  </Dialogo>
}

/** Un acceso en pocas palabras: fecha, navegador e IP. */
const textoAcceso = evento => `${fecha(evento.created_at)} · ${navegador(evento.user_agent)}${evento.ip_origen ? ` · IP ${evento.ip_origen}` : ''}`

/** Iniciales del nombre (dos como máximo) para la cabecera. */
const iniciales = nombre => nombre.trim().split(/\s+/).slice(0, 2).map(parte => parte[0]?.toUpperCase() || '').join('')

/** Fila de una ficha: término, valor y, si tiene, su acción a la derecha. */
function Fila({ termino, children, accion }) {
  return <div className="grid gap-2 py-4 sm:grid-cols-[11rem_1fr_auto] sm:items-center sm:gap-4">
    <dt className="text-sm text-slate-500">{termino}</dt>
    <dd className="min-w-0 text-sm font-medium break-words text-slate-700">{children}</dd>
    {accion && <dd>{accion}</dd>}
  </div>
}

/** Cabecera: quién es la cuenta. */
function Cabecera({ cuenta }) {
  return <div className={`${TARJETA} mt-6 flex flex-wrap items-center gap-4`}>
    <span aria-hidden="true" className="grid size-14 shrink-0 place-items-center rounded-2xl bg-slate-900 font-['Manrope',sans-serif] text-lg font-bold text-white">{iniciales(cuenta.nombre)}</span>
    <div className="min-w-0 flex-1">
      <p className="truncate text-lg font-semibold text-slate-900">{cuenta.nombre}</p>
      <p className="truncate text-sm text-slate-500">{cuenta.email}</p>
    </div>
    <span className="inline-flex rounded-full bg-teal-50 px-2.5 py-1 text-[11px] font-semibold text-teal-800 ring-1 ring-teal-200 ring-inset">Superadministrador</span>
  </div>
}

/** Perfil: se ve como ficha y se edita con «Editar» (como «Cliente y contrato»). El correo es el usuario de acceso: no se cambia. */
function Perfil({ cuenta, onGuardado }) {
  const [borrador, setBorrador] = useState(null)
  const [aviso, setAviso] = useState('')
  const { busy, error, setError, enviar } = useEnvio()
  async function guardar(event) {
    event.preventDefault()
    const nombre = borrador.trim()
    if (nombre === cuenta.nombre) { setBorrador(null); return }
    if (await enviar(() => cambiarMiNombre(nombre))) {
      setBorrador(null)
      setAviso('Nombre guardado.')
      onGuardado()
    }
  }
  const editar = () => { setAviso(''); setError(''); setBorrador(cuenta.nombre) }
  return <Seccion titulo="Perfil" descripcion="Tu nombre aparece en el menú y en las operaciones que registras."
    acciones={borrador === null && <button type="button" onClick={editar} className={boton('secundario')}>Editar</button>}>
    <div className="mt-3"><Aviso tipo="exito">{aviso}</Aviso></div>
    {borrador !== null ? <form onSubmit={guardar} aria-busy={busy} className="mt-3 grid gap-4 sm:grid-cols-2">
      <div className="sm:col-span-2"><Aviso>{error}</Aviso></div>
      <Campo etiqueta="Nombre *"><input required maxLength={150} value={borrador} onChange={event => setBorrador(event.target.value)} disabled={busy} autoComplete="name" className={ENTRADA} /></Campo>
      <Campo etiqueta="Correo" ayuda="Es tu usuario de acceso; no se puede cambiar."><input value={cuenta.email} readOnly disabled className={ENTRADA} /></Campo>
      <div className="flex justify-end gap-3 sm:col-span-2"><button type="button" onClick={() => setBorrador(null)} disabled={busy} className={boton('secundario')}>Cancelar</button><button type="submit" disabled={busy || !borrador.trim()} className={boton('principal')}>{busy ? 'Guardando…' : 'Guardar'}</button></div>
    </form> : <dl className="mt-1 divide-y divide-slate-100">
      <Fila termino="Nombre">{cuenta.nombre}</Fila>
      <Fila termino="Correo">{cuenta.email} <span className="font-normal text-slate-500">(usuario de acceso)</span></Fila>
    </dl>}
  </Seccion>
}

/** Seguridad: contraseña y otras sesiones (cada una con su ventana), acceso anterior y fecha de creación. */
function Seguridad({ datos, aviso, onCambiarPassword, onCerrarSesiones }) {
  const { cuenta, acceso_anterior: anterior } = datos
  return <Seccion titulo="Seguridad" descripcion="Tu contraseña y tus sesiones abiertas.">
    <div className="mt-3"><Aviso tipo="exito">{aviso}</Aviso></div>
    <dl className="mt-1 divide-y divide-slate-100">
      <Fila termino="Contraseña" accion={<button type="button" onClick={onCambiarPassword} className={boton('secundario')}>Cambiar contraseña</button>}>
        <span aria-hidden="true" className="tracking-widest">••••••••</span>
        <span className="font-normal text-slate-500"> · {cuenta.clave_cambiada_en ? `cambiada el ${fecha(cuenta.clave_cambiada_en)}` : 'sin cambios desde el panel'}</span>
      </Fila>
      <Fila termino="Otras sesiones" accion={<button type="button" onClick={onCerrarSesiones} className={boton('secundario')}>Cerrar otras sesiones</button>}>
        <span className="font-normal text-slate-600">¿Dejaste el panel abierto en otro equipo? Ciérralo sin cambiar tu contraseña.</span>
      </Fila>
      <Fila termino="Acceso anterior">{anterior ? textoAcceso(anterior) : 'Este es tu primer acceso registrado'}</Fila>
      <Fila termino="Cuenta creada">{fecha(cuenta.creada_en)}</Fila>
    </dl>
  </Seccion>
}

/**
 * Mi cuenta: perfil (nombre, con «Editar») y seguridad (contraseña y otras sesiones en ventanas, acceso anterior). Con
 * una contraseña temporal solo se ofrece el cambio, ya abierto (obligatorio): el backend bloquea el resto.
 */
export default function MiCuentaPage({ obligatorio = false }) {
  const { administrador, terminada, actualizar } = useSesionPlataforma()
  const [estado, setEstado] = useState({ loading: true })
  const [intento, setIntento] = useState(0)
  const [ventana, setVentana] = useState(null)
  const [aviso, setAviso] = useState('')
  useEffect(() => { document.title = 'Mi cuenta · Plataforma' }, [])
  useEffect(() => {
    if (obligatorio) return
    const controller = new AbortController()
    verMiCuenta(controller.signal).then(data => setEstado({ data })).catch(error => {
      if (controller.signal.aborted) return
      if (sesionTerminada(error)) terminada()
      else setEstado({ error: mensajeError(error) })
    })
    return () => controller.abort()
  }, [obligatorio, intento, terminada])
  const recargar = () => setIntento(valor => valor + 1)
  const abrir = cual => () => { setAviso(''); setVentana(cual) }
  const terminar = texto => () => { setVentana(null); setAviso(texto); recargar() }
  const { data } = estado
  return <section aria-labelledby="titulo-cuenta" aria-busy={Boolean(!obligatorio && estado.loading)}>
    <h1 id="titulo-cuenta" className="text-2xl font-bold text-slate-900">Mi cuenta</h1>
    <p className="mt-1 text-sm text-slate-500">{obligatorio ? `${administrador.nombre} · ${administrador.email}` : 'Tu perfil y la seguridad de tu cuenta.'}</p>
    {obligatorio ? <>
      <div className="mt-5"><Aviso tipo="info">Tu contraseña es temporal. Cámbiala para usar el panel.</Aviso></div>
      <CambioObligatorio onCambiada={actualizar} />
    </> : <div className="max-w-4xl">
      {estado.loading && <p role="status" className="mt-6 text-sm text-slate-500">Cargando…</p>}
      {estado.error && <div className="mt-6 space-y-3"><Aviso>{estado.error}</Aviso><button type="button" onClick={() => { setEstado({ loading: true }); recargar() }} className={boton('secundario')}>Reintentar</button></div>}
      {data && <>
        <Cabecera cuenta={data.cuenta} />
        <Perfil key={data.cuenta.id} cuenta={data.cuenta} onGuardado={() => { actualizar(); recargar() }} />
        <Seguridad datos={data} aviso={aviso} onCambiarPassword={abrir('password')} onCerrarSesiones={abrir('sesiones')} />
        {ventana === 'password' && <CambioPasswordDialogo onCerrar={() => setVentana(null)} onCambiada={terminar('Contraseña cambiada. Tus otras sesiones abiertas se cerraron.')} />}
        {ventana === 'sesiones' && <CerrarSesionesDialogo onCerrar={() => setVentana(null)} onCerradas={terminar('Se cerraron tus otras sesiones. Esta sigue abierta.')} />}
      </>}
    </div>}
  </section>
}
