import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import api, { apiErrorMessage } from '../../axios'
import Icon from '../../components/ui/Icon'
import { usePuede } from '../auth/session'
import { buscarTemas, etiquetaOrigen, temasDePantalla, ultimoTema } from './guiaBusqueda'

function Tema({ tema, onCerrar }) {
  return <details className="rounded-xl border border-slate-200 bg-white p-3">
    <summary className="cursor-pointer text-xs font-semibold text-slate-700">{tema.titulo}</summary>
    <ol className="mt-2 list-decimal space-y-1 pl-4 text-xs text-slate-600">{tema.pasos.map(paso => <li key={paso}>{paso}</li>)}</ol>
    <Link to={tema.ruta} onClick={onCerrar} className="mt-2 inline-block text-xs font-semibold text-teal-800 underline">Ir a esta pantalla</Link>
  </details>
}

function ListaTemas({ titulo, temas, onCerrar }) {
  if (!temas.length) return null
  return <section className="space-y-2"><h3 className="text-[11px] font-semibold text-slate-500">{titulo}</h3>{temas.map(tema => <Tema key={tema.id} tema={tema} onCerrar={onCerrar} />)}</section>
}

function Mensaje({ mensaje, onCerrar }) {
  if (mensaje.rol === 'usuario') return <p className="ml-8 self-end rounded-2xl rounded-br-sm bg-teal-700 px-3 py-2 text-xs whitespace-pre-line text-white">{mensaje.texto}</p>
  const etiqueta = etiquetaOrigen(mensaje.origen)
  return <div className="mr-4 self-start rounded-2xl rounded-bl-sm border border-slate-200 bg-white px-3 py-2 text-xs text-slate-700">
    {etiqueta && <p className="mb-1 text-[10px] font-semibold text-teal-800">{etiqueta}</p>}
    <p className="whitespace-pre-line">{mensaje.texto}</p>
    {mensaje.origen === 'ia' && <p className="mt-1 text-[10px] text-slate-500">Compruébala con los pasos de la guía.</p>}
    {mensaje.temas?.length > 0 && <details className="mt-2 border-t border-slate-100 pt-2">
      <summary className="cursor-pointer text-[11px] font-semibold text-teal-800">Ver pasos de la guía</summary>
      <div className="mt-2 space-y-2">{mensaje.temas.map(tema => <Tema key={tema.id} tema={tema} onCerrar={onCerrar} />)}</div>
    </details>}
  </div>
}

