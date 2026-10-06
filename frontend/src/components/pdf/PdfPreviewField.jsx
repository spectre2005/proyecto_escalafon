import { useEffect, useId, useRef, useState } from 'react'
import workerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url'
import api from '../../axios'
import { validatePdf } from './pdfValidation'

const button = 'rounded-lg border border-slate-200 px-3 py-2 text-xs text-teal-800 focus-visible:outline-2 focus-visible:outline-teal-600 disabled:opacity-40'

export default function PdfPreviewField({ name, id, label, help, maxMB = 2, currentUrl, currentName, required, error, onChange }) {
  const generatedId = useId()
  const helpId = `${generatedId}-help`
  const input = useRef(null)
  const panel = useRef(null)
  const canvas = useRef(null)
  const [file, setFile] = useState(null)
  const [validation, setValidation] = useState('')
  const [previewError, setPreviewError] = useState('')
  const [pdf, setPdf] = useState(null)
  const [page, setPage] = useState(1)
  const [width, setWidth] = useState(0)
  const [loading, setLoading] = useState(false)
  const [hidden, setHidden] = useState(false)
  const [currentSize, setCurrentSize] = useState(0)
  const active = Boolean(file || (currentUrl && !hidden))

  useEffect(() => {
    let timer
    const observer = new ResizeObserver(entries => {
      clearTimeout(timer)
      timer = setTimeout(() => setWidth(Math.floor(entries[0].contentRect.width)), 120)
    })
    observer.observe(panel.current)
    return () => { clearTimeout(timer); observer.disconnect() }
  }, [])

  useEffect(() => {
    let disposed = false
    let task
    const controller = new AbortController()
    async function load() {
      setPdf(null)
      setPage(1)
      setPreviewError('')
      setLoading(active)
      if (!active) return
      try {
        const blob = file || (await api.get(currentUrl, { responseType: 'blob', signal: controller.signal })).data
        if (!file && !disposed) setCurrentSize(blob.size)
        const data = await blob.arrayBuffer()
        if (disposed) return
        const pdfjs = await import('pdfjs-dist')
        if (disposed) return
        pdfjs.GlobalWorkerOptions.workerSrc = workerUrl
        task = pdfjs.getDocument({ data, isEvalSupported: false })
        const document = await task.promise
        if (!disposed) setPdf(document)
      } catch (failure) {
        if (!disposed) setPreviewError(failure.name === 'PasswordException' ? 'Este PDF tiene contraseña. No se puede mostrar la vista previa.' : 'No se pudo mostrar el PDF: puede estar dañado o no estar disponible.')
      } finally {
        if (!disposed) setLoading(false)
      }
    }
    load()
    return () => { disposed = true; controller.abort(); task?.destroy() }
  }, [file, currentUrl, active])

  useEffect(() => {
    if (!pdf || !width) return
    let disposed = false
    let render
    async function draw() {
      try {
        const sheet = await pdf.getPage(page)
        if (disposed) return
        const viewport = sheet.getViewport({ scale: width / sheet.getViewport({ scale: 1 }).width })
        const ratio = window.devicePixelRatio || 1
        const buffer = document.createElement('canvas')
        buffer.width = Math.ceil(viewport.width * ratio)
        buffer.height = Math.ceil(viewport.height * ratio)
        render = sheet.render({ canvas: buffer, viewport, transform: [ratio, 0, 0, ratio, 0, 0] })
        await render.promise
        if (disposed || !canvas.current) return
        canvas.current.width = buffer.width
        canvas.current.height = buffer.height
        canvas.current.style.width = '100%'
        canvas.current.getContext('2d').drawImage(buffer, 0, 0)
      } catch (failure) {
        if (!disposed && failure.name !== 'RenderingCancelledException') setPreviewError('No se pudo dibujar esta página.')
      }
    }
    draw()
    return () => { disposed = true; render?.cancel() }
  }, [pdf, page, width])

  function select(event) {
    const selected = event.target.files?.[0] || null
    const message = validatePdf(selected, maxMB)
    setValidation(message)
    setHidden(false)
    setFile(message ? null : selected)
    onChange(message ? null : selected, message)
    if (message) event.target.value = ''
  }
  function remove() {
    input.current.value = ''
    setFile(null)
    setHidden(true)
    setValidation('')
    onChange(null, '')
  }
  const message = validation || error
  return <section className="flex h-full min-w-0 flex-col gap-3">
    <label className="grid min-w-0 gap-2 text-xs font-medium text-slate-600" htmlFor={id}>
      {label}{required ? ' *' : ''}
      <input ref={input} name={name} id={id} type="file" accept="application/pdf,.pdf" required={required && !currentUrl} onChange={select} aria-describedby={helpId} aria-invalid={Boolean(message)} className="min-w-0 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm focus-visible:outline-2 focus-visible:outline-teal-600" />
    </label>
    <p id={helpId} className="text-xs text-slate-500">{help}</p>
    {message && <p role="alert" className="text-xs text-rose-700">{[].concat(message).join(' ')}</p>}
    <div className="flex min-w-0 flex-1 flex-col overflow-hidden rounded-xl border border-slate-200 bg-slate-50">
      <div className="flex min-w-0 flex-wrap items-center gap-2 border-b border-slate-200 bg-white p-3">
        <div className="min-w-0 basis-full break-words text-xs text-slate-600">{active ? <><p>{file ? (currentUrl ? 'Archivo nuevo, reemplazará al actual' : 'Archivo nuevo') : 'Archivo actual'}</p><p>{file?.name || currentName || 'Documento PDF'} · {((file?.size || currentSize) / 1024 / 1024).toFixed(2)} MB</p></> : 'Vista previa del PDF'}</div>
        <button type="button" className={button} disabled={!pdf || page <= 1} onClick={() => setPage(value => value - 1)} aria-label="Página anterior">Anterior</button>
        <span aria-live="polite" className="text-xs text-slate-600">{pdf ? `${page} de ${pdf.numPages}` : '0 de 0'}</span>
        <button type="button" className={button} disabled={!pdf || page >= pdf.numPages} onClick={() => setPage(value => value + 1)} aria-label="Página siguiente">Siguiente</button>
        <button type="button" className={button} disabled={!active && !validation} onClick={remove}>Quitar</button>
      </div>
      <div ref={panel} className="min-h-[420px] min-w-0 flex-1" aria-busy={loading}>
        {loading && <p className="p-6 text-sm text-slate-500">Cargando vista previa…</p>}
        {!active && <p className="p-6 text-sm text-slate-500">Cuando elijas un PDF, aquí verás cómo queda el documento antes de guardarlo.</p>}
        {previewError && <p role="alert" className="p-6 text-sm text-rose-700">{previewError} Puedes guardar el archivo igualmente.</p>}
        {pdf && <canvas ref={canvas} className="block max-w-full" role="img" aria-label={`Vista previa de la página ${page}`} />}
      </div>
    </div>
  </section>
}
