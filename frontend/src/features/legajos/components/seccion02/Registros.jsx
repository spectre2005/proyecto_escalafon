import DocumentoModal from '../documentos/DocumentoModal'
import { cloneElement, isValidElement, useState } from 'react'
import { apiErrorMessage } from '../../../../axios'
import { usePuede } from '../../../auth/session'
import { AnularForm } from '../seccion01/RegistrosPanel'
import { EnZona } from '../documentos/EspacioSeccion'

const button = 'rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50'

// Motivo visible de un botón deshabilitado.
export function Bloqueo({ children }) {
  return <span className="text-xs text-slate-500">{children}</span>
}

/**
 * Bloque de datos de una subsección de la 02: registrar, corregir (solo registros abiertos) y anular con
 * motivo cuando el esquema lo permite. Los registros llegan ya filtrados (sin anulados) desde el backend.
 * bloqueo: texto que explica por qué no se puede registrar (sin legajo, sin vínculo); deshabilita el botón y se muestra.
 * adjuntar(record): «Adjuntar documento» del registro (AdjuntarDocumento), si puede acreditarse con algún tipo.
 * corregir: { etiqueta, puede(record), motivo } — motivo se muestra cuando el registro ya no se corrige.
 * documento(record): el documento que lo acredita (DocumentoDelRegistro), dentro de la tarjeta.
 * El formulario de registrar o corregir se abre en la zona de trabajo de la sección (EnZona).
 */
export default function Registros({ titulo, agregar, vacio, registros, bloqueo, tituloRegistro, subtitulo, campos, renderForm, corregir, onAnular, detalle, adjuntar, documento }) {
  const puedeRegistrar = usePuede('legajo.editar')
  const puedeAnular = usePuede('sistema.administrar') && !!onAnular
  const [viendo, setViendo] = useState(null)
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
      await onAnular(anulando, motivo)
      setAnulando(null)
      setNotice('Registro anulado.')
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

  return <section aria-label={titulo} className="mb-8">
    <div className="mb-4 flex flex-wrap items-center justify-between gap-3"><h3 className="text-base font-semibold text-slate-800">{titulo}</h3>{puedeRegistrar && agregar && <div className="flex flex-wrap items-center gap-3">{bloqueo && <Bloqueo>{bloqueo}</Bloqueo>}<button disabled={ocupado || !!bloqueo} onClick={() => start({}, setEditing)} className={button}>{agregar}</button></div>}</div>
    {notice && <p role="status" className="mb-4 rounded-lg bg-teal-50 p-3 text-sm text-teal-800">{notice}</p>}
    {error && <p role="alert" className="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
    {editing && !bloqueo && <EnZona titulo={editing.id ? `Corregir: ${tituloRegistro(editing)}` : agregar}>{renderForm({ record: editing.id ? editing : null, onCancel: () => setEditing(null), onSaved: () => { setEditing(null); setNotice('Registro guardado.') } })}</EnZona>}
    {registros.length === 0 && <p className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">{vacio}</p>}
    <div className="space-y-4">{registros.map(record => {
      const values = record.values
      const corregible = corregir && puedeRegistrar && !bloqueo && corregir.puede(record)
      const motivoNoCorrige = corregir && puedeRegistrar && !corregir.puede(record) && corregir.motivo
      return <article key={record.id} className="rounded-2xl border border-slate-200 bg-white p-5">
        <div className="min-w-0"><h4 className="font-semibold text-slate-800">{tituloRegistro(record)}</h4>{subtitulo && <p className="mt-1 text-xs text-slate-500">{subtitulo(record)}</p>}</div>
        <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
          <button type="button" onClick={() => setViendo(record)} className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-teal-700 hover:bg-teal-50">Ver detalles</button>
          {(() => { const nodo = documento?.(record); return isValidElement(nodo) ? cloneElement(nodo, { compacto: true }) : nodo })()}
          {corregible && <button disabled={ocupado} onClick={() => start(record, setEditing)} className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-teal-700 disabled:opacity-50">{corregir.etiqueta}</button>}
          {adjuntar?.(record)}
          {puedeAnular && <button disabled={ocupado} onClick={() => start(record, setAnulando)} className="rounded-lg px-3 py-2 text-sm font-semibold text-rose-700 disabled:opacity-50">Anular</button>}
          {motivoNoCorrige && <Bloqueo>{motivoNoCorrige}</Bloqueo>}
        </div>
        {viendo?.id === record.id && <DocumentoModal titulo="Detalles del registro" onCancel={() => setViendo(null)}>
          <h3 className="mb-4 font-semibold text-slate-800">{tituloRegistro(record)}</h3>
          <dl className="grid gap-4 rounded-xl border border-slate-200 bg-white p-5 text-sm sm:grid-cols-2">{campos.map(([label, render]) => <div key={label}><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-1 break-words text-slate-800">{render(values, record)}</dd></div>)}
            {'registrado_por' in values && <div><dt className="text-xs text-slate-500">Registrado por</dt><dd className="mt-1 text-slate-800">{values.registrado_por || 'No registrado'}</dd></div>}
          </dl>
          {detalle && <div className="mt-4">{detalle(record)}</div>}
        </DocumentoModal>}
        {anulando?.id === record.id && <AnularForm record={record} titulo={tituloRegistro} busy={busy} onCancel={() => setAnulando(null)} onConfirm={anular} />}
      </article>
    })}</div>
  </section>
}
