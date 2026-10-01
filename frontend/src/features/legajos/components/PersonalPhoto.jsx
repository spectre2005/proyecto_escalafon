import { useEffect, useRef, useState } from 'react'
import api, { apiErrorMessage } from '../../../axios'

export default function PersonalPhoto({ usuario, nombre }) {
  const [photo, setPhoto] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const input = useRef(null)
  useEffect(() => {
    let active = true
    let objectUrl = ''
    let sequence = 0
    async function load() {
      const current = ++sequence
      try {
        const response = await api.get(`/expedientes/${usuario}/foto`, { responseType: 'blob' })
        if (!active || current !== sequence) return
        const next = response.status === 204 ? '' : URL.createObjectURL(response.data)
        setPhoto(next)
        if (objectUrl) URL.revokeObjectURL(objectUrl)
        objectUrl = next
      } catch {
        if (active) setError('No se pudo cargar la foto. Vuelve a abrir la ficha para reintentar.')
      }
    }
    load()
    window.addEventListener('records-updated', load)
    return () => {
      active = false
      window.removeEventListener('records-updated', load)
      if (objectUrl) URL.revokeObjectURL(objectUrl)
    }
  }, [usuario])

  async function upload(event) {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!file || busy) return
    setError('')
    setNotice('')
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
      setError('Selecciona una imagen JPG, PNG o WebP de hasta 2 MB.')
      return
    }
    setBusy(true)
    try {
      const form = new FormData()
      form.append('foto', file)
      await api.post(`/expedientes/${usuario}/foto`, form)
      setNotice('Foto guardada.')
    } catch (failure) {
      setError(apiErrorMessage(failure))
    } finally {
      setBusy(false)
    }
  }

  return <aside className="w-40 shrink-0 self-start md:ml-auto" aria-label="Foto del personal">
    <p className="mb-2 text-xs font-medium text-slate-500">Fotografía del personal</p>
    <div className={`flex aspect-[3/4] items-center justify-center overflow-hidden rounded-xl border bg-slate-50 text-center text-sm text-slate-400 ${photo ? 'border-slate-200' : 'border-dashed border-teal-200'}`}>
      {photo ? <img src={photo} alt={`Foto de ${nombre}`} className="h-full w-full object-cover" /> : <span className="p-4">Sin fotografía registrada</span>}
    </div>
    <input ref={input} type="file" accept="image/jpeg,image/png,image/webp" aria-label="Seleccionar foto del personal" className="sr-only" disabled={busy} onChange={upload} />
    <button type="button" disabled={busy} onClick={() => input.current?.click()} className="mt-3 w-full rounded-xl border border-teal-200 bg-white px-3 py-2 text-sm font-semibold text-teal-800 disabled:opacity-50">{busy ? 'Subiendo…' : photo ? 'Cambiar foto' : 'Subir foto'}</button>
    <p className="mt-2 text-xs text-slate-500">JPG, PNG o WebP · Máx. 2 MB</p>
    {error && <p role="alert" className="mt-2 text-xs text-rose-700">{error}</p>}
    {notice && <p role="status" className="mt-2 text-xs text-teal-700">{notice}</p>}
  </aside>
}
