import { useEffect, useRef, useState } from 'react'
import { useLiveQuery } from '../../../shared/queries/useLiveQuery'
import ListaDocumentos from './documentos/ListaDocumentos'
import SubirDocumentoForm from './documentos/SubirDocumentoForm'

const documents = [
  ['S01_A_FICHA_DATOS', 'Ficha personal'],
  ['S01_B_DOCUMENTO_IDENTIDAD', 'Copia de DNI / identidad'],
]

export default function PersonalDocuments({ usuario, uploadCode, onClose }) {
  const request = useLiveQuery(`/expedientes/${usuario}/seccion-01`)
  const data = request.data?.data
  const dialog = useRef(null)
  const [message, setMessage] = useState('')
  const type = data?.tipos.find(item => item.codigo === uploadCode)
  const title = uploadCode === 'S01_A_FICHA_DATOS' ? 'Subir ficha personal' : 'Subir copia de DNI / identidad'

  useEffect(() => {
    if (!uploadCode) return
    const modal = dialog.current
    modal.showModal()
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      modal.close()
      document.body.style.overflow = previousOverflow
    }
  }, [uploadCode])

  return <>
    {message && <p role="status" className="mt-5 rounded-xl bg-teal-50 p-3 text-sm text-teal-800">{message}</p>}
    {request.error && <p role="alert" className="mt-5 text-xs text-rose-700">No se pudieron actualizar los documentos. <button type="button" onClick={request.retry} className="font-semibold underline">Reintentar</button></p>}
    <div className="mt-6 grid gap-3 border-t border-slate-100 pt-5 sm:grid-cols-2">
      {documents.map(([code, label]) => {
        const item = data?.tipos.find(document => document.codigo === code)
        return <details key={code} className="min-w-0 rounded-xl border border-slate-200 p-3 sm:open:col-span-2">
          <summary className="flex cursor-pointer list-none flex-wrap items-center justify-between gap-2 text-xs"><span className="font-semibold text-slate-700">{label}</span><span className="text-teal-700">{item?.lista?.length ? `Ver archivos (${item.lista.length})` : request.loading ? 'Consultando…' : 'Sin archivo'}</span></summary>
          {item && <ListaDocumentos usuario={usuario} tipo={item} />}
        </details>
      })}
    </div>
    <dialog ref={dialog} aria-labelledby="personal-document-title" onClose={onClose}
      onCancel={event => { if (dialog.current.querySelector('form[aria-busy="true"]')) event.preventDefault() }}
      className="fixed inset-0 m-auto max-h-[90dvh] w-[calc(100%_-_2rem)] max-w-6xl overflow-y-auto rounded-2xl border-0 bg-slate-50 p-5 text-slate-800 shadow-2xl backdrop:bg-slate-900/50">
      <h2 id="personal-document-title" className="mb-4 text-lg font-semibold">{title}</h2>
      {request.error && <p role="alert" className="mb-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">No se pudieron consultar los documentos. <button type="button" onClick={request.retry} className="font-semibold underline">Reintentar</button></p>}
      {uploadCode && (!data ? <p role="status" className="mb-4 text-sm text-slate-500">Consultando documentos…</p> : !data.legajo ? <p role="alert" className="mb-4 text-sm text-amber-800">Abre un legajo para esta persona antes de subir el archivo.</p> : !type ? <p role="alert" className="mb-4 text-sm text-amber-800">El tipo de documento no está disponible en el catálogo.</p> :
        <SubirDocumentoForm modal={false} key={`${uploadCode}:${type.id}`} usuario={usuario} seccion={1} tipo={type} titular={data.titular}
          onCancel={onClose} onSaved={() => { setMessage(uploadCode === 'S01_A_FICHA_DATOS' ? 'Ficha personal subida correctamente.' : 'Copia de identidad subida correctamente.'); request.retry(); onClose() }} />)}
      {(!data || !data.legajo || !type) && <button type="button" onClick={onClose} className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm">Cerrar</button>}
    </dialog>
  </>
}
