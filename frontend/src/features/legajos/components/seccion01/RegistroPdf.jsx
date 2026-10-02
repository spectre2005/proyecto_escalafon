import { useState } from 'react'
import { descargarRegistroPdf } from '../../api/seccion01Api'

export default function RegistroPdf({ usuario, record, tipo }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function descargar() {
    if (busy) return
    setBusy(true)
    setError('')
    try {
      const response = await descargarRegistroPdf(usuario, tipo, record.id)
      const url = URL.createObjectURL(response.data)
      const link = document.createElement('a')
      link.href = url
      link.download = record.values.pdf_nombre
      document.body.appendChild(link)
      link.click()
      link.remove()
      setTimeout(() => URL.revokeObjectURL(url), 1000)
    } catch {
      setError('No se pudo descargar el PDF. Inténtalo nuevamente.')
    } finally {
      setBusy(false)
    }
  }
  return <div className="mt-4 text-sm">
    {record.values.pdf_nombre ? <button type="button" disabled={busy} onClick={descargar} className="break-words text-left font-semibold text-teal-700 disabled:opacity-50">{busy ? 'Descargando…' : `Descargar PDF: ${record.values.pdf_nombre}`}</button> : <p className="text-slate-500">Sin PDF adjunto</p>}
    {error && <p role="alert" className="mt-2 text-rose-700">{error}</p>}
  </div>
}

