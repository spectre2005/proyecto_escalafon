import { useEffect, useRef, useState } from 'react'
import api, { apiErrorMessage } from '../../axios'
import Icon from '../ui/Icon'
import { camposEscaner, datosAplicables } from './escanerCampos'

export default function EscanerPdf({ file, config }) {
  const [busy, setBusy] = useState(false)
  const [aviso, setAviso] = useState(null)
  const solicitud = useRef(null)
  const actual = useRef(config)
  useEffect(() => { actual.current = config })
  const identidad = `${config.usuario}:${config.tipoId}`
  useEffect(() => () => { solicitud.current?.abort(); solicitud.current = null }, [file, identidad])

  async function escanear() {
    if (!file || solicitud.current) return
    const controller = new AbortController()
    solicitud.current = controller
    const inicial = { ...config.valores }
    const permitidos = config.campos.filter(key => camposEscaner.includes(key))
    setBusy(true)
    setAviso(null)
    const data = new FormData()
    data.append('pdf', file)
    data.append('usuario_id', config.usuario)
    data.append('tipo_documento_id', config.tipoId)
    permitidos.forEach(key => data.append('campos[]', key))
    try {
      const respuesta = await api.post('/documentos/escanear', data, { signal: controller.signal, timeout: 280000, sinAvisoCambios: true })
      if (controller.signal.aborted) return
      const campos = respuesta.data.data.campos
      const aplicar = datosAplicables(campos, inicial, actual.current.valores, permitidos.filter(key => actual.current.campos.includes(key)))
      Object.entries(aplicar).forEach(([key, value]) => actual.current.poner(key)(value))
      setAviso({ texto: Object.keys(aplicar).length ? `${Object.keys(aplicar).length} campos completados. Revisa los datos antes de guardar.` : 'No se detectaron datos seguros para completar. Puedes rellenarlos manualmente.', campos: Object.fromEntries(Object.entries(campos).filter(([key]) => key in aplicar)) })
    } catch (error) {
      if (!controller.signal.aborted) setAviso({ texto: error.response?.data?.message || apiErrorMessage(error) })
    } finally {
      if (solicitud.current === controller) { solicitud.current = null; setBusy(false) }
    }
  }

  return <div className="relative shrink-0">
    <button type="button" onClick={escanear} disabled={!file || busy} title="Leer PDF y completar campos" aria-label={busy ? 'Leyendo PDF' : 'Leer PDF y completar campos'} aria-busy={busy}
      className="relative flex h-8 w-8 items-center justify-center rounded-full border border-teal-300 bg-white text-teal-700 disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-teal-700">
      <Icon name="robot" size={18} />
      {busy && <span className="absolute -inset-0.5 animate-spin rounded-full border-2 border-transparent border-t-teal-700 motion-reduce:animate-none" />}
    </button>
    {aviso && <div role="status" className="absolute right-0 top-10 z-30 max-h-72 w-72 overflow-y-auto rounded-xl border border-teal-200 bg-white p-3 text-xs font-normal text-slate-700 shadow-lg">
      <button type="button" onClick={() => setAviso(null)} className="float-right ml-2 text-teal-800" aria-label="Cerrar resultado"><Icon name="close" size={16} /></button>
      <p>{aviso.texto}</p>
      {aviso.campos && Object.entries(aviso.campos).map(([key, dato]) => <details key={key} className="mt-2"><summary className="cursor-pointer text-teal-800">{key.replaceAll('_', ' ')}: {dato.valor}</summary><p className="mt-1 break-words">Fuente: «{dato.evidencia}»</p></details>)}
      <p className="mt-2 text-slate-500">Hasta 3 páginas. Lectura local; no guarda ni verifica autenticidad.</p>
    </div>}
  </div>
}
