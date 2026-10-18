import { useEffect, useId, useRef, useState } from 'react'
import Icon from '../../components/ui/Icon'
import { apiErrorMessage } from '../../axios'
import { consultarModulosSistema, guardarModuloSistema } from './modulosSistemaApi'

function ConfirmarModulo({ modulo, guardar, cerrar }) {
  const dialogo = useRef(null)
  const titulo = useId()
  const [motivo, setMotivo] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  useEffect(() => { dialogo.current.showModal() }, [])
  async function confirmar(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    try {
      await guardar(modulo.codigo, { activa: !modulo.activa, activa_actual: modulo.activa, motivo: motivo.trim() })
      cerrar()
    } catch (failure) {
      setError(failure.response?.data?.message || apiErrorMessage(failure))
      setBusy(false)
    }
  }
  return <dialog ref={dialogo} aria-labelledby={titulo} onCancel={event => { event.preventDefault(); if (!busy) cerrar() }} className="m-auto w-[calc(100%_-_2rem)] max-w-lg rounded-2xl p-0 text-slate-700 shadow-xl backdrop:bg-slate-900/35">
    <form onSubmit={confirmar} aria-busy={busy} className="space-y-5 p-6">
      <h3 id={titulo} className="text-lg font-semibold">{modulo.activa ? 'Desactivar' : 'Activar'} Evaluaciones</h3>
      <p className="text-sm leading-relaxed text-slate-500">{modulo.activa ? 'Se ocultará la consulta general del menú principal y se bloqueará su acceso directo.' : 'La consulta general volverá a estar disponible en el menú para quienes tengan permiso.'} La sección 07 del legajo y los reportes mantienen su funcionamiento.</p>
      <label className="grid gap-2 text-xs font-medium text-slate-500">Motivo del cambio<textarea required minLength={5} maxLength={500} value={motivo} onChange={event => setMotivo(event.target.value)} disabled={busy} rows={3} className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 outline-none focus:border-teal-500" /></label>
      {error && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
      <div className="flex justify-end gap-3"><button type="button" disabled={busy} onClick={cerrar} className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm">Cancelar</button><button type="submit" disabled={busy || motivo.trim().length < 5} className="rounded-xl bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{busy ? 'Guardando…' : modulo.activa ? 'Confirmar desactivación' : 'Confirmar activación'}</button></div>
    </form>
  </dialog>
}

export default function ModulosSistemaConfig({ consultar = consultarModulosSistema, guardar = guardarModuloSistema, onCambio }) {
  const [datos, setDatos] = useState(null)
  const [error, setError] = useState('')
  const [aviso, setAviso] = useState('')
  const [seleccion, setSeleccion] = useState(null)
  const [revision, setRevision] = useState(0)
  useEffect(() => {
    const controller = new AbortController()
    consultar(controller.signal).then(data => { if (!controller.signal.aborted) { setDatos(data); setError('') } }).catch(failure => { if (!controller.signal.aborted) setError(apiErrorMessage(failure)) })
    return () => controller.abort()
  }, [consultar, revision])
  async function cambiar(codigo, valores) {
    let modulo
    try {
      modulo = await guardar(codigo, valores)
    } catch (failure) {
      setRevision(valor => valor + 1)
      throw failure
    }
    setDatos(actual => actual.map(item => item.codigo === codigo ? modulo : item))
    setAviso(`Evaluaciones quedó ${modulo.activa ? 'activado' : 'desactivado'} para esta institución. El cambio se registró en la auditoría.`)
    setRevision(valor => valor + 1)
    onCambio?.()
  }
  return <section aria-label="Módulos del sistema" className="rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
    <div className="mb-5 flex items-start gap-3"><span className="grid size-11 shrink-0 place-items-center rounded-xl bg-teal-50 text-teal-700"><Icon name="settings" /></span><div><h2 className="font-semibold text-slate-800">Módulos del sistema</h2><p className="mt-1 text-xs leading-relaxed text-slate-500">Activa los accesos del menú principal que utiliza esta institución. Estos ajustes son independientes de las secciones del legajo.</p></div></div>
    {error && <p role="alert" className="mb-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error} <button type="button" onClick={() => setRevision(valor => valor + 1)} className="font-semibold underline">Reintentar</button></p>}
    {aviso && <p role="status" className="mb-4 rounded-xl bg-teal-50 p-3 text-sm text-teal-800">{aviso}</p>}
    {!datos && !error && <p role="status" className="text-sm text-slate-500">Cargando módulos…</p>}
    {datos?.map(modulo => <div key={modulo.codigo} className="flex flex-wrap items-start justify-between gap-5 rounded-xl border border-slate-200 p-4"><div className="min-w-0 flex-1 basis-64"><div className="flex flex-wrap items-center gap-2"><h3 className="text-sm font-semibold text-slate-800">{modulo.nombre}</h3><span className={`rounded-full px-2.5 py-1 text-[11px] font-medium ${modulo.activa ? 'bg-teal-50 text-teal-700' : 'bg-slate-100 text-slate-500'}`}>{modulo.activa ? 'Activo' : 'Desactivado'}</span></div><p className="mt-2 text-xs leading-relaxed text-slate-500">{modulo.descripcion}</p>{modulo.ultimo_cambio && <p className="mt-3 text-xs leading-relaxed text-slate-400">Último cambio: {modulo.ultimo_cambio.fecha ? new Date(modulo.ultimo_cambio.fecha).toLocaleString('es-PE', { timeZone: 'America/Lima' }) : ''}{modulo.ultimo_cambio.por ? ` · ${modulo.ultimo_cambio.por}` : ''} · {modulo.ultimo_cambio.motivo}</p>}</div><button type="button" role="switch" aria-checked={modulo.activa} aria-label={modulo.nombre} disabled={!!seleccion} onClick={() => { setSeleccion(modulo); setAviso('') }} className="inline-flex shrink-0 items-center gap-3 rounded-xl border border-slate-200 px-3 py-2.5 text-xs font-semibold text-slate-700 focus-visible:outline-2 focus-visible:outline-teal-700"><span className={`flex h-6 w-11 items-center rounded-full p-0.5 transition-colors ${modulo.activa ? 'bg-teal-700' : 'bg-slate-200'}`}><span className={`size-5 rounded-full bg-white shadow-sm transition-transform ${modulo.activa ? 'translate-x-5' : ''}`} /></span>{modulo.activa ? 'Desactivar' : 'Activar'}</button></div>)}
    {seleccion && <ConfirmarModulo modulo={seleccion} guardar={cambiar} cerrar={() => setSeleccion(null)} />}
  </section>
}
