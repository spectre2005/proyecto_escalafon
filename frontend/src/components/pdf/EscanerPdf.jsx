import { useEffect, useRef, useState } from 'react'
import api, { apiErrorMessage } from '../../axios'
import Icon from '../ui/Icon'
import { camposEscaner, datosAplicables, etiquetaCampo, LECTURA_EN_CURSO, motivosDescarte, resultadoLectura, sugerenciasAplicables, textoAvance, TONO_TITULAR, valorLegible } from './escanerCampos'

// La lectura corre en el servidor después de responder (LeerDocumentoConIa): se consulta su avance cada 2 s.
const CONSULTA_MS = 2000
const ESPERA_MAXIMA_MS = 25 * 60 * 1000
const esperar = (ms, signal) => new Promise((resolve, reject) => {
  const temporizador = setTimeout(resolve, ms)
  signal.addEventListener('abort', () => { clearTimeout(temporizador); reject(new DOMException('Cancelada', 'AbortError')) }, { once: true })
})

const estiloTitular = { error: 'border-rose-200 bg-rose-50 text-rose-800', aviso: 'border-amber-200 bg-amber-50 text-amber-800', ok: 'border-teal-100 bg-teal-50 text-teal-800' }

export default function EscanerPdf({ file, config }) {
  const [busy, setBusy] = useState(false)
  const [aviso, setAviso] = useState(null)
  const [abierto, setAbierto] = useState(false)
  const [avance, setAvance] = useState('')
  const solicitud = useRef(null)
  const actual = useRef(config)
  const boton = useRef(null)
  // Última lectura: qué se pidió, propuso y sugirió. Al enviar el formulario se informa qué pasó con cada campo (sin valores).
  const lectura = useRef(null)
  useEffect(() => { actual.current = config })
  useEffect(() => {
    const formulario = boton.current?.closest('form')
    if (!formulario) return undefined
    const alEnviar = () => {
      const datos = lectura.current
      if (!datos?.id) return
      // Se informa una sola vez por lectura: si el guardado falla y se vuelve a enviar, no se cuenta dos veces.
      lectura.current = null
      const resultado = resultadoLectura({ ...datos, finales: actual.current.valores })
      api.post(`/documentos/escaner/lecturas/${datos.id}/resultado`, { resultado }, { sinAvisoCambios: true }).catch(() => {})
    }
    formulario.addEventListener('submit', alEnviar)
    return () => formulario.removeEventListener('submit', alEnviar)
  }, [])
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
    setAvance('Enviando el PDF…')
    const data = new FormData()
    data.append('pdf', file)
    data.append('usuario_id', config.usuario)
    data.append('tipo_documento_id', config.tipoId)
    permitidos.forEach(key => data.append('campos[]', key))
    try {
      // Responde al instante con la lectura en espera; luego se consulta hasta que termine. Se puede seguir usando el formulario.
      let datos = (await api.post('/documentos/escanear', data, { signal: controller.signal, timeout: 60000, sinAvisoCambios: true })).data.data
      const limite = Date.now() + ESPERA_MAXIMA_MS
      while (LECTURA_EN_CURSO.includes(datos.estado)) {
        setAvance(textoAvance(datos))
        if (Date.now() > limite) throw new Error('La lectura tarda demasiado. Vuelve a intentarlo más tarde o completa los campos manualmente.')
        await esperar(CONSULTA_MS, controller.signal)
        datos = (await api.get(`/documentos/escaner/lecturas/${datos.lectura_id}`, { signal: controller.signal, sinAvisoCambios: true })).data.data
      }
      if (controller.signal.aborted) return
      if (datos.estado !== 'lista') {
        setAbierto(true)
        setAviso({ texto: datos.error || 'No se pudo completar la lectura. Puedes completar los campos manualmente.' })
        return
      }
      const respuesta = { data: { data: datos } }
      const campos = respuesta.data.data.campos
      const aplicar = datosAplicables(campos, inicial, actual.current.valores, permitidos.filter(key => actual.current.campos.includes(key)))
      Object.entries(aplicar).forEach(([key, value]) => actual.current.poner(key)(value))
      const permitidosAhora = permitidos.filter(key => actual.current.campos.includes(key))
      const sugerencias = sugerenciasAplicables(respuesta.data.data.sugerencias, { ...actual.current.valores, ...aplicar }, permitidosAhora)
      const sugeridos = Object.fromEntries(Object.entries(respuesta.data.data.sugerencias || {}).map(([key, dato]) => [key, dato?.valor]))
      lectura.current = { id: respuesta.data.data.lectura_id, pedidos: permitidosAhora, propuestos: aplicar, sugeridos }
      setAbierto(true)
      setAviso({ titular: respuesta.data.data.titular, sugerencias, texto: Object.keys(aplicar).length ? `${Object.keys(aplicar).length} campos completados. Revisa los datos antes de guardar.` : Object.keys(sugerencias).length ? 'No se pudo comprobar ningún dato. Revisa los posibles datos de abajo.' : 'No se detectaron datos seguros para completar. Puedes rellenarlos manualmente.', campos: Object.fromEntries(Object.entries(campos).filter(([key]) => key in aplicar)), descartados: motivosDescarte(respuesta.data.data.descartados, permitidos, sugerencias), dudosa: respuesta.data.data.lectura_dudosa, paginas: respuesta.data.data.aviso })
    } catch (error) {
      if (!controller.signal.aborted) {
        setAbierto(true)
        setAviso({ texto: error.response?.data?.message || error.message || apiErrorMessage(error) })
      }
    } finally {
      if (solicitud.current === controller) { solicitud.current = null; setBusy(false); setAvance('') }
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
    <button ref={boton} type="button" onClick={escanear} disabled={!file || busy} title="Leer PDF y completar campos" aria-label={busy ? 'Leyendo PDF' : 'Leer PDF y completar campos'} aria-busy={busy}
      className="relative flex h-8 w-8 items-center justify-center rounded-full border border-teal-300 bg-white text-teal-700 disabled:opacity-50 focus-visible:outline-2 focus-visible:outline-teal-700">
      <Icon name="robot" size={18} />
      {busy && <span className="absolute -inset-0.5 animate-spin rounded-full border-2 border-transparent border-t-teal-700 motion-reduce:animate-none" />}
    </button>
    {busy && avance && <p role="status" aria-live="polite" className="absolute right-0 top-9 z-20 whitespace-nowrap rounded-lg bg-white/95 px-1.5 text-xs font-semibold text-teal-800">{avance}</p>}
    {aviso && !abierto && <button type="button" onClick={() => setAbierto(true)} className={`absolute right-0 top-9 whitespace-nowrap text-xs font-semibold underline ${aviso.titular?.estado === 'distinto' ? 'text-rose-700' : 'text-teal-800'}`}>{aviso.titular?.estado === 'distinto' ? 'Ver aviso' : 'Ver lectura'}</button>}
    {aviso && abierto && <div role="status" className="absolute right-0 top-10 z-30 max-h-80 w-80 overflow-y-auto rounded-xl border border-teal-200 bg-white p-3 text-xs font-normal text-slate-700 shadow-lg">
      <button type="button" onClick={() => setAbierto(false)} className="float-right ml-2 text-teal-800" aria-label="Cerrar resultado"><Icon name="close" size={16} /></button>
      {aviso.titular && <p role={TONO_TITULAR[aviso.titular.estado] === 'error' ? 'alert' : undefined} className={`mb-2 rounded-lg border p-2 ${estiloTitular[TONO_TITULAR[aviso.titular.estado]] || estiloTitular.aviso}`}>
        {TONO_TITULAR[aviso.titular.estado] === 'error' && <strong className="block">¿Documento de otra persona?</strong>}{aviso.titular.mensaje}
      </p>}
      <p>{aviso.texto}</p>
      {Object.keys(aviso.campos || {}).length > 0 && <div className="mt-2"><p className="font-semibold text-teal-800">Propuesto por IA</p>{Object.entries(aviso.campos).map(([key, dato]) => <details key={key} className="mt-1"><summary className="cursor-pointer text-teal-800">{etiquetaCampo(key)}: {valorLegible(dato.valor)}</summary><p className="mt-1 break-words">Fuente: «{dato.evidencia}»</p></details>)}</div>}
      {Object.keys(aviso.sugerencias || {}).length > 0 && <div className="mt-3 rounded-lg bg-amber-50 p-2"><p className="font-semibold text-amber-800">Posibles datos sin comprobar</p>{Object.entries(aviso.sugerencias).map(([key, dato]) => <div key={key} className="mt-2"><p>{etiquetaCampo(key)}: <strong>{valorLegible(dato.valor)}</strong></p><p className="mt-0.5 break-words text-slate-500">Fuente: «{dato.evidencia}»</p><button type="button" onClick={() => usarSugerencia(key, dato)} className="mt-1 rounded-lg border border-amber-300 bg-white px-2 py-1 font-semibold text-amber-800">Usar este dato</button></div>)}</div>}
      {aviso.dudosa && <p className="mt-2 text-amber-700">El escaneo se leyó con dificultad: revisa los datos con más cuidado o prueba con una copia más nítida.</p>}
      {aviso.descartados?.length > 0 && <div className="mt-2"><p className="text-slate-500">Sin completar:</p><ul className="mt-1 list-disc pl-4">{aviso.descartados.map(({ campo, motivo }) => <li key={campo}>{etiquetaCampo(campo)}: {motivo}</li>)}</ul></div>}
      <p className="mt-2 text-slate-500">{aviso.paginas || 'Lectura local; no guarda ni verifica autenticidad.'}</p>
    </div>}
  </div>
}
