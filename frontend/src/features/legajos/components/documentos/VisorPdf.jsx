import { useEffect, useRef, useState } from 'react'
import { archivoDocumento } from '../../api/documentosApi'

/**
 * Visor del PDF dentro de la ficha. El archivo llega por el endpoint autenticado (con la sesión) y se muestra
 * desde un blob local: no hay URL pública. Cada vista y cada descarga quedan en la bitácora de accesos.
 */
export default function VisorPdf({ usuario, documento, titulo, onClose }) {
  const [url, setUrl] = useState(null)
  const [error, setError] = useState('')
  const [descargando, setDescargando] = useState(false)
  // Una sola petición por documento: cada "ver" queda en la bitácora, y StrictMode repite los efectos en desarrollo.
  const pedido = useRef(null)

  useEffect(() => {
    let creado = null
    let vigente = true
    if (pedido.current?.id !== documento.id) pedido.current = { id: documento.id, promesa: archivoDocumento(usuario, documento.id, 'ver') }
    pedido.current.promesa
      .then(response => { if (vigente) { creado = URL.createObjectURL(response.data); setUrl(creado) } })
      .catch(failure => { if (vigente) setError(failure.response?.status === 403 ? 'No tienes permiso para ver este documento.' : 'No se pudo abrir el PDF.') })
    return () => { vigente = false; if (creado) URL.revokeObjectURL(creado) }
  }, [usuario, documento.id])

  async function descargar() {
    if (descargando) return
    setDescargando(true)
    try {
      const response = await archivoDocumento(usuario, documento.id, 'descargar')
      const enlace = URL.createObjectURL(response.data)
      const link = document.createElement('a')
      link.href = enlace
      link.download = documento.archivo?.nombre_original || 'documento.pdf'
      document.body.appendChild(link)
      link.click()
      link.remove()
      setTimeout(() => URL.revokeObjectURL(enlace), 1000)
    } catch {
      setError('No se pudo descargar el PDF. Inténtalo nuevamente.')
    } finally {
      setDescargando(false)
    }
  }

  return <section aria-label="Visor de PDF" className="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
    <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
      <h4 className="min-w-0 break-words text-sm font-semibold text-slate-800">{titulo}</h4>
      <div className="flex flex-wrap gap-4">
        <button type="button" disabled={descargando} onClick={descargar} className="text-sm font-semibold text-teal-700 disabled:opacity-50">{descargando ? 'Descargando…' : 'Descargar'}</button>
        <button type="button" onClick={onClose} className="text-sm font-semibold text-slate-600">Cerrar visor</button>
      </div>
    </div>
    {error ? <p role="alert" className="rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{error}</p>
      : url ? <iframe title={titulo} src={url} className="h-[70vh] w-full rounded-xl border border-slate-100" />
        : <p role="status" className="rounded-xl bg-slate-100 p-6 text-sm text-slate-500">Cargando PDF…</p>}
  </section>
}
