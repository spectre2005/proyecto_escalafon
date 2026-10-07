import { useCallback, useEffect, useRef, useState } from 'react'
import './RegistroGuiado.css'
import { observarCargas } from './observadorCargas'
import api, { apiErrorMessage } from '../../../../axios'
import { categoriaRecorrido, estadosRecorrido, puedeAvanzarAutomaticamente } from '../../config/recorrido'

const control = 'rounded-xl border px-3 py-2 text-sm font-semibold outline-none focus-visible:ring-2 focus-visible:ring-teal-600 disabled:opacity-50'
const boton = `${control} border-slate-300 bg-white text-slate-700`
const principal = `${control} border-teal-700 bg-teal-700 text-white`

/** Orquesta respuestas confirmadas sin reemplazar los formularios ni sus callbacks de guardado. */
export default function RegistroGuiado({ usuario, activo, onNavigate, contentRef }) {
  const [datos, setDatos] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [mensaje, setMensaje] = useState('')
  const [preguntar, setPreguntar] = useState(false)
  const [pendiente, setPendiente] = useState(null)
  const [abrir, setAbrir] = useState(null)
  const [resumen, setResumen] = useState(false)
  const posicion = useRef(null)
  const ocupado = useRef(false)
  const revision = useRef(0)
  const tituloRef = useRef(null)
  const ruta = `/expedientes/${usuario}/recorrido`
  const actual = datos?.secciones.find(seccion => seccion.id === datos.seccion_actual_id)

  const actualizar = useCallback(async () => {
    if (ocupado.current) return
    const version = ++revision.current
    try {
      const response = await api.get(ruta)
      if (version !== revision.current) return
      posicion.current = response.data.data.seccion_actual_id
      setDatos(response.data.data)
      setError('')
    } catch (failure) {
      if (version === revision.current) setError(apiErrorMessage(failure))
    }
  }, [ruta])

  useEffect(() => {
    const frame = requestAnimationFrame(actualizar)
    const contadorRevision = revision
    const refrescar = () => { if (activo) actualizar() }
    window.addEventListener('records-updated', refrescar)
    window.addEventListener('focus', refrescar)
    return () => {
      cancelAnimationFrame(frame)
      contadorRevision.current++
      window.removeEventListener('records-updated', refrescar)
      window.removeEventListener('focus', refrescar)
    }
  }, [activo, actualizar])

  useEffect(() => {
    if (!activo || !contentRef.current) return
    const contenido = contentRef.current
    const etiquetarTablas = () => {
      contenido.querySelectorAll('table').forEach(table => {
        const etiquetas = [...table.querySelectorAll('thead th')].map(header => header.textContent.trim())
        if (!table.hasAttribute('role')) table.setAttribute('role', 'table')
        table.querySelectorAll('tbody tr').forEach(row => {
          ;[...row.children].forEach((cell, index) => {
            if (cell.tagName === 'TD' && etiquetas[index]) cell.dataset.recorridoLabel = etiquetas[index]
          })
        })
      })
    }
    etiquetarTablas()
    const observer = new MutationObserver(etiquetarTablas)
    observer.observe(contenido, { subtree: true, childList: true })
    return () => observer.disconnect()
  }, [activo, contentRef])

  const actuar = useCallback(async (accion, otros = {}, navegar = true) => {
    if (ocupado.current) return null
    ocupado.current = true
    revision.current++
    setBusy(true)
    setError('')
    try {
      const response = await api.post(ruta, { accion, seccion_actual_id: posicion.current, ...otros })
      const next = response.data.data
      posicion.current = next.seccion_actual_id
      setDatos(next)
      setPreguntar(false)
      const seccion = next.secciones.find(seccion => seccion.id === next.seccion_actual_id)
      if (navegar) {
        setResumen(!seccion)
        onNavigate(seccion ? categoriaRecorrido(seccion) : null, true)
        requestAnimationFrame(() => tituloRef.current?.focus())
      }
      if (next.avisos?.length) setMensaje(next.avisos.join(' '))
      return next
    } catch (failure) {
      setError(failure.response?.data?.message && failure.response.status !== 422 ? failure.response.data.message : apiErrorMessage(failure))
      return null
    } finally {
      ocupado.current = false
      setBusy(false)
    }
  }, [onNavigate, ruta])

  useEffect(() => {
    if (!activo) return
    let vigente = true
    const liberar = observarCargas(api, usuario, async id => {
      setMensaje('Verificando el archivo y los folios del documento…')
      setPendiente(null)
      const next = await actuar('verificar', { documento_id: id }, false)
      if (!vigente || !next) return
      setMensaje('Documento guardado y verificado. Puedes completar los datos de su ficha antes de continuar.')
      setPendiente({ id, seccion: next.seccion_actual_id })
    }, () => {
      setPendiente(null)
      setMensaje('No se pudo confirmar el documento. Revisa el mensaje del formulario antes de continuar.')
    })
    return () => { vigente = false; liberar() }
  }, [activo, actuar, usuario])

  useEffect(() => {
    if (!activo || !pendiente || !actual || actual.id !== pendiente.seccion) return
    const contenido = contentRef.current
    if (!contenido) return
    let consumido = false
    const comprobar = () => {
      if (consumido || ocupado.current || !puedeAvanzarAutomaticamente(actual, !!contenido.querySelector('form, [role="dialog"]'))) return
      consumido = true
      setPendiente(null)
      actuar('continuar').then(next => {
        if (!next) return
        const destino = next.secciones.find(seccion => seccion.id === next.seccion_actual_id)
        setMensaje(`Documento guardado. Siguiente: ${destino?.nombre || 'resumen del legajo'}.${next.avisos?.length ? ` ${next.avisos.join(' ')}` : ''}`)
      })
    }
    const observer = new MutationObserver(comprobar)
    observer.observe(contenido, { subtree: true, childList: true, attributes: true })
    const frame = requestAnimationFrame(comprobar)
    return () => { observer.disconnect(); cancelAnimationFrame(frame) }
  }, [activo, actual, actuar, contentRef, pendiente])

  useEffect(() => {
    if (!abrir || !activo) return
    const contenido = contentRef.current
    if (!contenido) return
    let consumido = false
    const intentar = () => {
      if (consumido) return true
      const button = [...contenido.querySelectorAll('[data-recorrido-tipo]')].find(button => button.dataset.recorridoTipo === abrir.id)
      if (!button || button.disabled) return false
      for (let padre = button.parentElement; padre && padre !== contenido; padre = padre.parentElement) {
        if (padre.tagName === 'DETAILS') padre.open = true
      }
      consumido = true
      button.click()
      requestAnimationFrame(() => {
        contenido.querySelector('form input:not([readonly]):not([type="hidden"]), form select, form button')?.focus()
      })
      setAbrir(null)
      return true
    }
    const frame = requestAnimationFrame(intentar)
    const observer = new MutationObserver(intentar)
    observer.observe(contenido, { subtree: true, childList: true })
    return () => { cancelAnimationFrame(frame); observer.disconnect() }
  }, [abrir, activo, contentRef])

  async function agregar(seccion, item) {
    if (contentRef.current?.querySelector('form, [role="dialog"]')) {
      setMensaje('Guarda o cancela el formulario abierto antes de agregar otro documento.')
      return
    }
    const next = await actuar('ir', { seccion_destino_id: seccion.id }, false)
    if (!next) return
    setResumen(false)
    setPreguntar(false)
    onNavigate(categoriaRecorrido(seccion, item), true)
    setAbrir(item)
  }

  function continuar() {
    if (contentRef.current?.querySelector('form, [role="dialog"]')) {
      setMensaje('Guarda o cancela el formulario abierto antes de continuar.')
      return
    }
    if (actual.solo_opcionales) setPreguntar(true)
    else actuar('continuar')
  }

  async function cambiarSeccion(seccion) {
    if (contentRef.current?.querySelector('form, [role="dialog"]')) {
      setMensaje('Guarda o cancela el formulario abierto antes de cambiar de sección.')
      return
    }
    await actuar('ir', { seccion_destino_id: seccion.id })
  }

  if (!activo) return <section aria-label="Registro guiado" className="mb-6 flex min-w-0 flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-4">
    <div><h2 className="text-sm font-semibold text-slate-800">Registro guiado</h2><p className="mt-1 text-xs text-slate-600">Registra los documentos sección por sección.</p></div>
    <button className={principal} disabled={busy || !datos} onClick={() => {
      if (contentRef.current?.querySelector('form, [role="dialog"]')) setMensaje('Guarda o cancela el formulario abierto antes de iniciar el recorrido.')
      else actuar('iniciar')
    }}>{datos?.iniciado ? 'Continuar donde me quedé' : 'Iniciar registro guiado'}</button>
    {mensaje && <p role="status" className="w-full text-sm text-teal-900">{mensaje}</p>}
    {error && <p role="alert" className="w-full text-sm text-rose-700">{error} <button className="underline" onClick={actualizar}>Reintentar</button></p>}
  </section>

  const faltantes = actual?.items.filter(item => item.aplica && ((!item.registrado && item.exigido !== false) || item.documentos.some(doc => doc.errores.length))) || []
  const indice = datos?.secciones.findIndex(seccion => seccion.id === datos.seccion_actual_id) ?? -1
  return <section aria-label="Registro guiado" aria-busy={busy} className="mb-6 min-w-0 rounded-2xl border border-teal-200 bg-white p-4 sm:p-5">
    <div className="flex flex-wrap items-start justify-between gap-3"><div className="min-w-0">
      <p className="text-xs font-semibold text-teal-800">Registro guiado{actual ? ` · Sección ${indice + 1} de ${datos.total_secciones}` : ''}</p>
      <h2 ref={tituloRef} tabIndex={-1} className="mt-1 break-words text-lg font-semibold text-slate-800 outline-none focus-visible:ring-2 focus-visible:ring-teal-600">{actual?.nombre || 'Resumen del legajo'}</h2>
    </div><button className={boton} disabled={busy} onClick={() => { setPendiente(null); onNavigate(null, false) }}>Salir del recorrido</button></div>
    {datos && <><label className="mt-4 block text-xs text-slate-700">{datos.resumen.secciones_resueltas} de {datos.total_secciones} secciones completas, omitidas o no aplicables
      <progress className="mt-2 block h-3 w-full accent-teal-700" value={datos.resumen.secciones_resueltas} max={datos.total_secciones || 1} aria-label="Avance del registro guiado" /></label>
      <ol className="mt-3 flex flex-wrap gap-2" aria-label="Estado de cada sección">{datos.secciones.map((seccion, numero) => <li key={seccion.id}><button className={`${boton} text-xs ${seccion.id === actual?.id ? 'border-teal-700 bg-teal-50 text-teal-900' : ''}`} disabled={busy} aria-current={seccion.id === actual?.id ? 'step' : undefined} onClick={() => cambiarSeccion(seccion)}>{numero + 1}. {estadosRecorrido[seccion.estado]}</button></li>)}</ol></>}
    <p role="status" aria-live="polite" className="mt-3 text-sm text-teal-900">{mensaje}</p>
    {error && <p role="alert" className="mt-3 rounded-xl bg-rose-50 p-3 text-sm text-rose-800">{error} <button className="font-semibold underline" onClick={actualizar}>Actualizar recorrido</button></p>}
    {!datos && <p role="status" className="mt-3 text-sm text-slate-600">Consultando el recorrido…</p>}
    {actual && <><p className="mt-3 text-sm text-slate-700">{actual.motivo}</p>
      {faltantes.length > 0 && <ul className="mt-3 space-y-2" aria-label="Documentos por completar">{faltantes.map(item => <li key={`${item.id}:${item.familiar_id || ''}`} className="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-slate-50 p-3 text-sm text-slate-700"><div className="min-w-0 flex-1 break-words"><span className="font-semibold">{item.nombre}</span><p className="mt-1 text-xs">{item.documentos.flatMap(doc => doc.errores).join(' ') || item.motivo}</p></div>{!['por_determinar', 'restringido'].includes(item.estado) && <button className={boton} disabled={busy} onClick={() => agregar(actual, item)}>Agregar ahora</button>}</li>)}</ul>}
      {actual.items.some(item => item.aplica && item.exigido === false) && <details className="mt-3 rounded-xl border border-slate-200 p-3"><summary className="cursor-pointer text-sm font-semibold text-slate-700">Documentos opcionales</summary><ul className="mt-3 space-y-2">{actual.items.filter(item => item.aplica && item.exigido === false).map(item => <li key={`${item.id}:${item.familiar_id || ''}`} className="flex flex-wrap items-center justify-between gap-2 text-sm text-slate-700"><span className="min-w-0 flex-1 break-words">{item.nombre}{item.registrado ? ' · Registrado' : ''}</span><button className={boton} disabled={busy} onClick={() => agregar(actual, item)}>Agregar ahora</button></li>)}</ul></details>}
      <div className="mt-4 flex flex-wrap gap-2"><button className={boton} disabled={busy || indice <= 0} onClick={() => {
        if (contentRef.current?.querySelector('form, [role="dialog"]')) setMensaje('Guarda o cancela el formulario abierto antes de volver.')
        else { setPendiente(null); actuar('anterior') }
      }}>Anterior</button>{actual.solo_opcionales && <button className={boton} disabled={busy || !actual.puede_continuar} onClick={() => setPreguntar(true)}>Omitir</button>}
        <button className={principal} disabled={busy || !actual.puede_continuar} title={!actual.puede_continuar ? actual.motivo : undefined} onClick={continuar}>Continuar</button>
        <button className={boton} onClick={() => setResumen(!resumen)}>{resumen ? 'Ocultar resumen' : 'Ver resumen'}</button></div>
      {preguntar && <div className="mt-4 rounded-xl border border-teal-200 bg-teal-50 p-4"><p className="text-sm text-teal-900">¿Quieres agregar algún documento opcional antes de continuar?</p>
        <div className="mt-3 flex flex-wrap gap-2"><button className={boton} onClick={() => setPreguntar(false)}>Quedarme en esta sección</button><button className={principal} disabled={busy} onClick={() => {
          if (contentRef.current?.querySelector('form, [role="dialog"]')) setMensaje('Guarda o cancela el formulario abierto antes de omitir la sección.')
          else { setPendiente(null); actuar('omitir') }
        }}>Omitir por ahora</button></div></div>}
    </>}
    {datos && (resumen || !actual) && <div className="mt-5 border-t border-slate-200 pt-4"><h3 className="font-semibold text-slate-800">Resumen del legajo</h3>
      <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-2">{[['Obligatorios completos', datos.resumen.obligatorios_completos], ['Obligatorios pendientes', datos.resumen.obligatorios_pendientes], ['Pendientes de revisión', datos.resumen.documentos_pendientes_revision], ['Documentos observados', datos.resumen.documentos_observados], ['Opcionales omitidos por ahora', datos.resumen.opcionales_omitidos], ['Requisitos por determinar', datos.resumen.por_determinar]].map(([nombre, valor]) => <div key={nombre}><dt className="text-slate-600">{nombre}</dt><dd className="font-semibold text-slate-800">{valor}</dd></div>)}</dl>
      <ul className="mt-4 space-y-2">{datos.secciones.map(seccion => <li key={seccion.id}><button className="break-words text-left text-sm font-semibold text-teal-800 underline outline-none focus-visible:ring-2 focus-visible:ring-teal-600" disabled={busy} onClick={() => cambiarSeccion(seccion)}>{seccion.nombre} · {estadosRecorrido[seccion.estado]}</button></li>)}</ul>
    </div>}
  </section>
}
