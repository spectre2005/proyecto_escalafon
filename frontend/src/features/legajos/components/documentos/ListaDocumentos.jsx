import { useState } from 'react'
import { apiErrorMessage } from '../../../../axios'
import { usePuede } from '../../../auth/session'
import { recepcionarDocumento } from '../../api/documentosApi'
import { folios, modalidadesPresentacion, relacionesTitular } from '../../config/documentos'
import { etiqueta } from '../../config/seccion01'
import { coloresEstadoDocumento, estadosDocumento } from '../../config/seccion02'
import VisorPdf from './VisorPdf'

const fecha = value => value ? value.split('-').reverse().join('/') : 'No registrada'
const kb = bytes => bytes ? `${Math.max(1, Math.round(bytes / 1024))} KB` : ''

/**
 * Documentos de un tipo, del más reciente al más antiguo, con metadatos, folios, estado y los datos que acreditan.
 * Acciones: ver el PDF (legajo.ver) y registrar la recepción (documento.recepcionar). No hay editar ni eliminar:
 * un documento subsanado se muestra atenuado junto al que lo corrige.
 * enlazado(documento): texto con el registro de datos que acredita (vínculo, adenda, inducción…) o null.
 * onSubsanar(documento): «Subsanar» en cada documento vigente (no subsanado). Abre la subsanación de ese documento, que el
 *   backend admite aunque el tipo ya no se ofrezca para cargas nuevas.
 */
export default function ListaDocumentos({ usuario, tipo, enlazado, onSubsanar }) {
  const puedeVer = usePuede('legajo.ver')
  const puedeRecepcionar = usePuede('documento.recepcionar')
  const [visor, setVisor] = useState(null)
  const [confirmando, setConfirmando] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const lista = tipo.lista || []
  if (!lista.length) return <p className="mt-2 text-[11px] text-slate-400">Sin documento cargado.</p>
  const titulo = documento => `${tipo.literal ? `${tipo.literal}) ` : ''}${tipo.nombre} · folios ${folios(documento.values.folio_inicio, documento.values.folio_fin)}`

  async function recepcionar(documento) {
    if (busy) return
    setBusy(true)
    setError('')
    try {
      await recepcionarDocumento(usuario, documento.id)
      setConfirmando(null)
    } catch (failure) {
      setError(failure.response?.status === 409 ? failure.response.data.message : apiErrorMessage(failure))
    } finally {
      setBusy(false)
    }
  }

  return <div className="mt-3 space-y-2">
    {error && <p role="alert" className="rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
    {lista.map(documento => {
      const v = documento.values
      const subsanado = documento.subsanado
      const datos = enlazado?.(documento)
      const subsana = v.subsana_a_documento_id && lista.find(d => d.id === v.subsana_a_documento_id)
      return <article key={documento.id} className={`rounded-xl border p-3 ${subsanado ? 'border-slate-100 bg-slate-50 opacity-70' : 'border-slate-200 bg-white'}`}>
        <div className="flex flex-wrap items-start justify-between gap-2">
          <p className="text-xs font-semibold text-slate-700">{tipo.codigo === 'S01_B_DOCUMENTO_IDENTIDAD' ? 'Copia de DNI' : `Folios ${folios(v.folio_inicio, v.folio_fin)}`}{documento.archivo?.num_paginas ? ` · ${documento.archivo.num_paginas} página(s)` : ''}{documento.archivo ? ` · ${kb(documento.archivo.tamano_bytes)}` : ''}</p>
          <div className="flex flex-wrap items-center gap-2">
            {subsanado && <span className="rounded-full bg-slate-200 px-2.5 py-1 text-[11px] font-medium text-slate-600">Subsanado</span>}
            <span className={`rounded-full px-2.5 py-1 text-[11px] font-medium ${coloresEstadoDocumento[documento.estado_visible] || 'bg-slate-100 text-slate-700'}`}>{etiqueta(estadosDocumento, documento.estado_visible)}</span>
          </div>
        </div>
        <dl className="mt-2 grid gap-x-4 gap-y-1 text-[11px] text-slate-600 sm:grid-cols-2 lg:grid-cols-3">
          {(tipo.codigo === 'S01_B_DOCUMENTO_IDENTIDAD' ? [
            ['Nombre', v.titular_nombre || 'No registrado'], ['DNI', v.titular_documento || 'No registrado'],
            ['Incorporado', fecha(v.fecha_incorporacion)], ['Emitido', fecha(v.fecha_emision)], ['Caducidad', fecha(v.fecha_caducidad)],
          ] : [['Emisor', v.emisor || 'No registrado'], ['Emitido', fecha(v.fecha_emision)], ['Incorporado', fecha(v.fecha_incorporacion)],
            ...(tipo.requiere_num_resolucion || v.num_resolucion ? [['N.º resolución', v.num_resolucion || 'No registrado']] : []),
            ['Titular', `${v.titular_nombre || 'No registrado'} ${v.titular_documento || ''} (${etiqueta(relacionesTitular, v.relacion_titular).toLowerCase()})`],
            ['Modalidad', v.modalidad_presentacion ? etiqueta(modalidadesPresentacion, v.modalidad_presentacion) : 'No registrada'],
            ['Subido por', `${documento.subido_por || 'No registrado'} · ${v.created_at || ''}`],
            ['Recepción', v.fecha_recepcion ? `${fecha(v.fecha_recepcion)} · ${documento.recepcionado_por || ''}` : 'Sin recepción registrada'],
            ...(subsana ? [['Subsana a', `documento de folios ${folios(subsana.values.folio_inicio, subsana.values.folio_fin)}`]] : []),
            ...(datos ? [['Acredita', datos]] : []),
          ]).map(([label, valor]) => <div key={label} className="min-w-0"><dt className="inline text-slate-400">{label}: </dt><dd className="inline break-words">{valor}</dd></div>)}
        </dl>
        <div className="mt-2 flex flex-wrap items-center gap-4">
          {puedeVer && documento.archivo && <button type="button" onClick={() => setVisor(visor === documento.id ? null : documento.id)} className="text-xs font-semibold text-teal-700">{visor === documento.id ? 'Ocultar PDF' : 'Ver PDF'}</button>}
          {onSubsanar && !subsanado && tipo.codigo !== 'S01_B_DOCUMENTO_IDENTIDAD' && <button type="button" onClick={() => onSubsanar(documento)} className="text-xs font-semibold text-amber-800">Subsanar</button>}
          {puedeRecepcionar && !v.fecha_recepcion && !subsanado && confirmando !== documento.id && <button type="button" onClick={() => { setError(''); setConfirmando(documento.id) }} className="text-xs font-semibold text-teal-700">Recepcionar</button>}
          {confirmando === documento.id && <span className="flex flex-wrap items-center gap-3 text-xs text-slate-600">Se registrará tu nombre y la fecha de hoy como recepción (art. 4.5).
            <button type="button" disabled={busy} onClick={() => recepcionar(documento)} className="rounded-lg bg-teal-700 px-3 py-1.5 font-semibold text-white disabled:opacity-50">{busy ? 'Registrando…' : 'Confirmar recepción'}</button>
            <button type="button" disabled={busy} onClick={() => setConfirmando(null)} className="font-semibold text-slate-600">Cancelar</button></span>}
        </div>
        {visor === documento.id && <VisorPdf usuario={usuario} documento={documento} titulo={titulo(documento)} onClose={() => setVisor(null)} />}
      </article>
    })}
  </div>
}
