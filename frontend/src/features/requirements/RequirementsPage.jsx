import { useEffect, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import api, { apiErrorMessage } from '../../axios'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'
import { usePuede, useSesion } from '../auth/session'
import DocumentoModal from '../legajos/components/documentos/DocumentoModal'

const panel = 'rounded-2xl border border-slate-200 bg-white p-5'
const control = 'w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 focus:ring-2 focus:ring-teal-600'
const button = 'rounded-xl border border-slate-300 px-4 py-2 text-sm font-semibold text-teal-800 hover:bg-teal-50 disabled:opacity-50'
const estados = { abierto: 'Pendiente de respuesta', en_revision: 'En revisión', observado: 'Observado', aceptado: 'Resuelto', cancelado: 'Cancelado' }
const fecha = value => value ? value.slice(0, 10).split('-').reverse().join('/') : 'Sin fecha'
const nombre = p => [p?.nombres, p?.apellido_paterno, p?.apellido_materno].filter(Boolean).join(' ')
function Campo({ label, children }) { return <label className="block text-sm text-slate-700"><span className="mb-1 block font-medium">{label}</span>{children}</label> }

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
      await api.post(`/solicitudes-legajo/${record.id}/${path}`, data)
      form.reset(); setSuccess('Operación registrada.'); onClose()
    } catch (e) { setError(e.response?.data?.message || apiErrorMessage(e)) } finally { setBusy(false) }
  }
  async function descargar(index) {
    setError('')
    try {
      const response = await api.get(`/solicitudes-legajo/${record.id}/archivos/${index}`, { responseType: 'blob' })
      const url = URL.createObjectURL(response.data)
      const a = document.createElement('a'); a.href = url; a.download = 'entrega.pdf'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000)
    } catch (e) { setError(apiErrorMessage(e)) }
  }
  async function elegirAccion(value) {
    setAccion(value)
    if (value === 'aceptar' && record.tipo_documento_id) {
      try { setDocumentos((await api.get(`/solicitudes-legajo/${record.id}/documentos`)).data.data) } catch (e) { setError(apiErrorMessage(e)) }
    }
  }
  return <DocumentoModal titulo="Seguimiento del requerimiento" busy={busy} onCancel={onClose} lateral>
    <section aria-label="Detalle del requerimiento">
    <p className="mt-3 text-sm">{nombre(record.usuario)} · {record.descripcion}</p>
    <p className="mt-2 text-sm">Plazo: {fecha(record.prorroga_hasta || record.fecha_limite)} · {estados[record.estado] || record.estado}</p>
    <h3 className="mt-5 font-semibold">Historial y entregas del docente</h3><ol className="my-3 space-y-3">{(record.seguimiento || []).map((item, i) => <li key={i} className="rounded-xl bg-slate-50 p-3 text-sm"><p className="font-semibold">{item.accion === 'respuesta' ? 'Entrega del docente' : item.accion} · {fecha(item.fecha)}</p>{item.accion === 'respuesta' && <p className="mt-1 text-xs text-teal-800">Canal: {item.canal === 'gmail' ? 'Gmail' : 'Plataforma'}</p>}<p className="mt-1 whitespace-pre-wrap">{item.mensaje}</p>{item.archivo && <button className={`${button} mt-2`} onClick={() => descargar(i)}>Descargar {item.archivo}</button>}</li>)}</ol>
    {error && <p role="alert" className="my-3 text-sm text-rose-800">{error}</p>}{success && <p role="status">{success}</p>}
    {propio && ['abierto', 'observado'].includes(record.estado) && <form onSubmit={e => enviar(e, 'responder')} className="space-y-3 border-t pt-4"><h3 className="font-semibold">Presentar respuesta</h3><Campo label="Respuesta o explicación de por qué no corresponde"><textarea required maxLength={3000} name="mensaje" className={control} /></Campo><Campo label="Documento PDF (opcional)"><input type="file" name="pdf" accept="application/pdf" className={control} /></Campo><p className="text-xs text-slate-600">La entrega quedará pendiente de revisión. El legajo se actualizará después de la aprobación.</p><button disabled={busy} className={button}>Enviar a revisión</button></form>}
    {admin && abierto && <form onSubmit={e => enviar(e, 'gestionar')} className="mt-4 space-y-3 border-t pt-4"><h3 className="font-semibold">Gestión administrativa</h3><Campo label="Acción"><select name="accion" value={accion} onChange={e => elegirAccion(e.target.value)} className={control}><option value="observar" disabled={record.estado !== 'en_revision' || propio}>Observar entrega</option><option value="aceptar" disabled={record.estado !== 'en_revision' || propio}>Resolver requerimiento</option><option value="recordar">Enviar recordatorio interno</option><option value="prorrogar">Ampliar plazo</option><option value="cancelar">Cancelar requerimiento</option></select></Campo>
      <Campo label="Comentario y motivo de la decisión"><textarea required name="mensaje" maxLength={3000} className={control} /></Campo>
      {accion === 'prorrogar' && <Campo label="Nueva fecha límite"><input required type="date" name="prorroga_hasta" className={control} /></Campo>}
      {accion === 'aceptar' && <><p className="text-sm text-slate-600">Revisa la entrega e incorpora el documento o corrige los datos desde la sección correspondiente del legajo. Después registra aquí la resolución.</p><Link to={`/legajos/${record.usuario_id}`} className="inline-block text-sm font-semibold text-teal-800 underline">Abrir legajo para actualizarlo</Link>{record.tipo_documento_id && <Campo label="Documento incorporado y aceptado"><select required name="documento_id" className={control}><option value="">Selecciona el documento del legajo</option>{documentos.map(d => <option key={d.id} value={d.id}>{d.nombre || d.num_resolucion || d.id} · {fecha(d.fecha_incorporacion)}</option>)}</select><button type="button" className={`${button} mt-2`} onClick={() => elegirAccion('aceptar')}>Actualizar documentos disponibles</button></Campo>}</>}
      <button disabled={busy} className={button}>Registrar acción</button></form>}
    </section>
  </DocumentoModal>
}

