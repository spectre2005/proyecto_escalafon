import Modal from '../../components/ui/Modal'
import Icon from '../../components/ui/Icon'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import api, { apiErrorMessage } from '../../axios'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'
import { usePuede, useSesion } from '../auth/session'
import { regimenesPorTipo } from '../users/utils/regimenesLaborales'

const control = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:ring-2 focus:ring-teal-600'
const button = 'rounded-xl border border-slate-200 px-4 py-2.5 text-sm font-medium text-slate-700 disabled:opacity-40'
const primary = 'rounded-xl bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-40'
const card = 'rounded-2xl border border-slate-200 bg-white p-5'
const estados = { registrado: 'Registrado', en_revision: 'En revisión', observado: 'Observado', admitido: 'Aceptado · por activar', incorporado: 'Incorporado', rechazado: 'Rechazado' }
const nombre = u => `${u.nombres} ${u.apellido_paterno} ${u.apellido_materno || ''}`.trim()
const errorText = e => e.response?.data?.errors ? apiErrorMessage(e) : e.response?.data?.message || apiErrorMessage(e)

function Campo({ label, children }) { return <label className="block text-xs font-medium text-slate-600"><span className="mb-2 block">{label}</span>{children}</label> }

function Registro({ onCreated, onClose }) {
  const [mode, setMode] = useState('existente')
  const [q, setQ] = useState('')
  const people = useLiveQuery(mode === 'existente' && q.trim().length >= 3 ? `/expedientes?q=${encodeURIComponent(q.trim())}` : null)
  const [draft, setDraft] = useState({ tipo_personal: 'administrativo', regimen_laboral: 'CAS', usuario_id: '', cargo: '', nombres: '', apellido_paterno: '', apellido_materno: '', numero_doc_identidad: '', email_institucional: '' })
  const [docs, setDocs] = useState('Documento nacional de identidad\nDeclaración jurada\nTítulo profesional')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const set = key => e => setDraft({ ...draft, [key]: e.target.value })
  async function submit(e) {
    e.preventDefault(); setBusy(true); setError('')
    try {
      const payload = mode === 'existente' ? { usuario_id: draft.usuario_id, cargo: draft.cargo } : Object.fromEntries(Object.entries(draft).filter(([key]) => key !== 'usuario_id'))
      const { data } = await api.post('/incorporaciones', { ...payload, documentos_exigidos: docs.split('\n').map(s => s.trim()).filter(Boolean) })
      onCreated(data.data.id)
    } catch (e) { setError(errorText(e)) } finally { setBusy(false) }
  }
  return <Modal titulo="Registrar postulante" onClose={onClose} busy={busy}><form aria-busy={busy} onSubmit={submit} className={card}><h2 className="text-lg font-semibold">Registrar postulante</h2><fieldset disabled={busy} className="mt-4 space-y-4">
    <Campo label="Origen del registro"><select className={control} value={mode} onChange={e => setMode(e.target.value)}><option value="existente">Persona ya registrada (conserva su legajo)</option><option value="nuevo">Nuevo personal</option></select></Campo>
    {mode === 'existente' ? <><Campo label="Buscar por nombre o DNI (mínimo 3 caracteres)"><input className={control} value={q} onChange={e => { setQ(e.target.value); setDraft({ ...draft, usuario_id: '' }) }} /></Campo><Campo label="Persona"><select className={control} required value={draft.usuario_id} onChange={set('usuario_id')}><option value="">Seleccionar persona</option>{people.data?.data.map(u => <option key={u.id} value={u.id}>{nombre(u)} · {u.numero_doc_identidad}</option>)}</select></Campo>{people.error && <p role="alert">No se pudo buscar personal.</p>}</> : <div className="grid gap-4 sm:grid-cols-2">{[['numero_doc_identidad', 'DNI', 8], ['nombres', 'Nombres', 120], ['apellido_paterno', 'Apellido paterno', 80], ['apellido_materno', 'Apellido materno', 80], ['email_institucional', 'Correo institucional', 255]].map(([key, label, max]) => <Campo key={key} label={label}><input className={control} required={key !== 'apellido_materno'} maxLength={max} type={key === 'email_institucional' ? 'email' : 'text'} pattern={key === 'numero_doc_identidad' ? '[0-9]{8}' : undefined} value={draft[key]} onChange={set(key)} /></Campo>)}<Campo label="Tipo de personal"><select className={control} value={draft.tipo_personal} onChange={e => setDraft({ ...draft, tipo_personal: e.target.value, regimen_laboral: regimenesPorTipo[e.target.value][0].value })}><option value="administrativo">Administrativo</option><option value="docente">Docente</option></select></Campo><Campo label="Régimen"><select className={control} value={draft.regimen_laboral} onChange={set('regimen_laboral')}>{regimenesPorTipo[draft.tipo_personal].map(r => <option key={r.value} value={r.value}>{r.label}</option>)}</select></Campo></div>}
    <Campo label="Cargo"><input className={control} required maxLength={150} value={draft.cargo} onChange={set('cargo')} /></Campo>
    <Campo label="Documentos exigidos (uno por línea)"><textarea className={control} required rows={4} value={docs} onChange={e => setDocs(e.target.value)} /></Campo><p className="text-xs text-slate-500">Ajusta esta lista al puesto antes de registrar. Cada documento necesitará un PDF y la revisión de otra persona.</p>
    <div className="flex gap-3"><button className={primary}>{busy ? 'Registrando…' : 'Registrar postulante'}</button><button type="button" className={button} onClick={onClose}>Cancelar</button></div>
  </fieldset>{error && <p role="alert" className="mt-4 text-sm text-rose-700">{error}</p>}</form></Modal>
}

