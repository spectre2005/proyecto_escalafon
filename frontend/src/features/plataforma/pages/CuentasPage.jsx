import { useEffect, useState } from 'react'
import plataforma, { mensajeError } from '../api'
import { WorkflowField, WorkflowPagination } from '../../../shared/WorkflowControls'
import { useSesionPlataforma } from '../sesion'

const boton = 'rounded-xl border border-teal-300 bg-white px-3 py-2 text-sm font-semibold text-teal-800 disabled:opacity-50'

function OperacionCuenta({ operacion, onSaved, onCancel }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const alta = operacion.tipo === 'alta'
  const password = alta || operacion.tipo === 'password'
  async function guardar(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true); setError('')
    try {
      const data = Object.fromEntries(new FormData(event.currentTarget))
      if (alta) await plataforma.post('/cuentas', data)
      else if (password) await plataforma.post(`/cuentas/${operacion.cuenta.id}/restablecer`, data)
      else await plataforma.patch(`/cuentas/${operacion.cuenta.id}/estado`, { ...data, activo: !operacion.cuenta.activo })
      onSaved()
    } catch (failure) { setError(mensajeError(failure)) } finally { setBusy(false) }
  }
  return <form onSubmit={guardar} className="my-5 space-y-4 rounded-xl border border-slate-200 bg-white p-5"><h2 className="font-semibold">{alta ? 'Crear cuenta de superadministración' : password ? 'Restablecer contraseña' : operacion.cuenta.activo ? 'Desactivar cuenta' : 'Activar cuenta'}{!alta && ` · ${operacion.cuenta.email}`}</h2>{alta && <><WorkflowField name="nombre" label="Nombre" maxLength={255} /><WorkflowField name="email" label="Correo" type="email" maxLength={255} /></>}{password && <><WorkflowField name="password" label="Contraseña nueva" type="password" autoComplete="new-password" maxLength={255} /><WorkflowField name="password_confirmation" label="Confirmar contraseña" type="password" autoComplete="new-password" maxLength={255} /><p className="text-sm text-slate-600">Comunica la contraseña al titular por un canal privado. Al restablecerla, las sesiones anteriores dejan de ser válidas.</p></>}<WorkflowField name="motivo" label="Motivo" type="textarea" minLength={5} maxLength={1000} />{error && <p role="alert" className="text-rose-800">{error}</p>}<div className="flex gap-3"><button className={boton} disabled={busy}>{busy ? 'Guardando…' : 'Confirmar'}</button><button type="button" className={boton} disabled={busy} onClick={onCancel}>Cancelar</button></div></form>
}

export default function CuentasPage() {
  const { administrador } = useSesionPlataforma()
  const [page, setPage] = useState(1)
  const [busqueda, setBusqueda] = useState('')
  const [q, setQ] = useState('')
  const [intento, setIntento] = useState(0)
  const [estado, setEstado] = useState({ loading: true })
  const [operacion, setOperacion] = useState(null)
  const [mensaje, setMensaje] = useState('')
  useEffect(() => {
    const controller = new AbortController()
    plataforma.get(`/cuentas?page=${page}&q=${encodeURIComponent(q)}`, { signal: controller.signal }).then(response => setEstado({ data: response.data })).catch(error => { if (!controller.signal.aborted) setEstado({ error: mensajeError(error) }) })
    return () => controller.abort()
  }, [page, q, intento])
  function recargar() { setEstado({ loading: true }); setIntento(value => value + 1) }
  return <section className="space-y-5"><h1 className="text-2xl font-bold">Cuentas de superadministración</h1><p className="text-sm text-slate-600">Cuentas del panel de plataforma. La gestión de administradores institucionales se encuentra dentro de cada institución.</p><button type="button" className={boton} onClick={() => setOperacion({ tipo: 'alta' })}>Crear cuenta</button><form onSubmit={event => { event.preventDefault(); setPage(1); setQ(busqueda.trim()); recargar() }} className="flex gap-3"><label className="flex-1"><span className="sr-only">Buscar por nombre o correo</span><input className="w-full rounded-xl border border-slate-300 p-3" maxLength={120} value={busqueda} onChange={event => setBusqueda(event.target.value)} placeholder="Nombre o correo" /></label><button className={boton}>Buscar</button></form>{mensaje && <p role="status" className="text-teal-800">{mensaje}</p>}{operacion && <OperacionCuenta key={`${operacion.tipo}:${operacion.cuenta?.id || 'nueva'}`} operacion={operacion} onCancel={() => setOperacion(null)} onSaved={() => { setOperacion(null); setMensaje('Operación guardada y registrada en auditoría.'); recargar() }} />}{estado.loading && <p role="status">Cargando cuentas…</p>}{estado.error && <p role="alert">{estado.error} <button type="button" className={boton} onClick={recargar}>Reintentar</button></p>}{estado.data && <>{!estado.data.data.length && <p>No se encontraron cuentas.</p>}{estado.data.data.map(cuenta => <article key={cuenta.id} className="space-y-3 rounded-xl border border-slate-200 bg-white p-5"><h2 className="font-semibold">{cuenta.nombre}</h2><p className="text-sm">{cuenta.email} · {cuenta.activo ? 'Activa' : 'Inactiva'}</p>{cuenta.id === administrador?.id ? <p className="text-sm text-slate-600">Tu cuenta. Cambia tu contraseña desde Mi cuenta.</p> : <div className="flex flex-wrap gap-3"><button type="button" className={boton} onClick={() => setOperacion({ tipo: 'estado', cuenta })}>{cuenta.activo ? 'Desactivar' : 'Activar'}</button><button type="button" className={boton} onClick={() => setOperacion({ tipo: 'password', cuenta })}>Restablecer contraseña</button></div>}</article>)}<WorkflowPagination data={estado.data} setPage={value => { setPage(value); setEstado({ loading: true }) }} loading={estado.loading} /></>}</section>
}
