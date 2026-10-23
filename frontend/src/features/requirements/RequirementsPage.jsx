import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import api, { apiErrorMessage } from '../../axios'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'
import { usePuede, useSesion } from '../auth/session'
import { separarHistorialCorreo } from './historialCorreo'
import DocumentoModal from '../legajos/components/documentos/DocumentoModal'

const panel = 'rounded-2xl border border-slate-200 bg-white p-5'
const control = 'w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 focus:ring-2 focus:ring-teal-600'
const button = 'rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-teal-800 hover:bg-teal-50 disabled:opacity-50'
const estados = { abierto: 'Pendiente de respuesta', en_revision: 'Respondido · pendiente de revisión', observado: 'Observado', aceptado: 'Resuelto', cancelado: 'Cancelado' }
const fecha = value => value ? value.slice(0, 10).split('-').reverse().join('/') : 'Sin fecha'
const nombre = p => [p?.nombres, p?.apellido_paterno, p?.apellido_materno].filter(Boolean).join(' ')
function Campo({ label, children }) { return <label className="block text-sm text-slate-700"><span className="mb-1 block font-medium">{label}</span>{children}</label> }

const normalizar = value => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()

function NuevoRequerimiento({ opciones, busy, error, progreso, onSubmit, onCancel }) {
  const [busqueda, setBusqueda] = useState('')
  const [persona, setPersona] = useState(null)
  const [abierto, setAbierto] = useState(false)
  const [activo, setActivo] = useState(0)
  const [tipos, setTipos] = useState([])
  const [filtroTipo, setFiltroTipo] = useState('')
  const palabras = normalizar(busqueda).trim().split(/\s+/).filter(Boolean)
  const coincidencias = (opciones?.personas || []).filter(p => palabras.every(palabra => normalizar(`${nombre(p)} ${p.numero_doc_identidad}`).includes(palabra)))
  const resultados = coincidencias.slice(0, 15)
  function elegir(p) {
    setPersona(p); setBusqueda(`${nombre(p)} · ${p.numero_doc_identidad}`); setAbierto(false)
  }
  function teclado(e) {
    if (e.key === 'Escape') { setAbierto(false); return }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault(); setAbierto(true)
      setActivo(value => Math.max(0, Math.min(resultados.length - 1, value + (e.key === 'ArrowDown' ? 1 : -1))))
    }
    if (e.key === 'Enter' && abierto) {
      e.preventDefault()
      if (resultados[activo]) elegir(resultados[activo])
    }
  }
  return <form onSubmit={e => onSubmit(e, persona, tipos)} className="space-y-4">
    <div className="relative"><Campo label="Buscar persona por nombre o DNI"><input autoComplete="off" role="combobox" aria-expanded={abierto} aria-controls="personas-requerimiento" aria-autocomplete="list" aria-activedescendant={abierto && resultados[activo] ? `persona-${resultados[activo].id}` : undefined} value={busqueda} placeholder="Escribe un nombre, apellido o DNI" onFocus={() => setAbierto(true)} onBlur={() => setAbierto(false)} onKeyDown={teclado} onChange={e => { setBusqueda(e.target.value); setPersona(null); setActivo(0); setAbierto(true) }} className={control} required /></Campo>
      {abierto && <ul id="personas-requerimiento" role="listbox" aria-label="Personas con legajo activo" className="absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-lg">{resultados.map((p, i) => <li key={p.id} id={`persona-${p.id}`} role="option" aria-selected={activo === i}><button type="button" className={`w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-teal-50 ${activo === i ? 'bg-teal-50' : ''}`} onMouseDown={e => e.preventDefault()} onClick={() => elegir(p)}><span className="block font-medium">{nombre(p)}</span><span className="text-xs text-slate-500">DNI / documento: {p.numero_doc_identidad} · {p.email_institucional || 'Sin correo registrado'}</span></button></li>)}{!resultados.length && <li className="p-3 text-sm text-slate-500">No se encontraron personas con legajo activo.</li>}{coincidencias.length > 15 && <li className="p-3 text-xs text-slate-500">Escribe más letras o el DNI para precisar la búsqueda.</li>}</ul>}
    </div>
    {persona && <p className="rounded-xl bg-teal-50 p-3 text-sm text-teal-900">Destinatario: {persona.email_institucional || 'Sin correo: complétalo en Personal antes de enviar.'}</p>}
    <fieldset className="space-y-2"><legend className="text-sm font-medium text-slate-700">Tipos de documento ({tipos.length} seleccionados)</legend><input aria-label="Filtrar tipos de documento" placeholder="Buscar tipo de documento" className={control} value={filtroTipo} onChange={e => setFiltroTipo(e.target.value)} /><div className="max-h-52 space-y-1 overflow-y-auto rounded-xl border border-slate-200 p-2">{opciones?.tipos.filter(t => normalizar(t.nombre).includes(normalizar(filtroTipo))).map(t => <label key={t.id} className="flex cursor-pointer items-start gap-2 rounded-lg p-2 text-sm hover:bg-slate-50"><input type="checkbox" checked={tipos.includes(t.id)} onChange={e => setTipos(current => e.target.checked ? [...current, t.id] : current.filter(id => id !== t.id))} className="mt-0.5 accent-teal-700" /><span>{t.nombre}</span></label>)}</div><p className="text-xs text-slate-500">Selecciona hasta 20 documentos. Cada uno tendrá su propio requerimiento y correo. Si no seleccionas ninguno, se registrará una corrección de datos u otro pedido.</p></fieldset>
    <Campo label="Detalle de lo solicitado"><textarea required name="descripcion" maxLength={300} className={control} /></Campo>
    <Campo label="Fecha límite"><input required name="fecha_limite" type="date" className={control} /></Campo>
    <p className="text-xs text-slate-600">El envío se realiza solo al pulsar este botón. Registrar una persona o abrir este panel no envía correos.</p>
    {progreso && <p role="status" className="text-sm text-teal-800">{progreso}</p>}
    {error && <p role="alert" className="text-sm text-rose-800">{error}</p>}
    <div className="flex flex-wrap gap-2"><button disabled={busy || !opciones || !persona || tipos.length > 20} className={button}>{busy ? 'Registrando y enviando…' : `Registrar y enviar ${tipos.length || 1} correo(s)`}</button><button type="button" className={button} disabled={busy} onClick={onCancel}>Cancelar</button></div>
  </form>
}