function HabilitarAsistente({ onClose }) {
  const [q, setQ] = useState('')
  const [usuario, setUsuario] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const people = useLiveQuery(q.trim().length >= 3 ? `/expedientes?legajo=con&q=${encodeURIComponent(q.trim())}` : null)
  async function submit(e) {
    e.preventDefault(); setBusy(true); setError(''); setNotice('')
    try {
      const { data } = await api.post('/incorporaciones/responsables', { usuario_id: usuario, ...(password ? { password } : {}) })
      setPassword(''); setNotice(data.message)
    } catch (e) { setError(errorText(e)) } finally { setBusy(false) }
  }
  return <Modal titulo="Habilitar asistente de legajo" onClose={onClose} busy={busy}><form aria-busy={busy} onSubmit={submit} className={card}><h2 className="text-lg font-semibold">Habilitar asistente de legajo</h2><p className="mt-2 text-sm text-slate-500">Selecciona personal con legajo activo existente. El acceso permite consultar y cargar datos según sus permisos y trabajar en incorporaciones asignadas. La admisión y revisión quedan a cargo de administración.</p><fieldset disabled={busy} className="mt-4 grid gap-4 sm:grid-cols-2"><Campo label="Buscar personal (nombre o DNI)"><input className={control} value={q} onChange={e => { setQ(e.target.value); setUsuario('') }} /></Campo><Campo label="Persona que recibirá el acceso"><select required className={control} value={usuario} onChange={e => setUsuario(e.target.value)}><option value="">Seleccionar persona</option>{people.data?.data.map(u => <option key={u.id} value={u.id}>{nombre(u)} · {u.numero_doc_identidad}</option>)}</select></Campo><Campo label="Contraseña inicial (para cuentas inactivas o sin acceso)"><input type="password" autoComplete="new-password" minLength={8} className={control} value={password} onChange={e => setPassword(e.target.value)} /></Campo><p className="self-center text-xs text-slate-500">Entrega la contraseña por el canal institucional. Si la persona ya tiene acceso a su cuenta, conserva su contraseña actual.</p><div className="flex gap-3 sm:col-span-2"><button className={primary}>Habilitar asistente</button><button type="button" className={button} onClick={onClose}>Cerrar</button></div></fieldset>{people.error && <p role="alert" className="mt-3 text-rose-700">No se pudo consultar el personal.</p>}{error && <p role="alert" className="mt-3 text-rose-700">{error}</p>}{notice && <p role="status" className="mt-3 text-teal-700">{notice}</p>}</form></Modal>
}

