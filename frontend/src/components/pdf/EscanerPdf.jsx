import { useEffect, useRef, useState } from 'react'
import api, { apiErrorMessage } from '../../axios'
import Icon from '../ui/Icon'
import { camposEscaner, datosAplicables, etiquetaCampo, motivosDescarte, sugerenciasAplicables, valorLegible } from './escanerCampos'

export default function EscanerPdf({ file, config }) {
  const [busy, setBusy] = useState(false)
  const [aviso, setAviso] = useState(null)
  const [abierto, setAbierto] = useState(false)
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
    setAbierto(false)
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
      const permitidosAhora = permitidos.filter(key => actual.current.campos.includes(key))
      const sugerencias = sugerenciasAplicables(respuesta.data.data.sugerencias, { ...actual.current.valores, ...aplicar }, permitidosAhora)
      setAbierto(true)
      setAviso({ sugerencias, texto: Object.keys(aplicar).length ? `${Object.keys(aplicar).length} campos completados. Revisa los datos antes de guardar.` : Object.keys(sugerencias).length ? 'No se pudo comprobar ningún dato. Revisa los posibles datos de abajo.' : 'No se detectaron datos seguros para completar. Puedes rellenarlos manualmente.', campos: Object.fromEntries(Object.entries(campos).filter(([key]) => key in aplicar)), descartados: motivosDescarte(respuesta.data.data.descartados, permitidos, sugerencias), dudosa: respuesta.data.data.lectura_dudosa })
    } catch (error) {
      if (!controller.signal.aborted) {
        setAbierto(true)
        setAviso({ texto: error.response?.data?.message || apiErrorMessage(error) })
      }
    } finally {
      if (solicitud.current === controller) { solicitud.current = null; setBusy(false) }
    }
  }

  function usarSugerencia(campo, dato) {
    actual.current.poner(campo)(dato.valor)
    setAviso(previo => {
      const sugerencias = { ...previo.sugerencias }
      delete sugerencias[campo]
      return { ...previo, sugerencias, campos: { ...previo.campos, [campo]: dato } }
    })
  }

  return <div className="relative shrink-0">
    <button type="button" onClick={escanear} disabled={!file || busy} title="Leer PDF y completar campos" aria-label={busy ? 'Leyendo PDF' : 'Leer PDF y completar campos'} aria-busy={busy}
      className="relative flex h-8 w-8 items-center justify-center rounded-full border border-teal-300 bg-white text-teal-700 disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-teal-700">
      <Icon name="robot" size={18} />
      {busy && <span className="absolute -inset-0.5 animate-spin rounded-full border-2 border-transparent border-t-teal-700 motion-reduce:animate-none" />}
    </button>
    {aviso && !abierto && <button type="button" onClick={() => setAbierto(true)} className="absolute right-0 top-9 whitespace-nowrap text-[11px] font-semibold text-teal-800 underline">Ver lectura</button>}
    {aviso && abierto && <div role="status" className="absolute right-0 top-10 z-30 max-h-80 w-80 overflow-y-auto rounded-xl border border-teal-200 bg-white p-3 text-xs font-normal text-slate-700 shadow-lg">
      <button type="button" onClick={() => setAbierto(false)} className="float-right ml-2 text-teal-800" aria-label="Cerrar resultado"><Icon name="close" size={16} /></button>
      <p>{aviso.texto}</p>
      {Object.keys(aviso.campos || {}).length > 0 && <div className="mt-2"><p className="font-semibold text-teal-800">Propuesto por IA</p>{Object.entries(aviso.campos).map(([key, dato]) => <details key={key} className="mt-1"><summary className="cursor-pointer text-teal-800">{etiquetaCampo(key)}: {valorLegible(dato.valor)}</summary><p className="mt-1 break-words">Fuente: «{dato.evidencia}»</p></details>)}</div>}
      {Object.keys(aviso.sugerencias || {}).length > 0 && <div className="mt-3 rounded-lg bg-amber-50 p-2"><p className="font-semibold text-amber-800">Posibles datos sin comprobar</p>{Object.entries(aviso.sugerencias).map(([key, dato]) => <div key={key} className="mt-2"><p>{etiquetaCampo(key)}: <strong>{valorLegible(dato.valor)}</strong></p><p className="mt-0.5 break-words text-slate-500">Fuente: «{dato.evidencia}»</p><button type="button" onClick={() => usarSugerencia(key, dato)} className="mt-1 rounded-lg border border-amber-300 bg-white px-2 py-1 font-semibold text-amber-800">Usar este dato</button></div>)}</div>}
      {aviso.dudosa && <p className="mt-2 text-amber-700">El escaneo se leyó con dificultad: revisa los datos con más cuidado o prueba con una copia más nítida.</p>}
      {aviso.descartados?.length > 0 && <div className="mt-2"><p className="text-slate-500">Sin completar:</p><ul className="mt-1 list-disc pl-4">{aviso.descartados.map(({ campo, motivo }) => <li key={campo}>{etiquetaCampo(campo)}: {motivo}</li>)}</ul></div>}
      <p className="mt-2 text-slate-500">Hasta 3 páginas. Lectura local; no guarda ni verifica autenticidad.</p>
    </div>}
  </div>
}