export default function RequirementsPage() {
  const admin = usePuede('sistema.administrar')
  const { usuario } = useSesion()
  const [params, setParams] = useSearchParams()
  const clase = params.get('clase') || 'documentos'
  const [nuevo, setNuevo] = useState(false)
  const [seleccion, setSeleccion] = useState(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [sync, setSync] = useState({ loading: admin, error: '', creados: null })
  useEffect(() => {
    if (!admin) return
    let active = true
    api.post('/solicitudes-legajo/sincronizar').then(({ data }) => {
      if (active) setSync({ loading: false, error: '', ...data })
    }).catch(e => {
      if (active) setSync({ loading: false, error: apiErrorMessage(e), creados: null })
    })
    return () => { active = false }
  }, [admin])
  async function actualizarPendientes() {
    setSync({ loading: true, error: '', creados: null })
    try {
      const { data } = await api.post('/solicitudes-legajo/sincronizar')
      setSync({ loading: false, error: '', ...data })
    } catch (e) { setSync({ loading: false, error: apiErrorMessage(e), creados: null }) }
  }
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
  async function crear(e) {
    e.preventDefault(); setBusy(true); setError('')
    try {
      const data = Object.fromEntries(new FormData(e.currentTarget)); data.clase = admin ? (clase === 'actualizacion' ? 'actualizacion' : 'documentos') : 'actualizacion'
      if (!data.tipo_documento_id) delete data.tipo_documento_id
      await api.post('/solicitudes-legajo', data); setNuevo(false); filtrar('clase', data.clase)
    } catch (e) { setError(e.response?.data?.message || apiErrorMessage(e)) } finally { setBusy(false) }
  }
  return <>
    <header className="mb-6"><p className="text-xs font-semibold uppercase tracking-widest text-teal-700">Seguimiento documental</p><h1 className="mt-2 text-3xl font-bold text-slate-800">Requerimientos</h1><p className="mt-3 text-sm text-slate-600">Revisa los documentos pendientes detectados automáticamente, presenta actualizaciones y consulta sus entregas.</p><p className="mt-2 text-xs text-slate-500">Los avisos y recordatorios se consultan dentro del sistema.</p></header>
    {admin && <section className={`${panel} mb-5 flex flex-wrap items-center justify-between gap-3`} aria-label="Detección automática"><div><h2 className="font-semibold">Detección automática de pendientes</h2><p className="mt-1 text-sm text-slate-600">Se generan requerimientos por documentos faltantes, vencidos u observados. Los requisitos por determinar se revisan al completar el perfil.</p>{sync.loading && <p role="status" className="mt-2 text-sm text-teal-800">Revisando legajos…</p>}{sync.creados !== null && !sync.loading && <p role="status" className="mt-2 text-sm text-teal-800">{sync.creados ? `${sync.creados} requerimiento(s) nuevo(s).` : 'Revisión completada, sin requerimientos nuevos.'} Plazo inicial: {sync.plazo_dias} días calendario; puedes ampliarlo desde el detalle.</p>}{sync.error && <p role="alert" className="mt-2 text-sm text-rose-800">{sync.error}</p>}</div><button className={button} disabled={sync.loading} onClick={actualizarPendientes}>Actualizar pendientes</button></section>}
    {!!request.data?.avisos?.length && <section className={`${panel} mb-5`} aria-label="Mis avisos"><h2 className="font-bold">Mis avisos recientes</h2><ul className="mt-3 space-y-2">{request.data.avisos.map(aviso => <li key={aviso.id} className="rounded-xl bg-teal-50 p-3 text-sm"><p className="font-semibold">{aviso.titulo} · {fecha(aviso.created_at)}</p><p>{aviso.mensaje}</p></li>)}</ul></section>}
    <nav className="mb-5 flex flex-wrap gap-2" aria-label="Tipo de requerimientos">{[['documentos', 'Documentos pendientes'], ['actualizacion', 'Solicitudes de actualización'], ...(admin ? [['sugerencias', 'Completar perfil']] : [])].map(([key, label]) => <button key={key} className={`${button} ${clase === key ? 'bg-teal-100' : 'bg-white'}`} aria-pressed={clase === key} onClick={() => { const next = new URLSearchParams(); next.set('clase', key); setParams(next); setNuevo(false); setSeleccion(null) }}>{label}</button>)}</nav>
    <section className={`${panel} mb-5`}><form className="flex flex-wrap items-end gap-3" onSubmit={e => { e.preventDefault(); filtrar('q', new FormData(e.currentTarget).get('q')) }}><div className="min-w-60 flex-1"><Campo label="Buscar por persona o descripción"><input name="q" value={params.get('q') || ''} onChange={e => filtrar('q', e.target.value)} className={control} maxLength={120} /></Campo></div>{clase !== 'sugerencias' && <Campo label="Estado"><select className={control} value={params.get('estado') || ''} onChange={e => filtrar('estado', e.target.value)}><option value="">Todos</option>{Object.entries(estados).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></Campo>}<button className={button}>Buscar</button><button type="button" className={button} onClick={() => { setError(''); setNuevo(true) }}>{admin ? 'Requerimiento excepcional' : 'Solicitar actualización'}</button></form></section>
    {nuevo && <DocumentoModal titulo={admin ? 'Requerimiento excepcional' : 'Solicitar actualización de mi legajo'} busy={busy} onCancel={() => setNuevo(false)} compacto><form onSubmit={crear} className="space-y-4"><Campo label="Persona"><select name="usuario_id" required className={control} defaultValue={!admin ? usuario?.id : ''}><option value="">Selecciona una persona con legajo activo</option>{opciones.data?.personas.map(p => <option key={p.id} value={p.id}>{nombre(p)}</option>)}</select></Campo><Campo label="Tipo de documento (opcional para corrección de datos)"><select name="tipo_documento_id" className={control} defaultValue=""><option value="">Corrección de datos / otro</option>{opciones.data?.tipos.map(t => <option key={t.id} value={t.id}>{t.nombre}</option>)}</select></Campo><Campo label="Detalle de lo solicitado"><textarea required name="descripcion" maxLength={300} className={control} /></Campo><Campo label="Fecha límite propuesta"><input required name="fecha_limite" type="date" className={control} /></Campo><p className="text-xs text-slate-600">Podrás adjuntar un PDF de hasta {opciones.data?.maximo_pdf_kb || '—'} KB al presentar la respuesta.</p>{error && <p role="alert" className="text-sm text-rose-800">{error}</p>}<button disabled={busy || !opciones.data} className={button}>Crear requerimiento</button><button type="button" className={`${button} ml-2`} disabled={busy} onClick={() => setNuevo(false)}>Cancelar</button></form></DocumentoModal>}
    {(request.error || opciones.error || (clase === 'sugerencias' && sugerencias.error)) && <p role="alert" className="mb-4 text-rose-800">No se pudo cargar la información. <button onClick={() => { request.retry(); opciones.retry(); sugerencias.retry() }} className="underline">Reintentar</button></p>}
    {!result && <p role="status" className={panel}>Consultando requerimientos…</p>}
    {result && <section className={panel}><div className="mb-4 flex justify-between"><h2 className="font-bold">{clase === 'sugerencias' ? 'Perfil por completar' : 'Requerimientos'}</h2><span className="text-sm text-slate-600">{result.total ?? result.meta?.total ?? 0} registros</span></div>
      {clase === 'sugerencias' && <p className="mb-4 text-sm text-slate-600">Estos requisitos todavía no generan requerimientos. Completa la información del perfil para determinar si corresponde pedir el documento.</p>}
      <div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-slate-50"><tr>{['Persona', 'Detalle', 'Plazo / estado', 'Acción'].map(t => <th key={t} className="p-3">{t}</th>)}</tr></thead><tbody>{result.data?.map(r => <tr key={r.id} className="border-t border-slate-200 align-top"><td className="p-3">{clase === 'sugerencias' ? (r.persona || r.nombre) : nombre(r.usuario)}</td><td className="max-w-lg p-3"><p>{r.descripcion || r.detalle}</p>{r.origen === 'automatico' && <span className="mt-2 inline-block rounded-full bg-sky-50 px-2 py-1 text-xs text-sky-800">Automático</span>}{r.tipo_documento && <p className="mt-1 text-xs text-slate-500">{r.tipo_documento.nombre}</p>}</td><td className="p-3">{clase === 'sugerencias' ? r.estado : <><p>{fecha(r.prorroga_hasta || r.fecha_limite)}</p><span className={`mt-1 inline-block rounded-lg px-2 py-1 text-xs ${r.atrasado || r.estado === 'observado' ? 'bg-rose-100 text-rose-800' : 'bg-teal-50 text-teal-800'}`}>{estados[r.estado] || r.estado}{r.atrasado ? ' · Fuera de plazo' : ''}</span></>}</td><td className="p-3">{clase === 'sugerencias' ? <><Link to={`/legajos/${r.usuario_id}`} className="mt-2 block text-teal-800 underline">Revisar legajo</Link></> : <button className={button} onClick={() => setSeleccion(r.id)}>Ver y gestionar</button>}</td></tr>)}</tbody></table></div>
      {!result.data?.length && <p className="p-8 text-center text-sm text-slate-600">No hay registros para estos filtros.</p>}
      <div className="mt-5 flex items-center justify-between text-sm"><button className={button} disabled={(result.current_page ?? result.meta?.current_page ?? 1) <= 1} onClick={() => filtrar('page', Number(params.get('page') || 1) - 1)}>Anterior</button><span>Página {result.current_page ?? result.meta?.current_page ?? 1} de {result.last_page ?? result.meta?.last_page ?? 1}</span><button className={button} disabled={(result.current_page ?? result.meta?.current_page ?? 1) >= (result.last_page ?? result.meta?.last_page ?? 1)} onClick={() => filtrar('page', Number(params.get('page') || 1) + 1)}>Siguiente</button></div>
    </section>}
    {selected && <Detalle key={`${selected.id}-${selected.estado}`} record={selected} admin={admin} propio={selected.usuario_id === usuario?.id} onClose={() => setSeleccion(null)} />}
  </>
}
