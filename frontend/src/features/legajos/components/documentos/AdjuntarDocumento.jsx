import { useState } from 'react'
import Icon from '../../../../components/ui/Icon'
import { usePuede } from '../../../auth/session'
import SubirDocumentoForm from './SubirDocumentoForm'

/**
 * «Adjuntar documento» desde la tarjeta de un registro: abre el formulario común con ese registro fijo (enlace_id) y sus
 * datos propuestos (precarga), sin volver a pedirlos. tipos: los tipos que se ofrecen y pueden acreditar este registro
 * (los decide el panel con sus reglas de compatibilidad); sin tipos no se muestra. El backend valida el enlace igual.
 */
export default function AdjuntarDocumento({ usuario, seccion, record, etiqueta, tipos, titular, relacion, precarga, bloqueo, onSaved, textoBoton = 'Adjuntar documento' }) {
  const puedeSubir = usePuede('documento.subir')
  const [tipoId, setTipoId] = useState(null)
  const [eligiendo, setEligiendo] = useState(false)
  if (!puedeSubir || !tipos?.length) return null
  const tipo = tipos.find(item => item.id === tipoId)
  const abrir = () => tipos.length === 1 ? setTipoId(tipos[0].id) : setEligiendo(true)
  return <div className="mt-4">
    {!tipo && !eligiendo && <button type="button" disabled={!!bloqueo} title={bloqueo || undefined} onClick={abrir} className="inline-flex items-center gap-2 text-sm font-semibold text-teal-700 disabled:opacity-50"><Icon name="paperclip" size={17} />{textoBoton}</button>}
    {eligiendo && !tipo && <div className="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm">
      <label className="flex min-w-0 flex-1 flex-col gap-1 text-xs font-medium text-slate-600">Tipo de documento que lo acredita
        <select className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" defaultValue="" onChange={event => { if (event.target.value) { setTipoId(event.target.value); setEligiendo(false) } }}>
          <option value="" disabled>Seleccionar tipo de documento</option>
          {tipos.map(item => <option key={item.id} value={item.id}>{item.literal ? `${item.literal}) ` : ''}{item.nombre}</option>)}
        </select>
      </label>
      <button type="button" onClick={() => setEligiendo(false)} className="text-sm text-slate-500">Cancelar</button>
    </div>}
    {tipo && <SubirDocumentoForm usuario={usuario} seccion={seccion} tipo={tipo} titular={titular} relacion={relacion?.(tipo)}
      registroFijo={{ id: record.id, etiqueta }} enlaces={[]} precarga={precarga ? enlaceId => precarga(tipo, enlaceId) : null}
      onSaved={documento => { setTipoId(null); onSaved?.(documento, tipo) }} onCancel={() => setTipoId(null)} />}
  </div>
}