function ArchivoEntrega({ recordId, evento, adjunto, nombreArchivo }) {
  const [url, setUrl] = useState(null)
  const [error, setError] = useState('')
  useEffect(() => {
    let vigente = true
    let creado
    const controller = new AbortController()
    api.get(`/solicitudes-legajo/${recordId}/archivos/${evento}/${adjunto}?ver=1`, { responseType: 'blob', signal: controller.signal })
      .then(response => { if (vigente) { creado = URL.createObjectURL(response.data); setUrl(creado) } })
      .catch(e => { if (vigente && e.code !== 'ERR_CANCELED') setError('No se pudo abrir el PDF.') })
    return () => { vigente = false; controller.abort(); if (creado) URL.revokeObjectURL(creado) }
  }, [recordId, evento, adjunto])
  return <section className="mt-3 rounded-lg border border-slate-200 bg-white p-3" aria-label={`Documento recibido: ${nombreArchivo}`}>
    <p className="break-all font-medium">{nombreArchivo}</p>
    {error && <p role="alert" className="text-rose-800">{error}</p>}
    {url ? <><iframe title={nombreArchivo} src={url} className="mt-2 h-80 w-full rounded border border-slate-200" /><a href={url} download={nombreArchivo} className="mt-2 inline-block font-semibold text-teal-800 underline">Descargar PDF</a></> : !error && <p role="status">Cargando documento…</p>}
  </section>
}