export default function GuiaFlotante() {
  const puedeVer = usePuede('guia.ver')
  const { pathname } = useLocation()
  const [abierta, setAbierta] = useState(false)
  const [pestana, setPestana] = useState('chat')
  const [guia, setGuia] = useState(null)
  const [error, setError] = useState('')
  const [texto, setTexto] = useState('')
  const [filtro, setFiltro] = useState('')
  const [pensando, setPensando] = useState(false)
  // Solo en memoria: alguien podría escribir datos de una persona, y no deben quedar guardados en el navegador.
  const [mensajes, setMensajes] = useState([])
  const boton = useRef(null)
  const fin = useRef(null)
  const solicitud = useRef(null)

  useEffect(() => {
    if (!abierta || guia) return undefined
    const controller = new AbortController()
    api.get('/guia', { signal: controller.signal })
      .then(({ data }) => { setGuia(data.data); setError('') })
      .catch(fallo => { if (!controller.signal.aborted) setError(apiErrorMessage(fallo)) })
    return () => controller.abort()
  }, [abierta, guia])

  useEffect(() => () => solicitud.current?.abort(), [])

  useEffect(() => {
    if (!abierta) return undefined
    function cerrarConEscape(event) {
      if (event.key !== 'Escape') return
      setAbierta(false)
      boton.current?.focus()
    }
    document.addEventListener('keydown', cerrarConEscape)
    return () => document.removeEventListener('keydown', cerrarConEscape)
  }, [abierta])

  useEffect(() => {
    if (abierta && pestana === 'chat') fin.current?.scrollIntoView({ block: 'end' })
  }, [mensajes, pensando, abierta, pestana])

  const temas = useMemo(() => guia?.temas || [], [guia])
  const resultados = useMemo(() => buscarTemas(temas, filtro), [temas, filtro])
  const dePantalla = useMemo(() => temasDePantalla(temas, pathname), [temas, pathname])

  if (!puedeVer) return null

  async function enviar(pregunta) {
    const limpia = pregunta.trim()
    if (limpia.length < 2 || pensando) return
    const temaAnterior = ultimoTema(mensajes)
    const controller = new AbortController()
    solicitud.current = controller
    setTexto('')
    setMensajes(previos => [...previos, { id: crypto.randomUUID(), rol: 'usuario', texto: limpia }])
    setPensando(true)
    try {
      const { data } = await api.post('/guia/preguntar', { pregunta: limpia, tema_anterior: temaAnterior }, { signal: controller.signal, timeout: 120000, sinAvisoCambios: true })
      setMensajes(previos => [...previos, { id: crypto.randomUUID(), rol: 'guia', texto: data.data.respuesta, origen: data.data.origen, temas: data.data.temas }])
    } catch (fallo) {
      if (!controller.signal.aborted) setMensajes(previos => [...previos, { id: crypto.randomUUID(), rol: 'guia', origen: 'sistema', texto: fallo.response?.data?.message || apiErrorMessage(fallo) }])
    } finally {
      if (solicitud.current === controller) setPensando(false)
    }
  }

  const cerrar = () => setAbierta(false)
  const pestanaClase = activa => `flex-1 rounded-lg px-3 py-1.5 text-xs font-semibold ${activa ? 'bg-white text-teal-800 shadow-sm' : 'text-slate-500'}`
  return <div className="fixed right-4 bottom-4 z-40 print:hidden">
    {abierta && <section role="dialog" aria-label="Chat de ayuda" className="mb-3 flex h-[34rem] max-h-[80dvh] w-[24rem] max-w-[calc(100vw-2rem)] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 shadow-2xl">
      <header className="flex items-center gap-2 border-b border-slate-200 bg-white px-4 py-3">
        <Icon name="robot" size={18} className="text-teal-700" />
        <h2 className="flex-1 text-sm font-semibold text-slate-800">Chat de ayuda</h2>
        {mensajes.length > 0 && <button type="button" onClick={() => setMensajes([])} disabled={pensando} className="text-[11px] font-semibold text-teal-800 underline disabled:opacity-50">Nueva conversación</button>}
        <button type="button" onClick={cerrar} aria-label="Cerrar chat" className="text-slate-500"><Icon name="close" size={18} /></button>
      </header>
      <div role="tablist" aria-label="Secciones del chat" className="m-2 mb-0 flex gap-1 rounded-xl bg-slate-200/70 p-1">
        <button type="button" role="tab" aria-selected={pestana === 'chat'} onClick={() => setPestana('chat')} className={pestanaClase(pestana === 'chat')}>Chat</button>
        <button type="button" role="tab" aria-selected={pestana === 'temas'} onClick={() => setPestana('temas')} className={pestanaClase(pestana === 'temas')}>Temas</button>
      </div>
      {error && <p role="alert" className="px-4 pt-3 text-xs text-rose-700">{error}</p>}
      {pestana === 'chat' ? <>
        <div className="flex flex-1 flex-col gap-3 overflow-y-auto p-4" aria-live="polite">
          {mensajes.length === 0 && <div className="space-y-3">
            <p className="text-xs text-slate-600">Pregúntame cómo hacer algo en el sistema. Por ejemplo:</p>
            <div className="flex flex-col items-start gap-2">{['¿Cómo subo un documento?', '¿Cómo saco una constancia de trabajo?', '¿Cómo registro una renuncia?'].map(ejemplo => <button key={ejemplo} type="button" onClick={() => enviar(ejemplo)} className="rounded-full border border-teal-200 bg-white px-3 py-1.5 text-left text-xs text-teal-800">{ejemplo}</button>)}</div>
            <ListaTemas titulo="De esta pantalla" temas={dePantalla.slice(0, 4)} onCerrar={cerrar} />
          </div>}
          {mensajes.map(mensaje => <Mensaje key={mensaje.id} mensaje={mensaje} onCerrar={cerrar} />)}
          {pensando && <p role="status" className="self-start rounded-2xl bg-white px-3 py-2 text-xs text-slate-500">Escribiendo…</p>}
          <div ref={fin} />
        </div>
        <form onSubmit={event => { event.preventDefault(); enviar(texto) }} className="flex items-center gap-2 border-t border-slate-200 bg-white p-3">
          <label htmlFor="guia-texto" className="sr-only">Escribe tu pregunta</label>
          <input id="guia-texto" value={texto} maxLength={300} onChange={event => setTexto(event.target.value)} placeholder="Escribe tu pregunta…" className="min-w-0 flex-1 rounded-xl border border-slate-200 px-3 py-2 text-xs focus-visible:outline-2 focus-visible:outline-teal-600" />
          <button type="submit" disabled={pensando || texto.trim().length < 2} className="rounded-xl bg-teal-700 px-3 py-2 text-xs font-semibold text-white disabled:opacity-50">Enviar</button>
        </form>
      </> : <div className="flex-1 space-y-4 overflow-y-auto p-4">
        <label htmlFor="guia-filtro" className="sr-only">Buscar en los temas</label>
        <input id="guia-filtro" value={filtro} onChange={event => setFiltro(event.target.value)} placeholder="Buscar en los temas…" className="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs focus-visible:outline-2 focus-visible:outline-teal-600" />
        {!guia && !error && <p className="text-xs text-slate-500">Cargando…</p>}
        {filtro.trim() && guia && (resultados.length ? <ListaTemas titulo="Resultados" temas={resultados} onCerrar={cerrar} /> : <p className="text-xs text-slate-500">Sin temas con esas palabras.</p>)}
        {!filtro.trim() && <ListaTemas titulo="En esta pantalla" temas={dePantalla} onCerrar={cerrar} />}
        {!filtro.trim() && <ListaTemas titulo="Todos los temas" temas={temas.filter(tema => !dePantalla.includes(tema))} onCerrar={cerrar} />}
        {guia?.borrador && <p className="text-[10px] text-slate-400">Guía en borrador, pendiente de revisión por la ORH.</p>}
      </div>}
    </section>}
    <div className="flex justify-end"><button ref={boton} type="button" onClick={() => setAbierta(valor => !valor)} aria-expanded={abierta} aria-label={abierta ? 'Cerrar chat de ayuda' : 'Abrir chat de ayuda'} title="Chat de ayuda" className="grid size-12 place-items-center rounded-full bg-teal-700 text-white shadow-lg transition hover:bg-teal-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700"><Icon name={abierta ? 'close' : 'robot'} size={22} /></button></div>
  </div>
}
