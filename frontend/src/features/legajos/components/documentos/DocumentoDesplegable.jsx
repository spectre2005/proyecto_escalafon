import { useState } from 'react'
import Modal from '../../../../components/ui/Modal'
import Icon from '../../../../components/ui/Icon'

/** Mantiene el resumen visible y abre el contenido documental con el patrón de SST. */
export default function DocumentoDesplegable({ titulo, resumen, id, children, compacto = false, onAdjuntar, formulario, textoAccion = 'Ver documentos', tituloVentana }) {
  const [abierto, setAbierto] = useState(false)
  return <><div className="border-b border-slate-100 last:border-b-0">
    <div className="flex flex-wrap items-center justify-between gap-3 p-4">
      <div className={compacto ? "flex min-w-0 flex-1 flex-wrap items-center justify-between gap-3" : "grid min-w-0 flex-1 grid-cols-[28px_minmax(0,1fr)] items-start gap-3 sm:grid-cols-[28px_minmax(0,1fr)_auto]"}>{resumen}</div>
      <div className="flex flex-wrap gap-2">{onAdjuntar && <button id={`adjuntar-${id}`} type="button" onClick={onAdjuntar} aria-label={`Adjuntar documento: ${titulo}`} className="inline-flex items-center gap-2 rounded-xl border border-teal-200 bg-teal-50 px-3 py-2 text-xs font-semibold text-teal-800 hover:bg-teal-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700"><Icon name="paperclip" size={18} />Adjuntar documento</button>}
      <button id={id} type="button" aria-haspopup="dialog" onClick={() => setAbierto(true)} className="inline-flex items-center gap-2 rounded-xl border border-teal-200 bg-teal-50 px-3 py-2 text-xs font-semibold text-teal-800 hover:bg-teal-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700"><Icon name="eye" size={18} />{textoAccion}</button></div>
    </div>
    {abierto && <Modal titulo={tituloVentana || `Documentos de ${titulo}`} onClose={() => setAbierto(false)}>{children}</Modal>}
  </div>{formulario}</>
}
