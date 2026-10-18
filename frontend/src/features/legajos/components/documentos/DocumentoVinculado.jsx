import { useRef, useState } from 'react'
import { useParams } from 'react-router-dom'
import { usePuede } from '../../../auth/session'
import DocumentoModal from './DocumentoModal'
import ListaDocumentos from './ListaDocumentos'
import SubirDocumentoForm from './SubirDocumentoForm'

export default function DocumentoVinculado({ encontrado, enlazado, compacto = false }) {
  const { usuario } = useParams()
  const puedeSubir = usePuede('documento.subir')
  const [abierto, setAbierto] = useState(false)
  const [subsanando, setSubsanando] = useState(null)
  const contenido = useRef(null)
  if (!encontrado) return compacto ? null : <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">{enlazado ? 'Documento enlazado (no está en la lista de esta subsección).' : 'Sin documento enlazado.'}</p>
  const { tipo, documento } = encontrado
  const valores = documento.values
  const seccion = Number(valores.seccion_legajo_id || tipo.seccion_legajo_id || tipo.codigo?.match(/^S(\d{2})/)?.[1])
  function cerrar() {
    if (contenido.current?.querySelector('form[aria-busy="true"]')) return
    setSubsanando(null)
    setAbierto(false)
  }
  return <>
    {!compacto && <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">{tipo.nombre} · folios {valores.folio_inicio}–{valores.folio_fin}</p>}
    <button type="button" onClick={() => setAbierto(true)} className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-teal-700 hover:bg-teal-50">Ver documentos</button>
    {abierto && <DocumentoModal titulo={`Documento del registro: ${tipo.nombre}`} onCancel={cerrar}>
      <div ref={contenido}>
        {subsanando ? <SubirDocumentoForm modal={false} usuario={usuario} seccion={seccion} tipo={tipo}
          titular={{ nombre: valores.titular_nombre, documento: valores.titular_documento }} enlaces={[]} subsanar={subsanando}
          onCancel={() => setSubsanando(null)} onSaved={() => { setSubsanando(null); setAbierto(false) }} />
          : <><p className="mb-4 text-sm text-slate-500">Consulta el PDF y sus datos. Para corregir el archivo, registra una nueva evidencia con «Subsanar»; el documento anterior se conserva.</p>
            <ListaDocumentos usuario={usuario} tipo={{ ...tipo, lista: [documento] }} onSubsanar={puedeSubir && seccion ? setSubsanando : undefined} />
          </>}
      </div>
    </DocumentoModal>}
  </>
}