function Detalle({ record, admin, propio, onClose }) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [success, setSuccess] = useState('')
  const [accion, setAccion] = useState(record.estado === 'en_revision' && !propio ? 'observar' : 'recordar')
  const [documentos, setDocumentos] = useState([])
  const abierto = ['abierto', 'observado', 'en_revision'].includes(record.estado)
  async function enviar(event, path) {
    event.preventDefault()
    const form = event.currentTarget
    setBusy(true); setError(''); setSuccess('')
    try {
      const data = new FormData(form)
      if (!data.get('pdf')?.size) data.delete('pdf')
      const response = await api.post(`/solicitudes-legajo/${record.id}/${path}`, data)
      if (response.data.correo && response.data.correo.correo_estado !== 'enviado') {
        setError('La acción se registró, pero el correo no se envió. Revisa la conexión y el correo de la persona. No habrá reintentos automáticos.')
      } else { form.reset(); setSuccess('Operación registrada.'); onClose() }
    } catch (e) { setError(e.response?.data?.message || apiErrorMessage(e)) } finally { setBusy(false) }
  }
  async function enviarPendiente() {
    setBusy(true); setError('')
    try {
      const { data } = await api.post(`/solicitudes-legajo/${record.id}/enviar`, { notificacion_id: record.correo.id }, { timeout: 60000 })
      if (data.correo.correo_estado === 'enviado') onClose()
      else setError(data.correo.correo_error || 'No se envió el correo. Revisa la conexión en Configuración.')
    } catch (e) { setError(apiErrorMessage(e)) } finally { setBusy(false) }
  }
  async function elegirAccion(value) {
    setAccion(value)
    if (value === 'aceptar' && record.tipo_documento_id) {
      try { setDocumentos((await api.get(`/solicitudes-legajo/${record.id}/documentos`)).data.data) } catch (e) { setError(apiErrorMessage(e)) }
    }
  }
  return <DocumentoModal titulo="Seguimiento del requerimiento" busy={busy} onCancel={onClose} lateral cerrarAlClickFuera>
    <section aria-label="Detalle del requerimiento">
    <p className="mt-3 text-sm">{nombre(record.usuario)} · {record.descripcion}</p>
    {record.correo && <p className="mt-2 rounded-lg bg-white p-3 text-sm">Correo: {record.correo.correo_destinatario || 'Sin destinatario'} · {record.correo.correo_estado}{record.correo.correo_error && <span className="block text-rose-800">{record.correo.correo_error}</span>}</p>}
    {admin && abierto && ['pendiente', 'sin_configurar', 'error', 'sin_correo'].includes(record.correo?.correo_estado) && <button type="button" disabled={busy} className={`${button} mt-2`} onClick={enviarPendiente}>Enviar este correo pendiente</button>}
    <p className="mt-2 text-sm">Plazo: {fecha(record.prorroga_hasta || record.fecha_limite)} · {estados[record.estado] || record.estado}</p>
    <h3 className="mt-5 font-semibold">Historial y entregas del docente</h3>
    <ol className="my-3 space-y-3">{(record.seguimiento || []).map((item, i) => {
      const esRespuesta = item.accion === 'respuesta'
      const { respuesta, historial } = esRespuesta ? separarHistorialCorreo(item.mensaje) : { respuesta: item.mensaje }
      const archivos = item.archivos || (item.archivo ? [{ archivo: item.archivo }] : [])
      return <li key={item.gmail_id || i} className="rounded-xl bg-slate-50 p-3 text-sm">
        <p className="font-semibold">{esRespuesta ? 'Entrega del docente' : item.accion} · {fecha(item.fecha)}</p>
        {esRespuesta && <p className="mt-3 text-slate-700"><strong>Canal:</strong> {item.canal === 'correo_manual' ? 'Correo registrado por administración' : item.canal === 'gmail' ? 'Gmail' : 'Plataforma (histórico)'}</p>}
        {item.remitente && <p className="mt-2 break-words text-slate-700"><strong>Remitente:</strong> {item.remitente}</p>}
        {item.referencia_correo && <p className="mt-2 break-words text-slate-700"><strong>Asunto:</strong> {item.referencia_correo}</p>}
        {esRespuesta && <p className="mt-3"><strong>Respuesta:</strong></p>}
        <p className="mt-1 whitespace-pre-wrap break-words">{respuesta || (esRespuesta ? 'Sin mensaje de respuesta.' : '')}</p>
        {historial && <details className="mt-3 rounded-lg border border-slate-200 bg-white p-3">
          <summary className="cursor-pointer font-semibold text-teal-800">Ver historial del correo</summary>
          <p className="mt-3 whitespace-pre-wrap break-words text-slate-600">{historial}</p>
        </details>}
        {esRespuesta && <p className="mt-4"><strong>Documento:</strong>{!archivos.length && ' Sin documentos adjuntos.'}</p>}
        {archivos.map((archivo, j) => <ArchivoEntrega key={`${archivo.hash || j}-${i}`} recordId={record.id} evento={i} adjunto={j} nombreArchivo={archivo.archivo} />)}
        {item.avisos_adjuntos?.map((aviso, j) => <p key={j} className="mt-2 text-xs text-amber-900">{aviso}</p>)}
      </li>
    })}</ol>
    {error && <p role="alert" className="my-3 text-sm text-rose-800">{error}</p>}{success && <p role="status">{success}</p>}
    {admin && ['abierto', 'observado'].includes(record.estado) && <form onSubmit={e => enviar(e, 'registrar-entrega')} className="space-y-3 border-t pt-4"><h3 className="font-semibold">Registrar respuesta recibida por correo</h3><p className="text-xs text-slate-600">Las respuestas identificadas por Gmail se incorporan automáticamente. Usa este formulario solo para entregas que debas registrar manualmente.</p><Campo label="Correo del remitente"><input required type="email" name="remitente" maxLength={255} className={control} /></Campo><Campo label="Referencia del correo (asunto y fecha)"><input required name="referencia_correo" maxLength={300} className={control} /></Campo><Campo label="Respuesta o explicación de por qué no corresponde"><textarea required maxLength={3000} name="mensaje" className={control} /></Campo><Campo label="Documento PDF (opcional)"><input type="file" name="pdf" accept="application/pdf" className={control} /></Campo><p className="text-xs text-slate-600">La entrega quedará pendiente de revisión. El legajo se actualizará después de la aprobación.</p><button disabled={busy} className={button}>Registrar entrega para revisión</button></form>}
    {admin && abierto && <form onSubmit={e => enviar(e, 'gestionar')} className="mt-4 space-y-3 border-t pt-4"><h3 className="font-semibold">Gestión administrativa</h3><Campo label="Acción"><select name="accion" value={accion} onChange={e => elegirAccion(e.target.value)} className={control}><option value="observar" disabled={record.estado !== 'en_revision' || propio}>Observar entrega</option><option value="aceptar" disabled={record.estado !== 'en_revision' || propio}>Resolver requerimiento</option><option value="recordar">Enviar aviso por correo</option><option value="prorrogar">Ampliar plazo</option><option value="cancelar">Cancelar requerimiento</option></select></Campo>
      <Campo label="Comentario y motivo de la decisión"><textarea required name="mensaje" maxLength={3000} className={control} /></Campo>
      {accion === 'prorrogar' && <Campo label="Nueva fecha límite"><input required type="date" name="prorroga_hasta" className={control} /></Campo>}
      {accion === 'aceptar' && <><p className="text-sm text-slate-600">Revisa la entrega e incorpora el documento o corrige los datos desde la sección correspondiente del legajo. Después registra aquí la resolución.</p><Link to={`/legajos/${record.usuario_id}`} className="inline-block text-sm font-semibold text-teal-800 underline">Abrir legajo para actualizarlo</Link>{record.tipo_documento_id && <Campo label="Documento incorporado y aceptado"><select required name="documento_id" className={control}><option value="">Selecciona el documento del legajo</option>{documentos.map(d => <option key={d.id} value={d.id}>{d.nombre || d.num_resolucion || d.id} · {fecha(d.fecha_incorporacion)}</option>)}</select><button type="button" className={`${button} mt-2`} onClick={() => elegirAccion('aceptar')}>Actualizar documentos disponibles</button></Campo>}</>}
      <button disabled={busy} className={button}>Registrar acción</button></form>}
    </section>
  </DocumentoModal>
}