function Pdf({ id, documento, onClose }) {
  const [url, setUrl] = useState('')
  const [error, setError] = useState('')
  useEffect(() => {
    let active = true, objectUrl
    api.get(`/incorporaciones/${id}/documentos/${documento.id}`, { responseType: 'blob' }).then(r => { if (active) { objectUrl = URL.createObjectURL(r.data); setUrl(objectUrl) } }).catch(() => { if (active) setError('No se pudo abrir el PDF.') })
    return () => { active = false; if (objectUrl) URL.revokeObjectURL(objectUrl) }
  }, [id, documento.id])
  return <Modal titulo={documento.nombre} onClose={onClose}><section className={card}><div className="mb-3 flex justify-between gap-4"><h3>{documento.nombre}</h3><button className={button} onClick={onClose}>Cerrar PDF</button></div>{error ? <p role="alert">{error}</p> : url ? <iframe title={documento.nombre} src={url} className="h-[65vh] w-full" /> : <p role="status">Cargando PDF…</p>}</section></Modal>
}

function Detalle({ id, admin, onClose }) {
  const query = useLiveQuery(`/incorporaciones/${id}`)
  const options = useLiveQuery(admin ? '/incorporaciones/opciones' : null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [motivo, setMotivo] = useState('')
  const [responsable, setResponsable] = useState('')
  const [tareas, setTareas] = useState('Cargar documentos\nCompletar datos de resolución')
  const [activation, setActivation] = useState(false)
  const [rol, setRol] = useState('')
  const [password, setPassword] = useState('')
  const [datos, setDatos] = useState(null)
  const [pdf, setPdf] = useState(null)
  const [adjuntar, setAdjuntar] = useState(null)
  const [archivo, setArchivo] = useState(null)
  const [errorArchivo, setErrorArchivo] = useState('')
  const { usuario } = useSesion()
  const i = query.data?.data
  const cerrado = i && ['incorporado', 'rechazado'].includes(i.estado)
  async function action(accion, extra = {}) {
    if (busy) return
    setBusy(true); setError(''); setNotice('')
    try {
      const { data } = await api.patch(`/incorporaciones/${id}`, { accion, version: i.version, ...extra })
      query.setData(data)
      setDatos(null); setPassword(''); setActivation(false)
      setNotice(accion === 'firma' ? 'Solicitud de firma registrada para seguimiento interno. Coordina la firma con el postulante.' : 'Cambios guardados.')
    } catch (e) { setError(errorText(e)) } finally { setBusy(false) }
  }
  async function upload(doc, file) {
    if (!file || busy) return
    setBusy(true); setError(''); setNotice('')
    const form = new FormData(); form.append('pdf', file); form.append('version', i.version)
    try { const { data } = await api.post(`/incorporaciones/${id}/documentos/${doc.id}`, form); query.setData(data); setNotice('PDF guardado; pendiente de revisión.'); setAdjuntar(null); setArchivo(null) }
    catch (e) { setError(errorText(e)) } finally { setBusy(false) }
  }
  if (!i) return <Modal titulo="Detalle de incorporación" onClose={onClose}><section className={card}>{query.error ? <p role="alert">No se pudo abrir el expediente. <button onClick={query.retry}>Reintentar</button></p> : <p>Cargando postulante…</p>}<button className={button} onClick={onClose}>Cerrar</button></section></Modal>
  const values = datos || { numero_resolucion: i.numero_resolucion || '', fecha_resolucion: i.fecha_resolucion || '', fecha_inicio: i.fecha_inicio || '' }
  return <Modal titulo="Detalle de incorporación" descripcion="Consulta el avance, completa los datos y revisa la documentación de la persona." onClose={onClose} busy={busy}><section aria-label="Detalle del postulante" className="min-w-0">
    <div className="flex flex-wrap items-center justify-between gap-4 border-b border-slate-100 pb-5"><div className="flex items-center gap-3"><span className="grid size-12 place-items-center rounded-full bg-teal-50 font-bold text-teal-700" aria-hidden="true">{i.usuario.nombres[0]}{i.usuario.apellido_paterno[0]}</span><div><h2 className="text-xl font-semibold text-slate-800">{nombre(i.usuario)}</h2><p className="mt-1 text-xs font-semibold uppercase text-teal-700">{estados[i.estado]}</p></div></div><div className="flex gap-2">{admin && !cerrado && <button disabled={busy || !['en_revision', 'admitido'].includes(i.estado)} className={button} onClick={() => action('firma')}>Solicitar firma</button>}</div></div>
    {error && <p role="alert" className="my-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-700">{error}</p>}{query.error && <p role="alert" className="my-3 text-rose-700">No se pudo actualizar el expediente. <button onClick={query.retry}>Reintentar</button></p>}{notice && <p role="status" className="my-4 rounded-xl bg-teal-50 p-4 text-sm text-teal-800">{notice}</p>}
    <dl aria-label="Resumen del expediente" className="mt-5 grid gap-3 sm:grid-cols-3">{[['Documentos revisados', `${i.revisados} de ${i.documentos.length}`], ['Documentos con archivo', `${i.documentos.filter(doc => doc.versiones.length > 0).length} de ${i.documentos.length}`], ['Tareas completadas', `${i.tareas.filter(t => t.completada).length} de ${i.tareas.length}`]].map(([label, value]) => <div key={label} className="rounded-xl border border-slate-200 bg-white p-4"><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-2 text-xl font-semibold text-teal-800">{value}</dd></div>)}</dl>
    <fieldset disabled={busy || !!query.error} className="mt-5 grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_310px] disabled:opacity-70"><div className="space-y-5">
      <section className={card}><h3 className="mb-4 font-semibold text-slate-800">Información personal y laboral</h3><dl className="grid gap-4 text-sm sm:grid-cols-2">{[['Cargo', i.cargo], ['Régimen', i.usuario.regimen_laboral], ['Tipo de personal', i.usuario.tipo_personal], ['DNI', i.usuario.numero_doc_identidad], ['Responsable delegado', i.responsable ? nombre(i.responsable) : 'Sin asignar'], ['Solicitud de firma', i.firma_solicitada_at ? new Date(i.firma_solicitada_at).toLocaleString('es-PE') : 'No solicitada']].map(([label, value]) => <div key={label}><dt className="text-xs text-slate-500">{label}</dt><dd className="mt-1 font-medium text-slate-800">{value}</dd></div>)}</dl></section>
      <section className={card}><h3 className="mb-4 font-semibold text-slate-800">Resolución y vínculo laboral</h3><form onSubmit={e => { e.preventDefault(); action('datos', values) }} className="grid gap-3 sm:grid-cols-3">{[['numero_resolucion', 'N.º resolución', 'text'], ['fecha_resolucion', 'Fecha de resolución', 'date'], ['fecha_inicio', 'Inicio del vínculo', 'date']].map(([key, label, type]) => <Campo key={key} label={label}><input className={control} type={type} maxLength={60} disabled={cerrado || i.estado === 'admitido'} value={values[key]} onChange={e => setDatos({ ...values, version: datos?.version || i.version, [key]: e.target.value })} /></Campo>)}{!cerrado && i.estado !== 'admitido' && <button className={`${button} sm:col-span-3`} disabled={!datos}>Guardar datos</button>}</form></section>
      <div className={card}><h3 className="font-semibold text-slate-800">Documentos exigidos <span className="ml-2 text-xs text-teal-700">{i.revisados} de {i.documentos.length}</span></h3><p className="mt-2 text-xs text-slate-500">PDF de hasta 20 MB. La revisión debe realizarla una persona distinta de quien cargó el archivo.</p><div className="mt-4 divide-y divide-slate-100">{i.documentos.map(doc => <div key={doc.id} className="py-4"><div className="flex flex-wrap justify-between gap-2"><h4 className="text-sm font-medium">{doc.nombre}</h4><span className={`rounded-full px-3 py-1 text-xs ${doc.estado === 'revisado' ? 'bg-teal-50 text-teal-700' : doc.estado === 'rechazado' ? 'bg-rose-50 text-rose-700' : 'bg-slate-100 text-slate-600'}`}>{doc.estado}</span></div>{doc.revisado_nombre && <p className="mt-1 text-xs text-slate-500">Revisado por {doc.revisado_nombre}</p>}{doc.motivo && <p className="mt-1 text-xs text-rose-700">{doc.motivo}</p>}<div className="mt-3 flex flex-wrap items-center gap-2">{doc.versiones.length > 0 && <button type="button" className={button} onClick={() => setPdf(doc)}><span className="inline-flex items-center gap-2"><Icon name="eye" size={18} />Ver documento</span></button>}{!cerrado && i.estado !== 'admitido' && doc.estado !== 'revisado' && <button type="button" className={`${button} inline-flex items-center gap-2`} onClick={() => { setAdjuntar(doc); setArchivo(null); setErrorArchivo('') }}><Icon name="paperclip" size={18} />{doc.versiones.length ? 'Subsanar documento' : 'Adjuntar documento'}</button>}{admin && i.estado === 'en_revision' && doc.versiones.length > 0 && doc.versiones.at(-1).subido_por !== usuario.id && <><button type="button" className={primary} onClick={() => action('revisar_documento', { documento_id: doc.id, estado_documento: 'revisado' })}>Revisado</button><button type="button" disabled={!motivo.trim()} className={`${button} text-rose-700`} onClick={() => action('revisar_documento', { documento_id: doc.id, estado_documento: 'rechazado', motivo })}>Rechazado</button></>}</div></div>)}</div></div>
      {pdf && <Pdf key={pdf.id} id={id} documento={pdf} onClose={() => setPdf(null)} />}
      <section className={card}><h3 className="font-semibold">Tareas delegadas</h3>{i.tareas.length ? <ul className="mt-3 space-y-3">{i.tareas.map(t => <li key={t.id}><label className="flex items-center gap-3 text-sm"><input type="checkbox" disabled={cerrado} checked={t.completada} onChange={e => action('tarea', { tarea_id: t.id, completada: e.target.checked })} className="accent-teal-700" />{t.titulo}</label></li>)}</ul> : <p className="mt-2 text-xs text-slate-500">Sin tareas asignadas.</p>}{admin && !cerrado && <form className="mt-4 space-y-3" onSubmit={e => { e.preventDefault(); action('delegar', { responsable_id: responsable, tareas: tareas.split('\n').map(s => s.trim()).filter(Boolean) }) }}><Campo label="Responsable"><select required className={control} value={responsable} onChange={e => setResponsable(e.target.value)}><option value="">Seleccionar usuario activo</option>{options.data?.data.responsables.map(u => <option key={u.id} value={u.id}>{nombre(u)}</option>)}</select></Campo><Campo label="Tareas (una por línea)"><textarea required rows={3} className={control} value={tareas} onChange={e => setTareas(e.target.value)} /></Campo><button className={button}>Asignar tareas</button><p className="text-xs text-slate-500">Una nueva asignación reemplaza la lista actual.</p></form>}</section>
      <details className={card}><summary className="cursor-pointer text-sm font-medium">Historial de seguimiento ({i.historial.length})</summary><ol className="mt-3 space-y-2 text-xs text-slate-500">{i.historial.map((h, index) => <li key={index}>{new Date(h.fecha).toLocaleString('es-PE')} · {h.accion.replaceAll('_', ' ')}{h.motivo ? `: ${h.motivo}` : ''}</li>)}</ol></details>
    </div><aside className="space-y-4 rounded-xl bg-slate-50 p-4"><h3 className="font-semibold text-slate-800">Gestión de la incorporación</h3><p className="text-xs leading-relaxed text-slate-500">Avanza según el estado del expediente. Para admitir, todos los documentos deben estar revisados.</p><h4 className="text-sm font-semibold text-slate-700">Al confirmar la incorporación</h4><ol className="list-decimal space-y-3 pl-5 text-sm text-slate-600"><li>Activa la cuenta del personal.</li><li>Registra o reutiliza el vínculo laboral.</li><li>Asigna el rol seleccionado.</li><li>Abre el legajo existente o crea uno si falta.</li></ol>
      {!cerrado && <div className="space-y-3 border-t border-slate-200 pt-4">{['registrado', 'observado'].includes(i.estado) && <button className={`${button} w-full`} onClick={() => action('revision')}>Enviar a revisión</button>}{admin && <>{i.estado === 'en_revision' && <button className={`${primary} w-full`} disabled={i.revisados !== i.documentos.length} onClick={() => action('admitir')}>Marcar como Admitido</button>}{i.estado === 'admitido' && <button className={`${primary} w-full`} onClick={() => setActivation(!activation)}>Aceptar e incorporar</button>}{activation && <form className="space-y-3" onSubmit={e => { e.preventDefault(); action('incorporar', { rol_id: rol, ...(!i.usuario.activo ? { password } : {}) }) }}><Campo label="Rol de la cuenta"><select required className={control} value={rol} onChange={e => setRol(e.target.value)}><option value="">Seleccionar rol</option>{options.data?.data.roles.map(r => <option key={r.id} value={r.id}>{r.nombre}</option>)}</select></Campo>{!i.usuario.activo && <Campo label="Contraseña de activación"><input required type="password" autoComplete="new-password" minLength={8} className={control} value={password} onChange={e => setPassword(e.target.value)} /></Campo>}<p className="text-xs text-slate-500">La cuenta podrá iniciar sesión con su correo institucional. Entrega la contraseña por el canal institucional.</p><button className={`${primary} w-full`}>Confirmar incorporación</button></form>}<Campo label="Motivo de observación o rechazo"><textarea maxLength={1000} rows={3} className={control} value={motivo} onChange={e => setMotivo(e.target.value)} /></Campo><button className={`${button} w-full`} disabled={!motivo.trim()} onClick={() => action('observar', { motivo })}>Observar</button><button className={`${button} w-full border-rose-200 text-rose-700`} disabled={!motivo.trim()} onClick={() => action('rechazar', { motivo })}>Rechazar postulante</button></>}</div>}
      <Link className="block text-sm font-semibold text-teal-700 underline" to={`/legajos/${i.usuario_id}`}>Abrir ficha del legajo</Link>
    </aside></fieldset>
    {adjuntar && <Modal titulo={`Adjuntar documento: ${adjuntar.nombre}`} onClose={() => setAdjuntar(null)} busy={busy}><form aria-busy={busy} onSubmit={event => { event.preventDefault(); if (archivo) upload(adjuntar, archivo) }} className={`${card} space-y-4`}>
      <p className="text-sm text-slate-600">Selecciona el PDF correspondiente. Al guardarlo quedará pendiente de revisión por otra persona.</p>
      <Campo label="Archivo PDF (máximo 20 MB)"><input type="file" required accept="application/pdf,.pdf" className={control} disabled={busy} onChange={event => { const file = event.target.files?.[0]; const invalid = file && ((!file.name.toLowerCase().endsWith('.pdf') || (file.type && file.type !== 'application/pdf')) || file.size > 20 * 1024 * 1024 || file.size === 0); setArchivo(invalid ? null : file); setErrorArchivo(invalid ? 'Selecciona un PDF válido, no vacío y de hasta 20 MB.' : ''); if (invalid) event.target.value = '' }} /></Campo>
      {archivo && <p className="text-sm text-slate-600">{archivo.name} · {(archivo.size / 1024 / 1024).toFixed(2)} MB</p>}
      {errorArchivo && <p role="alert" className="text-sm text-rose-700">{errorArchivo}</p>}
      {error && <p role="alert" className="text-sm text-rose-700">{error}</p>}
      <div className="flex flex-wrap justify-end gap-3"><button type="button" className={button} disabled={busy} onClick={() => setAdjuntar(null)}>Cancelar</button><button className={primary} disabled={busy || !archivo}>{busy ? 'Guardando…' : 'Guardar documento'}</button></div>
    </form></Modal>}
  </section></Modal>
}

export default function IncorporacionPage() {
  const admin = usePuede('sistema.administrar')
  const canRead = usePuede('legajo.editar')
  const [params, setParams] = useState({ q: '', tipo: '', cargo: '', archivo: '0', page: '1' })
  const [selected, setSelected] = useState(null)
  const [register, setRegister] = useState(false)
  const [enableAssistant, setEnableAssistant] = useState(false)
  const query = useLiveQuery(canRead ? `/incorporaciones?${new URLSearchParams(params)}` : null)
  const columns = params.archivo === '1' ? ['incorporado', 'rechazado'] : ['registrado', 'en_revision', 'observado', 'admitido']
  if (!canRead) return <p role="alert">No tienes permiso para consultar incorporaciones.</p>
  return <div><div className="mb-6 flex flex-wrap items-center justify-between gap-4"><div><p className="text-xs font-semibold uppercase tracking-widest text-teal-700">Ingreso del personal</p><h1 className="mt-2 text-3xl font-bold text-slate-800">Incorporación</h1><p className="mt-3 text-sm text-slate-500">Registro, revisión documental y admisión del personal.{!admin && ' Se muestran únicamente tus expedientes asignados.'}</p></div>{admin && <div className="flex flex-wrap gap-3"><button className={button} onClick={() => setEnableAssistant(!enableAssistant)}>Habilitar asistente</button><button className={primary} onClick={() => setRegister(!register)}>+ Registrar postulante</button></div>}</div>
    {enableAssistant && <HabilitarAsistente onClose={() => setEnableAssistant(false)} />}
    {register && <Registro onClose={() => setRegister(false)} onCreated={id => { setRegister(false); setSelected(id); setParams({ ...params, archivo: '0', page: '1' }); query.retry() }} />}
    <div className={`${card} mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4`}><Campo label="Buscar por nombre o DNI"><input className={control} value={params.q} onChange={e => setParams({ ...params, q: e.target.value, page: '1' })} /></Campo><Campo label="Tipo de personal"><select className={control} value={params.tipo} onChange={e => setParams({ ...params, tipo: e.target.value, page: '1' })}><option value="">Todo el personal</option><option value="docente">Docente</option><option value="administrativo">Administrativo</option></select></Campo><Campo label="Cargo"><input className={control} value={params.cargo} onChange={e => setParams({ ...params, cargo: e.target.value, page: '1' })} /></Campo><Campo label="Vista"><select className={control} value={params.archivo} onChange={e => setParams({ ...params, archivo: e.target.value, page: '1' })}><option value="0">En proceso</option><option value="1">Incorporados y rechazados</option></select></Campo></div>
    {query.error && <p role="alert" className="mb-4 text-rose-700">No se pudo actualizar el tablero. <button className="underline" onClick={query.retry}>Reintentar</button></p>}{query.loading && <p role="status" className="mb-4">Cargando postulantes…</p>}
    <div className="grid items-start gap-4 md:grid-cols-2 xl:grid-cols-4">{columns.map(estado => { const items = query.data?.data.filter(i => i.estado === estado) || []; return <section key={estado} className="rounded-2xl border border-slate-200 bg-slate-50 p-3"><h2 className="mb-4 flex items-center justify-between gap-2 px-1 text-sm font-semibold text-slate-700">{estados[estado]}<span className="rounded-full bg-white px-2 py-1 text-xs">{items.length}</span></h2><div className="space-y-3">{items.map(i => <button key={i.id} onClick={() => setSelected(i.id)} aria-pressed={selected === i.id} className={`w-full rounded-xl border bg-white p-4 text-left hover:border-teal-400 ${selected === i.id ? 'border-teal-600 ring-1 ring-teal-600' : 'border-slate-200'}`}><h3 className="text-sm font-semibold text-slate-800">{nombre(i.usuario)}</h3><p className="mt-2 text-xs text-slate-500">{i.cargo} · {i.usuario.tipo_personal}</p><div className="mt-4 flex items-center justify-between"><span className="rounded-lg bg-teal-50 px-2 py-1 text-xs font-medium text-teal-700">{i.revisados} de {i.documentos.length}</span><span className="text-xs text-slate-400">Ver detalle →</span></div></button>)}{!items.length && <p className="px-2 py-7 text-center text-xs text-slate-400">Sin postulantes en esta página</p>}</div></section> })}</div>
    {query.data && <div className="mt-4 flex items-center justify-between gap-3 text-xs text-slate-500"><span>{query.data.total} postulantes · Página {query.data.current_page} de {query.data.last_page}</span><div className="flex gap-2"><button className={button} disabled={query.data.current_page <= 1} onClick={() => setParams({ ...params, page: String(query.data.current_page - 1) })}>Anterior</button><button className={button} disabled={query.data.current_page >= query.data.last_page} onClick={() => setParams({ ...params, page: String(query.data.current_page + 1) })}>Siguiente</button></div></div>}
    {selected && <Detalle key={selected} id={selected} admin={admin} onClose={() => setSelected(null)} />}
  </div>
}
