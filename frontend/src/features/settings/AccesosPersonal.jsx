import { useState } from 'react'
import api, { apiErrorMessage } from '../../axios'
import { todayLima } from '../../shared/dates'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'

const control = 'w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm'
const button = 'rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-teal-800 disabled:opacity-50'
const hoy = todayLima

function Acciones({ persona, onChanged, onClave }) {
  const [accion, setAccion] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function submit(event) {
    event.preventDefault()
    if (busy) return
    const datos = Object.fromEntries(new FormData(event.currentTarget))
    const asignacion = datos.asignacion
    delete datos.asignacion
    if (datos.vigente_hasta === '') datos.vigente_hasta = null
    const base = `/accesos-personal/${persona.id}`
    const url = accion === 'conceder' ? base : accion === 'revocar' ? `${base}/${asignacion}/revocar` : `${base}/restablecer`
    setBusy(true); setError('')
    try {
      const response = await api.post(url, datos)
      onClave(response.data.clave_temporal || '')
      setAccion(''); onChanged()
    } catch (failure) { setError([409, 422].includes(failure.response?.status) && !failure.response?.data?.errors && typeof failure.response?.data?.message === 'string' ? failure.response.data.message : apiErrorMessage(failure)) } finally { setBusy(false) }
  }
  return <details className="mt-3"><summary className="cursor-pointer text-teal-800">Gestionar acceso</summary>
    {error && <p role="alert" className="my-3 text-rose-800">{error}</p>}
    <form className="mt-3" onSubmit={submit} aria-busy={busy}><fieldset disabled={busy} className="space-y-3">
      <label className="grid gap-2">Acción<select required className={control} value={accion} onChange={event => setAccion(event.target.value)}><option value="">Seleccionar</option><option value="conceder">Conceder rol de asistente</option><option value="revocar">Revocar asignación</option><option value="restablecer">Restablecer contraseña del asistente</option></select></label>
      {accion === 'conceder' && <>{!persona.activo && <p className="rounded-lg bg-amber-50 p-3 text-sm text-amber-950">Al conceder el acceso se habilitará esta cuenta y se generará una contraseña temporal que deberá cambiar al ingresar. El rol comenzará en la fecha indicada.</p>}<label className="grid gap-2">Vigente desde<input required name="vigente_desde" type="date" defaultValue={hoy()} min={hoy()} className={control} /></label><label className="grid gap-2">Vigente hasta (opcional)<input name="vigente_hasta" type="date" min={hoy()} className={control} /></label></>}
      {accion === 'revocar' && <label className="grid gap-2">Asignación<select required name="asignacion" className={control}><option value="">Seleccionar</option>{persona.usuario_roles_por_usuario.map(rol => <option key={rol.id} value={rol.id}>{rol.vigente_desde?.slice(0, 10)} → {rol.vigente_hasta?.slice(0, 10) || 'Sin fin'}</option>)}</select></label>}
      {accion && <><label className="grid gap-2">Motivo<textarea required name="motivo" maxLength={1000} className={control} /></label><button className={button} disabled={busy}>{busy ? 'Guardando…' : 'Registrar operación'}</button></>}
    </fieldset></form>
  </details>
}

export default function AccesosPersonal() {
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const [clave, setClave] = useState('')
  const request = useLiveQuery(`/accesos-personal?q=${encodeURIComponent(query)}&page=${page}`)
  const result = request.data
  return <section className="rounded-2xl border border-slate-200 bg-white p-6">
    <h2 className="text-lg font-semibold text-slate-900">Usuarios y accesos</h2>
    <p className="mt-2 text-sm text-slate-600">El asistente consulta, carga datos/documentos y recepciona. No revisa, anula, administra cuentas ni accede a datos sensibles. Las cuentas de administración se gestionan desde la plataforma.</p>
    {clave && <div role="status" className="my-5 rounded-xl bg-amber-50 p-4 text-sm text-amber-950"><p>Contraseña temporal: <strong className="select-all font-mono">{clave}</strong></p><p>Entrégala de forma privada. La persona deberá cambiarla al ingresar.</p><button type="button" className={`${button} mt-3`} onClick={() => setClave('')}>Ocultar contraseña</button></div>}
    <form className="my-5 flex gap-3" onSubmit={event => { event.preventDefault(); setQuery(search.trim()); setPage(1); setClave('') }}><label className="flex-1"><span className="sr-only">Buscar personal para asignar acceso</span><input className={control} value={search} maxLength={120} onChange={event => setSearch(event.target.value)} placeholder="Nombre, documento o correo" /></label><button className={button}>Buscar</button></form>
    {request.error && <p role="alert" className="text-rose-800">{apiErrorMessage(request.error)} <button className={button} onClick={request.retry}>Reintentar</button></p>}
    {request.loading && !result && <p role="status">Cargando personas…</p>}
    {result && <><ul className="grid gap-4 lg:grid-cols-2">{result.data.map(persona => <li key={persona.id} className="rounded-xl border border-slate-200 p-4 text-sm"><h3 className="font-semibold">{[persona.nombres, persona.apellido_paterno, persona.apellido_materno].filter(Boolean).join(' ')}</h3><p className="mt-1 break-all text-slate-600">{persona.email_institucional || 'Sin correo institucional'} · {persona.activo ? 'Activa' : 'Inactiva'}</p><p className="mt-2 text-xs">Asignaciones de asistente: {persona.usuario_roles_por_usuario.length}</p><Acciones persona={persona} onChanged={request.retry} onClave={setClave} /></li>)}</ul>{!result.data.length && <p>No se encontraron personas.</p>}
      <nav aria-label="Paginación de usuarios y accesos" className="mt-5 flex flex-wrap items-center gap-3"><button className={button} disabled={request.loading || page <= 1} onClick={() => setPage(page - 1)}>Anterior</button><span>{result.current_page} / {result.last_page}</span><button className={button} disabled={request.loading || page >= result.last_page} onClick={() => setPage(page + 1)}>Siguiente</button></nav>
    </>}
  </section>
}