function GestionRequerimientos() {
  const admin = usePuede('requerimientos.gestionar')
  const { usuario } = useSesion()
  const [params, setParams] = useSearchParams()
  const clase = params.get('clase') || 'documentos'
  const [nuevo, setNuevo] = useState(false)
  const [seleccion, setSeleccion] = useState(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [resultadoEnvio, setResultadoEnvio] = useState('')
  const [progresoEnvio, setProgresoEnvio] = useState('')
  const queryParams = new URLSearchParams(params)
  if (clase === 'sugerencias') queryParams.delete('clase')
  else queryParams.set('clase', clase)
  const request = useLiveQuery(`/solicitudes-legajo?${queryParams.toString()}`)
  const opciones = useLiveQuery('/solicitudes-legajo/opciones')
  const sugerencias = useLiveQuery(admin && clase === 'sugerencias' ? `/solicitudes-legajo/sugerencias?q=${encodeURIComponent(params.get('q') || '')}&page=${params.get('page') || 1}` : '/solicitudes-legajo/opciones')
  const result = clase === 'sugerencias' ? sugerencias.data : request.data
  const selected = request.data?.data?.find(r => r.id === seleccion)
  function filtrar(key, value) {
    const next = new URLSearchParams(params); value ? next.set(key, value) : next.delete(key); if (key !== 'page') next.delete('page'); setParams(next, { replace: key === 'q' }); setSeleccion(null)
  }
  async function crear(e, persona, tipos) {
    e.preventDefault()
    if (!persona) { setError('Selecciona una persona de los resultados por nombre o DNI.'); return }
    setBusy(true); setError(''); setResultadoEnvio('')
    try {
      const data = Object.fromEntries(new FormData(e.currentTarget))
      data.usuario_id = persona.id
      data.tipo_documento_ids = tipos
      data.clase = clase === 'actualizacion' ? 'actualizacion' : 'documentos'
      const response = await api.post('/solicitudes-legajo', data)
      const { total, correos } = response.data
      let enviados = 0
      for (const [index, correo] of correos.entries()) {
        setProgresoEnvio(`Enviando correo ${index + 1} de ${total}…`)
        try {
          const envio = await api.post(`/solicitudes-legajo/${correo.requerimiento_id}/enviar`, { notificacion_id: correo.id }, { timeout: 60000 })
          if (envio.data.correo.correo_estado === 'enviado') enviados++
        } catch { /* El registro se conserva y el resultado informa los envíos no confirmados. */ }
      }
      setNuevo(false); filtrar('clase', data.clase)
      setResultadoEnvio(`${total} requerimiento(s) registrado(s). ${enviados} correo(s) enviado(s).${enviados < total ? ' Los restantes no se enviaron: revisa el correo de la persona y la conexión en Configuración. No se reintentarán automáticamente.' : ''}`)
      request.retry()
    } catch (e) { setError(e.response?.data?.message || apiErrorMessage(e)) } finally { setBusy(false); setProgresoEnvio('') }
  }
  return <>
    <header className="mb-6"><p className="text-xs font-semibold uppercase tracking-widest text-teal-700">Seguimiento documental</p><h1 className="mt-2 text-3xl font-bold text-slate-800">Requerimientos</h1><p className="mt-3 text-sm text-slate-600">Envía requerimientos por correo y revisa las respuestas y documentos recibidos automáticamente.</p><p className="mt-2 text-xs text-slate-500">Los docentes responden por correo; no necesitan ingresar al sistema ni crear una cuenta.</p></header>
    <p className="mb-5 rounded-xl bg-teal-50 p-4 text-sm text-teal-900">Envío manual: registra un requerimiento y selecciona los documentos que deseas pedir. No se envían correos automáticamente al registrar personas ni recordatorios por fecha.</p>
    {request.data && <p className="mb-5 text-sm text-slate-600">{request.data.recepcion_automatica ? 'Recepción automática activa: se revisa Gmail cada minuto. El docente debe responder desde su correo registrado y conservar la referencia [REQ-…] en el asunto.' : 'Para recibir respuestas automáticamente, el administrador debe volver a conectar Gmail desde Configuración y conceder el permiso de lectura.'}</p>}
    {resultadoEnvio && <p role="status" className="mb-5 rounded-xl border border-teal-200 p-4 text-sm text-teal-900">{resultadoEnvio}</p>}
    {request.data && !request.data.correo_configurado && <p role="status" className="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">El correo de salida aún no está configurado. Los requerimientos pueden registrarse, pero sus correos no se enviarán. Conecta la cuenta institucional de Gmail desde Configuración o configura SMTP antes de realizar un envío manual.</p>}
    {!!request.data?.avisos?.length && <section className={`${panel} mb-5`} aria-label="Mis avisos"><h2 className="font-bold">Mis avisos recientes</h2><ul className="mt-3 space-y-2">{request.data.avisos.map(aviso => <li key={aviso.id} className="rounded-xl bg-teal-50 p-3 text-sm"><p className="font-semibold">{aviso.titulo} · {fecha(aviso.created_at)}</p><p>{aviso.mensaje}</p></li>)}</ul></section>}
    <nav className="mb-5 flex flex-wrap gap-2" aria-label="Tipo de requerimientos">{[['documentos', 'Documentos pendientes'], ['actualizacion', 'Solicitudes de actualización'], ...(admin ? [['sugerencias', 'Completar perfil']] : [])].map(([key, label]) => <button key={key} className={`${button} ${clase === key ? 'bg-teal-100' : 'bg-white'}`} aria-pressed={clase === key} onClick={() => { const next = new URLSearchParams(); next.set('clase', key); setParams(next); setNuevo(false); setSeleccion(null) }}>{label}</button>)}</nav>
    <section className={`${panel} mb-5`}><form className="flex flex-wrap items-end gap-3" onSubmit={e => { e.preventDefault(); filtrar('q', new FormData(e.currentTarget).get('q')) }}><div className="min-w-60 flex-1"><Campo label="Buscar por nombre, DNI o descripción"><input name="q" value={params.get('q') || ''} onChange={e => filtrar('q', e.target.value)} className={control} maxLength={120} /></Campo></div>{clase !== 'sugerencias' && <Campo label="Estado"><select className={control} value={params.get('estado') || ''} onChange={e => filtrar('estado', e.target.value)}><option value="">Todos</option>{Object.entries(estados).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></Campo>}<button className={button}>Buscar</button><button type="button" className={button} onClick={() => { setError(''); setNuevo(true) }}>Nuevo requerimiento</button></form></section>
    {nuevo && <DocumentoModal titulo="Registrar y enviar requerimientos" busy={busy} onCancel={() => setNuevo(false)} compacto><NuevoRequerimiento opciones={opciones.data} busy={busy} error={error} progreso={progresoEnvio} onSubmit={crear} onCancel={() => setNuevo(false)} /></DocumentoModal>}
    {(request.error || opciones.error || (clase === 'sugerencias' && sugerencias.error)) && <p role="alert" className="mb-4 text-rose-800">No se pudo cargar la información. <button onClick={() => { request.retry(); opciones.retry(); sugerencias.retry() }} className="underline">Reintentar</button></p>}
    {!result && <p role="status" className={panel}>Consultando requerimientos…</p>}
    {result && <section className={panel}><div className="mb-4 flex justify-between"><h2 className="font-bold">{clase === 'sugerencias' ? 'Perfil por completar' : 'Requerimientos'}</h2><span className="text-sm text-slate-600">{result.total ?? result.meta?.total ?? 0} registros</span></div>
      {clase === 'sugerencias' && <p className="mb-4 text-sm text-slate-600">Estos requisitos todavía no generan requerimientos. Completa la información del perfil para determinar si corresponde pedir el documento.</p>}
      <div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-slate-50"><tr>{['Persona', 'Detalle', 'Plazo / estado', 'Acción'].map(t => <th key={t} className="p-3">{t}</th>)}</tr></thead><tbody>{result.data?.map(r => <tr key={r.id} className="border-t border-slate-200 align-top"><td className="p-3">{clase === 'sugerencias' ? (r.persona || r.nombre) : nombre(r.usuario)}</td><td className="max-w-lg p-3"><p>{r.descripcion || r.detalle}</p>{r.origen === 'automatico' && <span className="mt-2 inline-block rounded-full bg-sky-50 px-2 py-1 text-xs text-sky-800">Detección anterior</span>}{r.tipo_documento && <p className="mt-1 text-xs text-slate-500">{r.tipo_documento.nombre}</p>}{r.correo && <p className="mt-2 text-xs text-slate-600">Correo: {({ pendiente: 'No enviado (envío manual)', sin_configurar: 'No enviado: revisar conexión', enviado: 'Enviado', error: 'Error de envío', sin_correo: 'Sin destinatario válido', cancelado: 'Envío cancelado' })[r.correo.correo_estado]} · {r.correo.correo_destinatario || 'Sin correo registrado'}</p>}</td><td className="p-3">{clase === 'sugerencias' ? r.estado : <><p>{fecha(r.prorroga_hasta || r.fecha_limite)}</p><span className={`mt-1 inline-block rounded-lg px-2 py-1 text-xs ${r.atrasado || r.estado === 'observado' ? 'bg-rose-100 text-rose-800' : 'bg-teal-50 text-teal-800'}`}>{estados[r.estado] || r.estado}{r.atrasado ? ' · Fuera de plazo' : ''}</span></>}</td><td className="p-3">{clase === 'sugerencias' ? <><Link to={r.destino_revision || `/legajos/${r.usuario_id}`} className="mt-2 block text-teal-800 underline">Revisar legajo</Link></> : <button className={button} onClick={() => setSeleccion(r.id)}>Ver y gestionar</button>}</td></tr>)}</tbody></table></div>
      {!result.data?.length && <p className="p-8 text-center text-sm text-slate-600">No hay registros para estos filtros.</p>}
      <div className="mt-5 flex items-center justify-between text-sm"><button className={button} disabled={(result.current_page ?? result.meta?.current_page ?? 1) <= 1} onClick={() => filtrar('page', Number(params.get('page') || 1) - 1)}>Anterior</button><span>Página {result.current_page ?? result.meta?.current_page ?? 1} de {result.last_page ?? result.meta?.last_page ?? 1}</span><button className={button} disabled={(result.current_page ?? result.meta?.current_page ?? 1) >= (result.last_page ?? result.meta?.last_page ?? 1)} onClick={() => filtrar('page', Number(params.get('page') || 1) + 1)}>Siguiente</button></div>
    </section>}
    {selected && <Detalle key={`${selected.id}-${selected.estado}`} record={selected} admin={admin} propio={selected.usuario_id === usuario?.id} onClose={() => setSeleccion(null)} />}
  </>
}

export default function RequirementsPage() {
  const autorizado = usePuede('requerimientos.gestionar')
  if (!autorizado) return <p role="alert" className={panel}>El módulo de Requerimientos está reservado al administrador y a sus ayudantes autorizados. Los docentes responden por correo.</p>
  return <GestionRequerimientos />
}
