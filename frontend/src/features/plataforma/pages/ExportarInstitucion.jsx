import { useState } from 'react'
import { exportarInstitucion, mensajeError } from '../api'

export default function ExportarInstitucion({ institucion }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [done, setDone] = useState(false)
  if (!institucion.aprovisionada || !['suspendida', 'archivada'].includes(institucion.estado)) return null
  async function descargar() {
    if (busy) return
    setBusy(true)
    setError('')
    setDone(false)
    try {
      const response = await exportarInstitucion(institucion.id)
      const url = URL.createObjectURL(response.data)
      const enlace = document.createElement('a')
      enlace.href = url
      enlace.download = `${institucion.slug}-exportacion.zip`
      document.body.append(enlace)
      enlace.click()
      enlace.remove()
      setTimeout(() => URL.revokeObjectURL(url), 1000)
      setDone(true)
    } catch (failure) {
      if (failure.response?.data instanceof Blob) {
        try { failure.response.data = JSON.parse(await failure.response.data.text()) } catch { /* Mantener el mensaje general si la respuesta no es JSON. */ }
      }
      setError(mensajeError(failure))
    } finally { setBusy(false) }
  }
  return <section className="space-y-3 rounded-2xl border border-slate-200 bg-white p-5" aria-busy={busy}><h2 className="font-semibold">Exportación de la institución</h2><p className="text-sm text-slate-600">Descarga los datos JSON y los archivos en un ZIP privado. Los archivos conservan su cifrado: custodia por separado la clave documental necesaria para recuperarlos. Esta operación queda auditada y conserva la información original.</p><button type="button" disabled={busy} onClick={descargar} className="rounded-xl border border-teal-300 px-4 py-2 text-sm font-semibold text-teal-800 disabled:opacity-50">{busy ? 'Preparando exportación…' : 'Descargar JSON y archivos ZIP'}</button>{error && <p role="alert" className="text-sm text-rose-800">{error}</p>}{done && <p role="status" className="text-sm text-teal-800">Se inició la descarga. Conserva el ZIP en un lugar privado.</p>}</section>
}
