import { useState } from 'react'
import api, { apiErrorMessage } from '../../axios'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'
import { usePuede } from '../auth/session'

const card = 'rounded-2xl border border-slate-200 bg-white p-6'
export default function SectionAccessPage() {
  const canEdit = usePuede('sistema.administrar')
  const query = useLiveQuery(canEdit ? '/acceso-secciones' : null)
  const [draft, setDraft] = useState(null)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState('')
  const rows = draft?.rows || query.data?.data || []
  function change(id, key, value) {
    setDraft({ version: draft?.version || query.data.version, rows: rows.map(row => row.id === id ? { ...row, [key]: value } : row) })
    setNotice('')
  }
  async function save() {
    setBusy(true)
    setNotice('')
    try {
      const response = await api.put('/acceso-secciones', { secciones: rows.map(({ id, activa, disponibilidad }) => ({ id, activa, disponibilidad })) }, { headers: { 'If-Match': draft?.version || query.data.version } })
      query.setData(response.data)
      setDraft(null)
      setNotice('Cambios guardados. El menú del legajo ya refleja esta configuración.')
    } catch (error) { setNotice(error.response?.data?.message || apiErrorMessage(error)) }
    finally { setBusy(false) }
  }
  if (!canEdit) return <p role="alert">Necesitas permisos de administración.</p>
  return <div>
    <p className="text-xs font-semibold uppercase tracking-widest text-teal-700">Acceso y autorización</p>
    <h1 className="mt-2 text-3xl font-bold text-slate-800">Secciones activas del legajo</h1>
    <p className="mt-3 text-sm text-slate-500">Activa o desactiva las secciones que la institución usa. Se conserva la numeración y la información existente.</p>
    {query.loading && <p role="status" className="mt-6">Cargando secciones…</p>}
    {query.error && <p role="alert" className="mt-6 text-rose-700">No se pudo consultar la configuración. <button onClick={query.retry} className="underline">Reintentar</button></p>}
    {rows.length > 0 && <div className="mt-8 grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
      <div><fieldset disabled={busy || !!query.error} className={`${card} divide-y divide-slate-100 disabled:opacity-60`}>
        {rows.map(row => <div key={row.id} className="flex items-start gap-4 py-5 first:pt-0 last:pb-0">
          <span className="rounded-lg bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-500">{String(row.id).padStart(2, '0')}</span>
          <div className="min-w-0 flex-1"><h2 className="text-sm font-semibold text-slate-800">{row.nombre}</h2><p className="mt-1 text-xs leading-relaxed text-slate-500">{row.restriccion || row.descripcion || 'Datos y documentos de esta sección.'}</p>
            <label className="mt-3 block text-xs text-slate-500">Disponibilidad<select aria-label={`Disponibilidad: ${row.nombre}`} value={row.disponibilidad} onChange={e => change(row.id, 'disponibilidad', e.target.value)} className="ml-3 rounded-lg border border-slate-200 p-2 text-slate-700 disabled:bg-slate-100"><option value="siempre_activa">Siempre activa</option><option value="periodo_pendiente">Período pendiente</option></select></label>
          </div>
          <button type="button" role="switch" aria-checked={row.activa} aria-label={`Activar ${row.nombre}`} onClick={() => change(row.id, 'activa', !row.activa)} className={`relative h-6 w-11 shrink-0 rounded-full focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-teal-700 ${row.activa ? 'bg-teal-700' : 'bg-slate-300'}`}><span className={`absolute top-1 size-4 rounded-full bg-white transition-all ${row.activa ? 'left-6' : 'left-1'}`} /></button>
        </div>)}
      </fieldset><div className="mt-5 flex flex-wrap gap-3"><button disabled={busy || !draft || !!query.error} onClick={save} className="rounded-xl bg-teal-700 px-5 py-3 text-sm font-semibold text-white disabled:opacity-40">{busy ? 'Guardando…' : 'Guardar cambios'}</button><button disabled={busy} onClick={() => { setDraft(null); setNotice(''); query.retry() }} className="rounded-xl bg-slate-100 px-5 py-3 text-sm text-slate-600">Restaurar</button>{draft && <p className="self-center text-xs text-amber-700">Cambios sin guardar</p>}</div>{notice && <p role="status" className="mt-4 rounded-xl bg-slate-100 p-4 text-sm">{notice}</p>}</div>
      <aside className="space-y-5 xl:sticky xl:top-6"><section className={card}><h2 className="font-semibold text-slate-800">Así verá el menú el legajo</h2><ol className="mt-4 space-y-3">{rows.filter(row => row.activa).map(row => <li key={row.id} className="flex gap-3 text-xs text-slate-600"><span className="font-semibold text-teal-700">{String(row.id).padStart(2, '0')}</span>{row.nombre}</li>)}</ol>{!rows.some(row => row.activa) && <p className="mt-4 text-sm text-slate-500">No hay secciones visibles.</p>}</section>
      <section className={card}><h2 className="font-semibold text-slate-800">Qué cambia al guardar</h2><ul className="mt-4 list-disc space-y-3 pl-4 text-xs leading-relaxed text-slate-600"><li>Las categorías y documentos de una sección desactivada dejan de estar disponibles.</li><li>Los documentos existentes se conservan.</li><li>Los permisos personales siguen aplicándose.</li><li>Período pendiente identifica una configuración pendiente; el interruptor determina si está visible.</li><li>Restaurar recupera la última configuración guardada.</li></ul></section></aside>
    </div>}
  </div>
}
