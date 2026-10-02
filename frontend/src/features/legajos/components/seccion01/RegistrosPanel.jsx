import { useState } from 'react'
import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { apiErrorMessage } from '../../../../axios'
import { usePuede } from '../../../auth/session'
import { anularRegistro } from '../../api/seccion01Api'

const button = 'rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50'

export function AnularForm({ record, titulo, onCancel, onConfirm, busy }) {
  const [motivo, setMotivo] = useState('')
  return <form role="alertdialog" aria-label="Anular registro" onSubmit={event => { event.preventDefault(); onConfirm(motivo.trim()) }} className="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4">
    <p className="text-sm text-rose-900">Anular «{titulo(record)}». El registro no se borra: queda en el legajo marcado como anulado, con el motivo y tu nombre.</p>
    <label className="mt-3 grid gap-2 text-xs text-rose-900">Motivo de la anulación *<textarea required minLength={5} maxLength={1000} rows={2} value={motivo} onChange={event => setMotivo(event.target.value)} className="rounded-lg border border-rose-200 bg-white px-3 py-2 text-sm text-slate-800" /></label>
    <div className="mt-3 flex gap-3"><button type="button" disabled={busy} onClick={onCancel} className="rounded-lg border border-rose-200 bg-white px-3 py-2 text-sm">Cancelar</button><button disabled={busy || motivo.trim().length < 5} className="rounded-lg bg-rose-700 px-3 py-2 text-sm text-white disabled:opacity-50">{busy ? 'Anulando…' : 'Confirmar anulación'}</button></div>
  </form>
}

/**
 * Lista de registros de la sección 01 (familiares, declaraciones, certificados):
 * registrar, opcionalmente corregir, y anular con motivo. Nunca se eliminan.
 * El backend valida cada permiso; aquí solo se ocultan los botones.
 */
export default function RegistrosPanel({ usuario, tipo, titulo, agregar, vacio, tituloRegistro, subtitulo, campos, renderForm, editable = false }) {
  const [page, setPage] = useState(1)
  const request = useLiveQuery(`/expedientes/${usuario}/${tipo}?page=${page}`)
  const puedeRegistrar = usePuede('legajo.editar')
  const puedeAnular = usePuede('sistema.administrar')
  const [editing, setEditing] = useState(null)
  const [anulando, setAnulando] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const ocupado = !!editing || !!anulando

  async function anular(motivo) {
    if (busy) return
    setBusy(true)
    setError('')
    try {
      await anularRegistro(usuario, tipo, anulando.id, motivo)
      setAnulando(null)
      setNotice('Registro anulado.')
      request.retry()
    } catch (failure) {
      setError(failure.response?.status === 409 ? 'El registro ya estaba anulado.' : apiErrorMessage(failure))
    } finally {
      setBusy(false)
    }
  }

  function start(next, action) {
    action(next)
    setError('')
    setNotice('')
  }

  return <section aria-label={titulo}>
    <div className="mb-5 flex flex-wrap items-center justify-between gap-3"><h2 className="text-xl font-semibold text-slate-800">{titulo}</h2>{puedeRegistrar && <button disabled={ocupado} onClick={() => start({}, setEditing)} className={button}>{agregar}</button>}</div>
    {notice && <p role="status" className="mb-4 rounded-lg bg-teal-50 p-3 text-sm text-teal-800">{notice}</p>}
    {error && <p role="alert" className="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
    {editing && renderForm({ record: editing.id ? editing : null, onCancel: () => setEditing(null), onSaved: () => { setEditing(null); setNotice('Registro guardado.'); request.retry() } })}
    {request.error && <p role="alert" className="mb-4 text-sm text-rose-700">{request.error.response?.status === 403 ? 'No tienes permiso para ver esta sección.' : 'No se pudo actualizar la lista.'} <button onClick={request.retry} className="underline">Reintentar</button></p>}
    {request.loading && !request.data && <p role="status" className="rounded-xl bg-slate-100 p-6 text-sm text-slate-500">Cargando…</p>}
    {request.data?.data.length === 0 && <p className="rounded-xl border border-slate-200 bg-white p-8 text-sm text-slate-500">{vacio}</p>}
    <div className="space-y-4">{request.data?.data.map(record => {
      const values = record.values
      return <article key={record.id} className={`rounded-2xl border bg-white p-5 ${values.anulado ? 'border-rose-100 opacity-75' : 'border-slate-200'}`}>
        <div className="flex flex-wrap items-start justify-between gap-3"><div className="min-w-0"><h3 className={`font-semibold text-slate-800 ${values.anulado ? 'line-through decoration-rose-300' : ''}`}>{tituloRegistro(record)}</h3>{subtitulo && <p className="mt-1 text-xs text-slate-500">{subtitulo(record)}</p>}</div>{values.anulado && <span className="rounded-full bg-rose-50 px-2.5 py-1 text-[11px] text-rose-700">Anulado</span>}</div>
        <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">{campos.map(([label, render]) => <div key={label}><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-1 break-words text-slate-800">{render(values)}</dd></div>)}
          <div><dt className="text-xs text-slate-500">Registrado por</dt><dd className="mt-1 break-words text-slate-800">{values.registrado_por || 'No registrado'}</dd></div>
          {values.anulado && <div className="sm:col-span-2"><dt className="text-xs text-rose-700">Motivo de anulación</dt><dd className="mt-1 break-words text-slate-800">{values.motivo_anulacion}</dd></div>}
        </dl>
        {!values.anulado && (editable && puedeRegistrar || puedeAnular) && <div className="mt-5 flex gap-4">
          {editable && puedeRegistrar && <button disabled={ocupado} onClick={() => start(record, setEditing)} className="text-sm font-semibold text-teal-700 disabled:opacity-50">Corregir</button>}
          {puedeAnular && <button disabled={ocupado} onClick={() => start(record, setAnulando)} className="text-sm font-semibold text-rose-700 disabled:opacity-50">Anular</button>}
        </div>}
        {anulando?.id === record.id && <AnularForm record={record} titulo={tituloRegistro} busy={busy} onCancel={() => setAnulando(null)} onConfirm={anular} />}
      </article>
    })}</div>
    {request.data && request.data.last_page > 1 && <nav aria-label={`Páginas de ${titulo.toLowerCase()}`} className="mt-5 flex items-center gap-4 text-sm"><button disabled={page <= 1} onClick={() => setPage(page - 1)}>Anterior</button><span>{page} / {request.data.last_page}</span><button disabled={page >= request.data.last_page} onClick={() => setPage(page + 1)}>Siguiente</button></nav>}
  </section>
}
