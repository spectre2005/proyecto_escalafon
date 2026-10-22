import { useEffect, useId, useRef, useState } from 'react'
import { apiErrorMessage } from '../../axios'
import { buscarPersonasEquipo, consultarEquipo, otorgarAccesoEquipo, renovarAccesoEquipo, restablecerClaveEquipo, revocarAccesoEquipo } from './equipoAccesosApi'

const campo = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800'
const boton = 'rounded-xl border border-slate-200 px-3 py-2 text-sm font-medium disabled:opacity-50'
const principal = `${boton} bg-teal-700 text-white`
const etiquetas = { vigente: 'Vigente', programado: 'Programado', vencido: 'Vencido', revocado: 'Revocado', inactivo: 'Inactivo' }

function DialogoEquipo({ seleccion, datos, cerrar, completado }) {
  const ref = useRef(null)
  const titulo = useId()
  const nuevo = seleccion.accion === 'agregar'
  const [modo, setModo] = useState('existente')
  const [consulta, setConsulta] = useState('')
  const [resultados, setResultados] = useState(null)
  const [persona, setPersona] = useState(null)
  const [perfil, setPerfil] = useState('asistente_legajo')
  const [hasta, setHasta] = useState(seleccion.registro?.vigente_hasta || '')
  const [busy, setBusy] = useState(false)
  const [buscando, setBuscando] = useState(false)
  const [error, setError] = useState('')
  const accion = seleccion.accion
  const requiereHasta = nuevo ? perfil === 'suplente_legajo' : seleccion.registro.perfil === 'suplente_legajo'
  const nombreAccion = { agregar: 'Agregar integrante', renovar: 'Renovar acceso', revocar: 'Revocar acceso', restablecer: 'Restablecer contraseña' }[accion]
  useEffect(() => {
    ref.current.showModal()
  }, [])

  useEffect(() => {
    if (modo !== 'existente' || !consulta.trim()) return
    const controller = new AbortController()
    const timer = setTimeout(() => {
      setBuscando(true)
      buscarPersonasEquipo(consulta.trim(), controller.signal).then(lista => {
        if (!controller.signal.aborted) setResultados(lista)
      }).catch(failure => {
        if (!controller.signal.aborted) setError(apiErrorMessage(failure))
      }).finally(() => {
        if (!controller.signal.aborted) setBuscando(false)
      })
    }, 150)
    return () => { clearTimeout(timer); controller.abort() }
  }, [consulta, modo])

  async function guardar(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    const form = new FormData(event.currentTarget)
    const motivo = form.get('motivo').trim()
    try {
      let credenciales = null
      if (nuevo) {
        const cuenta = modo === 'existente' ? { usuario_id: persona.id } : {
          nombres: form.get('nombres').trim(), apellido_paterno: form.get('apellido_paterno').trim(),
          numero_doc_identidad: form.get('numero_doc_identidad').trim(), email: form.get('email').trim(),
        }
        const resultado = await otorgarAccesoEquipo({ ...cuenta, perfil, vigente_desde: form.get('vigente_desde'), vigente_hasta: hasta || null, motivo })
        credenciales = resultado.credenciales
      } else if (accion === 'renovar') {
        await renovarAccesoEquipo(seleccion.registro.id, { vigente_hasta: hasta || null, motivo })
      } else if (accion === 'revocar') {
        await revocarAccesoEquipo(seleccion.registro.id, { motivo })
      } else {
        credenciales = await restablecerClaveEquipo(seleccion.registro.id, { motivo })
      }
      completado(credenciales, nombreAccion)
    } catch (failure) {
      const errores = failure.response?.data?.errors
      setError(errores ? Object.values(errores).flat().join(' ') : apiErrorMessage(failure))
      setBusy(false)
    }
  }

  return <dialog ref={ref} aria-labelledby={titulo} onCancel={event => { event.preventDefault(); if (!busy) cerrar() }} className="m-auto max-h-[90dvh] w-[calc(100%_-_2rem)] max-w-xl overflow-y-auto rounded-2xl p-0 text-slate-700 shadow-xl backdrop:bg-slate-900/35">
    <form onSubmit={guardar} aria-busy={busy} className="space-y-5 p-6">
      <h3 id={titulo} className="text-lg font-semibold">{nombreAccion}</h3>
      {!nuevo && <p className="text-sm">{seleccion.registro.persona.nombre} · {seleccion.registro.nombre_perfil}</p>}
      {accion === 'revocar' && <p className="text-sm text-slate-600">Perderá los permisos de esta asignación inmediatamente. Su cuenta, sus documentos y su historial se conservan.</p>}
      {accion === 'restablecer' && <p className="text-sm text-slate-600">Se generará una contraseña temporal. Las sesiones anteriores dejarán de ser válidas y deberá cambiarla al ingresar.</p>}
      <fieldset disabled={busy} className="space-y-4">
        {nuevo && <>
          <label className="grid gap-2 text-sm">Persona<select className={campo} value={modo} onChange={event => { setModo(event.target.value); setPersona(null) }}><option value="existente">Seleccionar persona existente</option><option value="nueva">Registrar una cuenta nueva</option></select></label>
          {modo === 'existente' ? <div className="space-y-3">
            <label className="grid gap-2 text-sm">Buscar por DNI, nombre o correo<input className={campo} value={consulta} onChange={event => { setConsulta(event.target.value); setPersona(null); setResultados(null); setBuscando(false); setError('') }} maxLength={100} /></label>
            {buscando && <p role="status" className="text-sm text-slate-500">Buscando personas…</p>}
            {resultados?.length === 0 && <p role="status" className="text-sm">No se encontraron personas activas.</p>}
            {resultados && <div className="max-h-48 space-y-2 overflow-auto">{resultados.map(item => <label key={item.id} className="flex items-start gap-3 rounded-xl border border-slate-200 p-3 text-sm"><input type="radio" name="persona" checked={persona?.id === item.id} onChange={() => setPersona(item)} /><span>{item.nombre}<span className="block text-xs text-slate-500">{item.documento} · {item.email}</span></span></label>)}</div>}
            <p className="text-xs text-slate-500">Se conservará su contraseña actual. Las cuentas con otros roles se gestionan por separado.</p>
          </div> : <>
            <p className="text-xs text-slate-500">Se creará su cuenta con contraseña temporal, sin abrir un legajo ni registrar un vínculo laboral.</p>
            <label className="grid gap-2 text-sm">Nombres<input name="nombres" required maxLength={120} className={campo} autoComplete="off" /></label>
            <label className="grid gap-2 text-sm">Apellido paterno<input name="apellido_paterno" required maxLength={80} className={campo} autoComplete="off" /></label>
            <label className="grid gap-2 text-sm">DNI<input name="numero_doc_identidad" required inputMode="numeric" pattern="[0-9]{8}" maxLength={8} className={campo} autoComplete="off" /></label>
            <label className="grid gap-2 text-sm">Correo<input name="email" type="email" required maxLength={255} className={campo} autoComplete="off" /></label>
          </>}
          <label className="grid gap-2 text-sm">Perfil<select className={campo} value={perfil} onChange={event => setPerfil(event.target.value)}>{datos.perfiles.map(item => <option key={item.codigo} value={item.codigo}>{item.nombre}</option>)}</select></label>
          <div className="rounded-xl bg-slate-50 p-4 text-xs"><p className="font-semibold">Permisos que recibirá</p><ul className="mt-2 list-disc space-y-1 pl-4">{datos.perfiles.find(item => item.codigo === perfil)?.permisos.map(permiso => <li key={permiso}>{permiso}</li>)}</ul><p className="mt-3">Sin acceso a datos sensibles, gestión de cuentas ni configuración de módulos.</p></div>
          <label className="grid gap-2 text-sm">Fecha de inicio<input type="date" name="vigente_desde" required min={datos.hoy} defaultValue={datos.hoy} className={campo} /></label>
        </>}
        {(nuevo || accion === 'renovar') && <>
          <label className="grid gap-2 text-sm">Fecha de término {requiereHasta ? '(obligatoria)' : '(opcional)'}<input type="date" required={requiereHasta} min={datos.hoy} value={hasta} onChange={event => setHasta(event.target.value)} className={campo} /></label>
          <p className="text-xs text-slate-500">El acceso incluye todo el día de término. Zona horaria del sistema: {datos.zona_horaria}.</p>
        </>}
        <label className="grid gap-2 text-sm">Motivo<textarea name="motivo" required minLength={5} maxLength={500} rows={3} className={campo} /></label>
      </fieldset>
      {error && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
      <div className="flex justify-end gap-3"><button type="button" className={boton} disabled={busy} onClick={cerrar}>Cancelar</button><button className={principal} disabled={busy || (nuevo && modo === 'existente' && !persona)}>{busy ? 'Guardando…' : 'Confirmar'}</button></div>
    </form>
  </dialog>
}

export default function EquipoAccesosConfig() {
  const [datos, setDatos] = useState(null)
  const [revision, setRevision] = useState(0)
  const [seleccion, setSeleccion] = useState(null)
  const [error, setError] = useState('')
  const [aviso, setAviso] = useState('')
  const [credenciales, setCredenciales] = useState(null)
  useEffect(() => {
    const controller = new AbortController()
    consultarEquipo(controller.signal).then(resultado => {
      if (!controller.signal.aborted) { setDatos(resultado); setError('') }
    }).catch(failure => { if (!controller.signal.aborted) setError(apiErrorMessage(failure)) })
    return () => controller.abort()
  }, [revision])

  function abrir(accion, registro) {
    setCredenciales(null)
    setAviso('')
    setSeleccion({ accion, registro })
  }

  function completado(claves, accion) {
    setSeleccion(null)
    setCredenciales(claves)
    setAviso(`${accion}: cambio guardado y registrado en la auditoría.`)
    setRevision(valor => valor + 1)
  }

  return <section aria-label="Equipo de trabajo" className="rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
    <div className="flex flex-wrap items-start justify-between gap-4"><div><h2 className="font-semibold text-slate-800">Equipo de trabajo</h2><p className="mt-2 max-w-2xl text-sm text-slate-500">Otorga acceso a asistentes o suplentes autorizados por el administrador. Los docentes responden por correo y no necesitan una cuenta. Cada persona ingresa con su propia cuenta.</p></div><button type="button" className={principal} disabled={!datos || !!seleccion} onClick={() => abrir('agregar')}>Agregar integrante</button></div>
    {error && <p role="alert" className="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error} <button type="button" onClick={() => setRevision(valor => valor + 1)} className="underline">Reintentar</button></p>}
    {aviso && <p role="status" className="mt-4 rounded-xl bg-teal-50 p-3 text-sm text-teal-800">{aviso}</p>}
    {credenciales && <div className="mt-4 space-y-2 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm"><p className="font-semibold">Contraseña temporal</p><p>Correo: {credenciales.email}</p><p>Contraseña: <code className="select-all font-mono">{credenciales.password_temporal}</code></p><p>Entrégala a la persona. Deberá cambiarla al ingresar. Al cerrar este aviso no podrás volver a consultarla.</p><button type="button" className={boton} onClick={() => setCredenciales(null)}>Ya la guardé, cerrar</button></div>}
    {!datos && !error && <p role="status" className="mt-4 text-sm text-slate-500">Cargando equipo…</p>}
    {datos && <ul className="mt-5 divide-y divide-slate-100">{datos.asignaciones.length === 0 && <li className="py-4 text-sm text-slate-500">Todavía no hay accesos asignados.</li>}{datos.asignaciones.map(registro => <li key={registro.id} className="flex flex-wrap items-start justify-between gap-4 py-5">
      <div><p className="text-sm font-semibold text-slate-800">{registro.persona.nombre} <span className="ml-2 rounded-full bg-slate-100 px-2 py-1 text-xs font-normal">{etiquetas[registro.estado]}</span></p><p className="mt-2 text-xs text-slate-500">{registro.persona.email} · {registro.nombre_perfil}</p><p className="mt-1 text-xs text-slate-500">Desde {registro.vigente_desde} · {registro.vigente_hasta ? `Hasta ${registro.vigente_hasta}` : 'Sin fecha de término'}</p><p className="mt-2 max-w-xl break-words text-xs text-slate-600">{registro.motivo}</p>{registro.debe_cambiar_password && <p className="mt-1 text-xs text-amber-700">Debe cambiar su contraseña al ingresar.</p>}</div>
      {registro.estado !== 'revocado' && registro.estado !== 'inactivo' && <div className="flex flex-wrap gap-2"><button className={boton} type="button" disabled={!!seleccion} onClick={() => abrir('renovar', registro)}>Renovar</button>{registro.estado !== 'vencido' && <button className={boton} type="button" disabled={!!seleccion} onClick={() => abrir('restablecer', registro)}>Restablecer contraseña</button>}<button className={`${boton} text-rose-700`} type="button" disabled={!!seleccion} onClick={() => abrir('revocar', registro)}>Revocar</button></div>}
    </li>)}</ul>}
    {seleccion && <DialogoEquipo seleccion={seleccion} datos={datos} cerrar={() => setSeleccion(null)} completado={completado} />}
  </section>
}
