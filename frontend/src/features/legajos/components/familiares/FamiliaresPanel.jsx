import { useState } from 'react'
import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { apiErrorMessage } from '../../../../axios'
import { deleteFamiliar } from '../../api/familiaresApi'
import FamiliarForm from './FamiliarForm'

export default function FamiliaresPanel({ usuario }) {
  const [page, setPage] = useState(1)
  const request = useLiveQuery(`/expedientes/${usuario}/familiares?page=${page}`)
  const [editing, setEditing] = useState(null)
  const [removing, setRemoving] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  async function remove() {
    if (busy) return
    setBusy(true)
    setError('')
    try {
      await deleteFamiliar(usuario, removing.id)
      setRemoving(null)
      setNotice('Familiar eliminado.')
      if (request.data?.data.length === 1 && page > 1) setPage(page - 1)
      else request.retry()
    } catch (failure) {
      setError(failure.response?.status === 409 ? 'No se puede eliminar: el familiar tiene registros asociados.' : apiErrorMessage(failure))
    } finally {
      setBusy(false)
    }
  }
  return <section aria-label="Familiares">
    <div className="mb-5 flex items-center justify-between gap-3"><h2 className="text-xl font-semibold text-slate-800">Familiares y parentesco</h2><button disabled={!!editing || !!removing} onClick={() => { setEditing({}); setNotice(''); setError('') }} className="rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Agregar familiar</button></div>
    {notice && <p role="status" className="mb-4 text-sm text-teal-700">{notice}</p>}
    {error && <p role="alert" className="mb-4 text-sm text-rose-700">{error}</p>}
    {editing && <FamiliarForm key={editing.id || 'new'} usuario={usuario} record={editing.id ? editing : null} onCancel={() => setEditing(null)} onSaved={() => { setEditing(null); setNotice('Familiar guardado.'); request.retry() }} />}
    {removing && <div role="alertdialog" aria-label="Confirmar eliminación" className="mb-5 rounded-xl border border-rose-200 bg-rose-50 p-5"><p className="text-sm">¿Eliminar a {removing.values.apellidos_nombres} del registro de familiares? Esta acción es permanente.</p><div className="mt-4 flex gap-3"><button disabled={busy} onClick={() => { setRemoving(null); setError('') }} className="rounded-lg border px-3 py-2 text-sm">Cancelar</button><button disabled={busy} onClick={remove} className="rounded-lg bg-rose-700 px-3 py-2 text-sm text-white">{busy ? 'Eliminando…' : 'Confirmar eliminación'}</button></div></div>}
    {request.error && <p role="alert" className="mb-4 text-sm text-rose-700">No se pudo actualizar la lista. <button onClick={request.retry} className="underline">Reintentar</button></p>}
    {request.loading && <p role="status">Cargando familiares…</p>}
    {request.data?.data.length === 0 && <p className="rounded-xl border bg-white p-8 text-sm text-slate-500">No hay familiares registrados.</p>}
    <div className="space-y-4">{request.data?.data.map(record => <article key={record.id} className="rounded-2xl border border-slate-200 bg-white p-5">
      <h3 className="font-semibold text-slate-800">{record.values.apellidos_nombres}</h3>
      <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">{[['parentesco', 'Parentesco'], ['tipo_doc_identidad', 'Tipo de documento'], ['numero_doc_identidad', 'Documento'], ['fecha_nacimiento', 'Fecha de nacimiento'], ['es_derechohabiente', 'Derechohabiente'], ['registrado_por', 'Registrado por'], ['documento_id', 'Documento de respaldo'], ['anulado', 'Anulado'], ['motivo_anulacion', 'Motivo de anulación']].map(([key, title]) => <div key={key}><dt className="text-xs text-slate-500">{title}</dt><dd className="mt-1 break-words">{typeof record.values[key] === 'boolean' ? record.values[key] ? 'Sí' : 'No' : record.values[key] || 'No registrado'}</dd></div>)}</dl>
      <div className="mt-5 flex gap-4"><button disabled={!!editing || !!removing} onClick={() => { setEditing(record); setError(''); setNotice('') }} className="text-sm font-semibold text-teal-700 disabled:opacity-50">Editar</button><button disabled={!!editing || !!removing} onClick={() => { setRemoving(record); setError(''); setNotice('') }} className="text-sm font-semibold text-rose-700 disabled:opacity-50">Eliminar</button></div>
    </article>)}</div>
    {request.data && request.data.last_page > 1 && <nav aria-label="Páginas de familiares" className="mt-5 flex items-center gap-4 text-sm"><button disabled={page <= 1} onClick={() => setPage(page - 1)}>Anterior</button><span>{page} / {request.data.last_page}</span><button disabled={page >= request.data.last_page} onClick={() => setPage(page + 1)}>Siguiente</button></nav>}
  </section>
}
