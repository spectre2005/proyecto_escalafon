import { useState } from 'react'
import { apiErrorMessage } from '../../../../axios'
import { puede, useSesion } from '../../../auth/session'
import { AnularForm } from '../seccion01/RegistrosPanel'
import { Bloqueo } from '../seccion02/Registros'
import { EnZona, LineaDocumento } from '../documentos/EspacioSeccion'

const button = 'rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50'

// Permisos por defecto (sección 07). La sección 09 pasa los suyos (legajo.editar / sistema.administrar + datos_sensibles.ver).
const PERMISOS_07 = { registrar: ['seccion07.registrar'], anular: ['seccion07.registrar', 'sistema.administrar'] }

/**
 * Bloque de datos de una subsección o bloque de la ficha: registrar y corregir, y anular con motivo, según los permisos
 * indicados (todos los de la lista). Los registros llegan sin anulados desde el backend; el backend valida siempre.
 * bloqueo: texto que explica por qué no se puede registrar; deshabilita el botón y se muestra.
 * adjuntar(record): «Adjuntar documento» del registro (AdjuntarDocumento), si puede acreditarse con algún tipo.
 * documento(record): el documento que lo acredita (DocumentoDelRegistro), en lugar de «Enlazado / Sin documento».
 * El formulario de registrar o corregir se abre en una ventana superpuesta (EnZona).
 */
export default function Registros({ titulo, agregar, vacio, registros, bloqueo, tituloRegistro, subtitulo, campos, renderForm, onAnular, aviso, permisos = PERMISOS_07, adjuntar, documento }) {
  const sesion = useSesion()
  const puedeRegistrar = permisos.registrar.every(permiso => puede(sesion, permiso))
  const puedeAnular = permisos.anular.every(permiso => puede(sesion, permiso))
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
      setNotice('Registro anulado. Ya no se cuenta en la sección.')
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
    {aviso}
    {notice && <p role="status" className="mb-4 rounded-lg bg-teal-50 p-3 text-sm text-teal-800">{notice}</p>}
    {error && <p role="alert" className="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
    {editing && !(bloqueo && !editing.id) && <EnZona onCancel={() => setEditing(null)} titulo={editing.id ? `Corregir: ${tituloRegistro(editing)}` : agregar}>{renderForm({ record: editing.id ? editing : null, onCancel: () => setEditing(null), onSaved: () => { setEditing(null); setNotice('Registro guardado.') } })}</EnZona>}
    {registros.length === 0 && <p className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">{vacio}</p>}
    <div className="space-y-4">{registros.map(record => <article key={record.id} className="rounded-2xl border border-slate-200 bg-white p-5">
      <div className="min-w-0"><h4 className="font-semibold text-slate-800">{tituloRegistro(record)}</h4>{subtitulo && <p className="mt-1 text-xs text-slate-500">{subtitulo(record)}</p>}</div>
      <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2">{campos.map(([label, render]) => <div key={label}><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-1 break-words text-slate-800">{render(record.values, record)}</dd></div>)}
        {!documento && <div><dt className="text-xs text-slate-500">Documento de respaldo</dt><dd className="mt-1 text-slate-800">{record.values.documento_id ? 'Enlazado' : 'Sin documento enlazado'}</dd></div>}
        <div><dt className="text-xs text-slate-500">Registrado por</dt><dd className="mt-1 break-words text-slate-800">{record.values.registrado_por || 'No registrado'}</dd></div>
      </dl>
      <LineaDocumento documento={documento?.(record)} adjuntar={adjuntar?.(record)} />
      {(puedeRegistrar || puedeAnular) && <div className="mt-5 flex flex-wrap items-center gap-4">
        {puedeRegistrar && <button disabled={ocupado} onClick={() => start(record, setEditing)} className="text-sm font-semibold text-teal-700 disabled:opacity-50">Corregir</button>}
        {puedeAnular && <button disabled={ocupado} onClick={() => start(record, setAnulando)} className="text-sm font-semibold text-rose-700 disabled:opacity-50">Anular</button>}
        {puedeRegistrar && !puedeAnular && <Bloqueo>Anular es solo de administración.</Bloqueo>}
      </div>}
      {anulando?.id === record.id && <AnularForm record={record} titulo={tituloRegistro} busy={busy} onCancel={() => setAnulando(null)} onConfirm={anular} />}
    </article>)}</div>
  </section>
}
