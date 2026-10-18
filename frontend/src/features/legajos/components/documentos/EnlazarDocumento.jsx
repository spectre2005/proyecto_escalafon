import DocumentoModal from './DocumentoModal'
import { useState } from 'react'
import { apiErrorMessage } from '../../../../axios'
import { enlazarDocumento } from '../../api/documentosApi'
import { folios } from '../../config/documentos'

/**
 * Recuperar un documento ya cargado que quedó sin registro (p. ej. se canceló el formulario de datos tras subirlo):
 * se enlaza a un registro existente compatible sin volver a subir el archivo, o se registran sus datos ahora.
 * opciones: registros que el documento puede acreditar ({ id, etiqueta }), con las mismas reglas que la carga (enlacesDe
 * del panel); el backend valida igual. onRegistrarNuevo: abre el formulario de datos con este documento (opcional).
 */
export default function EnlazarDocumento({ usuario, documento, opciones, onRegistrarNuevo, onDone, onCancel }) {
  const lista = opciones || []
  const [registro, setRegistro] = useState(lista.length === 1 ? lista[0].id : '')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const v = documento.values
  async function enlazar(event) {
    event.preventDefault()
    if (busy || !registro) return
    setBusy(true)
    setError('')
    try {
      await enlazarDocumento(usuario, documento.id, registro)
      onDone(lista.find(opcion => opcion.id === registro)?.etiqueta)
    } catch (failure) {
      setError(failure.response?.data?.errors?.enlace_id?.join(' ') || apiErrorMessage(failure))
    } finally {
      setBusy(false)
    }
  }
  return <DocumentoModal titulo="Enlazar documento" busy={busy} onCancel={onCancel}><form onSubmit={enlazar} aria-busy={busy} className="mt-4 rounded-2xl border border-teal-200 bg-white p-5">
    <h3 className="font-semibold text-slate-800">Enlazar documento sin registro</h3>
    <p className="mt-1 text-xs text-slate-500">Documento de folios {folios(v.folio_inicio, v.folio_fin)}{v.fecha_emision ? ` · emitido ${v.fecha_emision}` : ''}. No se vuelve a subir el archivo: solo se asocia al registro que acredita. El enlace no se cambia después.</p>
    {error && <p role="alert" className="mt-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
    {lista.length > 0 ? <label className="mt-4 grid gap-2 text-xs font-medium text-slate-600">Registro que acredita *
      <select required value={registro} onChange={event => setRegistro(event.target.value)} disabled={busy} className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-800">
        <option value="">Seleccionar registro</option>
        {lista.map(opcion => <option key={opcion.id} value={opcion.id}>{opcion.etiqueta}</option>)}
      </select>
    </label> : <p role="note" className="mt-4 rounded-lg bg-amber-50 p-3 text-xs text-amber-900">No hay registros compatibles sin documento para enlazar.{onRegistrarNuevo ? ' Puedes registrar sus datos ahora.' : ''}</p>}
    <div className="mt-4 flex flex-wrap justify-end gap-3">
      <button type="button" onClick={onCancel} disabled={busy} className="rounded-xl border border-slate-200 px-4 py-2 text-sm">Cancelar</button>
      {onRegistrarNuevo && <button type="button" onClick={onRegistrarNuevo} disabled={busy} className="rounded-xl border border-teal-200 bg-teal-50 px-4 py-2 text-sm font-semibold text-teal-800">Registrar sus datos</button>}
      {lista.length > 0 && <button type="submit" disabled={busy || !registro} className="rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{busy ? 'Enlazando…' : 'Enlazar'}</button>}
    </div>
  </form></DocumentoModal>
}
