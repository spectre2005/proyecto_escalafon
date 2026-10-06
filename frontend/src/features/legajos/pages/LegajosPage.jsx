import { useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { useLiveQuery } from '../../../shared/queries/useLiveQuery'
import Seccion01Panel from '../components/seccion01/Seccion01Panel'
import Seccion02Panel from '../components/seccion02/Seccion02Panel'
import Seccion03Panel from '../components/seccion03/Seccion03Panel'
import Seccion04Panel from '../components/seccion04/Seccion04Panel'
import Seccion06Panel from '../components/seccion06/Seccion06Panel'
import Seccion07Panel from '../components/seccion07/Seccion07Panel'
import Seccion08Panel from '../components/seccion08/Seccion08Panel'
import { subseccionesSeccion07 } from '../config/seccion07'
import Seccion09Panel from '../components/seccion09/Seccion09Panel'
import { categoriasSeccion09 } from '../config/seccion09'
import { subseccionesSeccion02 } from '../config/seccion02'
import Seccion05Panel from '../components/seccion05/Seccion05Panel'
import { categoriasSeccion05 } from '../config/seccion05'
import { categoriasSensibles, gruposSeccion01 } from '../config/seccion01'
import { usePuede } from '../../auth/session'
import PersonalHeader from '../components/PersonalHeader'
import GeneralDataEditor from '../components/GeneralDataEditor'
import { legajoSections, findDocumentSection } from '../config/legajoSections'
import Icon from '../../../components/ui/Icon'

const control = 'rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-700 outline-none focus:ring-2 focus:ring-teal-600 disabled:opacity-40'
const panel = 'rounded-2xl border border-slate-200 bg-white'
const titles = {
  tipo_personal: 'Tipo de personal', domicilio: 'Domicilio',
  exige_colegiatura: 'Requiere colegiatura', exige_estudios_basicos: 'El puesto exige estudios básicos', tiene_periodo_prueba: 'Tiene período de prueba',
  tiene_derechohabientes: 'Tiene derechohabientes', con_discapacidad: 'Con discapacidad',
  ultimo_acceso: 'Último acceso al sistema',
  numero_doc_identidad: 'Documento de identidad', tipo_doc_identidad: 'Tipo de identificación',
  region: 'Regi\u00f3n', provincia: 'Provincia', distrito: 'Distrito',
  usuario_id: 'Titular', documento_id: 'Documento de respaldo', archivo_id: 'Archivo asociado',
  legajo_id: 'Legajo', vinculo_laboral_id: 'Vínculo laboral', seccion_legajo_id: 'Sección',
  subseccion_legajo_id: 'Subsección', tipo_documento_id: 'Tipo de documento',
  email_institucional: 'Correo institucional', regimen_laboral: 'Régimen laboral',
  regimen_normativo_id: 'Régimen normativo', unidad_organica_id: 'Unidad orgánica',
  fecha_nacimiento: 'Fecha de nacimiento', direccion: 'Dirección', telefono: 'Teléfono',
  ubicacion: 'Ubicación', calificacion: 'Calificación', descripcion: 'Descripción',
  anulado: 'Registro anulado', activo: 'Activo', numero_legajo: 'Número de legajo',
  fecha_apertura: 'Fecha de apertura', nombre_puesto: 'Puesto', ultimo_folio: 'Último folio',
  tamano_bytes: 'Tamaño en bytes', mime_type: 'Tipo de archivo', num_paginas: 'Páginas',
  es_dato_sensible: 'Dato sensible', es_dato_salud: 'Dato de salud',
  obligado_djbr: 'Obligación de declaración jurada de bienes y rentas',
}

function label(key) {
  return titles[key] || key.replace(/_id$/, '').replaceAll('_', ' ').replace(/^./, c => c.toUpperCase())
}

function valueText(value) {
  if (value === null || value === undefined || value === '') return 'No registrado'
  if (typeof value === 'boolean') return value ? 'Sí' : 'No'
  if (/^\d{4}-\d{2}-\d{2}$/.test(String(value))) return String(value).split('-').reverse().join('/')
  return String(value).replaceAll('_', ' ')
}

function useRead(url) {
  const result = useLiveQuery(url)
  const error = result.error ? (result.error.response?.status === 404
    ? 'No se encontro el expediente solicitado.'
    : 'No se pudo actualizar la informacion. Comprueba la conexion.') : null
  return { ...result, error }
}

function Feedback({ loading, error, retry }) {
  if (error) return <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-800">{error}<button onClick={retry} className="ml-3 font-semibold underline">Reintentar</button></div>
  if (loading) return <div role="status" className="animate-pulse rounded-xl bg-slate-100 p-8 text-sm text-slate-500">Consultando información…</div>
  return null
}

function Fields({ values }) {
  return <dl className="grid gap-x-8 gap-y-5 sm:grid-cols-2 xl:grid-cols-3">{Object.entries(values).map(([key, value]) => <div key={key} className="min-w-0"><dt className="text-[11px] font-medium text-slate-500">{label(key)}</dt><dd className={`mt-1.5 whitespace-pre-wrap break-words text-sm ${value === null || value === '' ? 'text-slate-400' : 'text-slate-800'}`}>{valueText(value)}</dd></div>)}</dl>
}

function Pagination({ data, onPage }) {
  if (!data || data.last_page <= 1) return null
  return <nav aria-label="Paginación" className="mt-5 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500"><span>Página {data.current_page} de {data.last_page} · {data.total} registros</span><div className="flex gap-2"><button className={control} disabled={data.current_page <= 1} onClick={() => onPage(data.current_page - 1)}>Anterior</button><button className={control} disabled={data.current_page >= data.last_page} onClick={() => onPage(data.current_page + 1)}>Siguiente</button></div></nav>
}

function Directory() {
  const [params, setParams] = useSearchParams()
  const [search, setSearch] = useState(params.get('q') || '')
  const request = useRead(`/expedientes?${params}`)
  function change(key, value) {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    setParams(next)
  }
  return <>
    <div className="mb-7 flex flex-wrap items-end justify-between gap-4"><div><p className="mb-2 text-[10px] font-semibold uppercase tracking-[.2em] text-teal-700">Archivo del personal</p><h1 className="text-3xl font-bold text-slate-800">Legajos</h1><p className="mt-3 text-sm text-slate-500">Documentos, antecedentes y trayectoria en una ficha por persona.</p></div></div>
    <section className={`${panel} mb-6 p-5`} aria-label="Buscar expedientes"><form onSubmit={event => { event.preventDefault(); change('q', search.trim()) }} className="flex flex-wrap gap-3"><label className="flex min-w-60 flex-1 items-center gap-2 rounded-xl border border-slate-200 px-3"><Icon name="search" size={18} /><input className="w-full py-3 text-sm outline-none" aria-label="Buscar por nombre, documento o número de legajo" placeholder="Nombre, documento o número de legajo" value={search} onChange={event => setSearch(event.target.value)} /></label><select aria-label="Tipo de personal" className={control} value={params.get('tipo') || ''} onChange={event => change('tipo', event.target.value)}><option value="">Todo el personal</option><option value="docente">Docentes</option><option value="administrativo">Administrativos</option></select><select aria-label="Disponibilidad de legajo" className={control} value={params.get('legajo') || ''} onChange={event => change('legajo', event.target.value)}><option value="">Con y sin legajo</option><option value="con">Con legajo</option><option value="sin">Sin legajo</option></select><button className="rounded-xl bg-teal-700 px-5 py-3 text-sm font-medium text-white hover:bg-teal-800">Buscar</button></form></section>
    <Feedback {...request} />
    {request.data && <section className={`${panel} overflow-hidden`}><div className="border-b border-slate-100 px-6 py-5"><h2 className="text-sm font-semibold text-slate-800">Directorio de expedientes <span className="ml-2 rounded-full bg-teal-50 px-2 py-1 text-xs text-teal-700">{request.data.total}</span></h2></div><div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-slate-50 text-xs text-slate-500"><tr>{['Personal', 'Unidad / puesto', 'Legajos', 'Documentos', ''].map((title, i) => <th className="px-6 py-4 font-medium" key={i}>{title}</th>)}</tr></thead><tbody>{request.data.data.map(user => <tr key={user.id} className="border-t border-slate-100 hover:bg-teal-50/30"><td className="px-6 py-5"><Link to={`/legajos/${user.id}`} className="font-semibold text-teal-800 hover:underline">{user.nombres} {user.apellido_paterno} {user.apellido_materno}</Link><p className="mt-1 text-xs text-slate-500">{user.tipo_doc_identidad?.toUpperCase() || 'Documento'}: {user.numero_doc_identidad || 'No registrado'} · {({ docente: 'Docente', administrativo: 'Administrativo' })[user.tipo_personal] || user.tipo_personal}</p></td><td className="px-6 py-5 text-slate-600">{user.unidad_organica?.nombre || 'Sin unidad registrada'}<p className="mt-1 text-xs text-slate-400">{user.nombre_puesto || 'Sin puesto registrado'}</p></td><td className="px-6 py-5">{user.legajos_count || <span className="text-xs text-amber-700">Sin legajo</span>}</td><td className="px-6 py-5">{user.documentos_usuario_por_usuario_count}</td><td className="px-6 py-5"><Link className="whitespace-nowrap text-xs font-semibold text-teal-700 hover:underline" to={`/legajos/${user.id}`}>Abrir ficha →</Link></td></tr>)}</tbody></table></div>{!request.data.data.length && <p className="p-10 text-center text-sm text-slate-500">No se encontraron personas con estos filtros.</p>}<div className="px-6 pb-5"><Pagination data={request.data} onPage={page => change('page', page)} /></div></section>}
  </>
}

function SectionRecords({ usuario, section, page, onPage, filters, onFilter, documentSections, documentStates }) {
  const query = new URLSearchParams({ page })
  if (section.key === 'documentos-usuario') {
    if (filters.get('seccion')) query.set('seccion_id', filters.get('seccion'))
    if (filters.get('estado')) query.set('estado', filters.get('estado'))
  }
  const request = useRead(`/expedientes/${usuario}/${section.key}?${query}`)
  return <section aria-label={section.label}>
    <div className="mb-5 flex items-start justify-between gap-4"><div><h2 className="text-xl font-semibold text-slate-800">{section.label}</h2><p className="mt-2 text-xs leading-relaxed text-slate-500">{section.scope === 'persona' ? 'Antecedentes de la persona, compartidos entre sus legajos.' : section.scope === 'catalogo' ? 'Catálogo de tipos documentales y sus reglas registradas. No representa documentos presentados ni determina por sí solo requisitos pendientes.' : 'Registros de todos los legajos de esta persona.'}</p></div>{request.data && <span className="rounded-lg bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-800">{request.data.total}</span>}</div>
    {section.key === 'documentos-usuario' && <div className="mb-5 flex flex-wrap gap-3"><select className={control} aria-label="Sección documental" disabled={documentSections.length === 1} value={filters.get('seccion') || ''} onChange={event => onFilter('seccion', event.target.value)}><option value="">Todas las secciones</option>{documentSections.map(item => <option key={item.id} value={item.id}>{item.nombre}</option>)}</select><select className={control} aria-label="Estado documental" value={filters.get('estado') || ''} onChange={event => onFilter('estado', event.target.value)}><option value="">Todos los estados</option>{documentStates.map(state => <option key={state} value={state}>{valueText(state)}</option>)}</select></div>}
    <Feedback {...request} />
    {request.data?.data.length === 0 && <div className={`${panel} p-10 text-center`}><span className="mx-auto mb-3 grid size-12 place-items-center rounded-2xl bg-slate-50 text-slate-400"><Icon name="folder" /></span><h3 className="text-sm font-medium text-slate-700">Sin registros para esta consulta</h3><p className="mt-2 text-xs text-slate-500">Revisa los filtros o selecciona otra categoría.</p></div>}
    <div className="space-y-3">{request.data?.data.map((record, index) => {
      const values = record.values
      const title = values.nombre || values.denominacion || values.numero_legajo || values.nombre_original || values.tipo_documento_id || values.entidad_empresa || values.concepto || values.titulo || values.numero_resolucion || `Registro ${(request.data.current_page - 1) * request.data.per_page + index + 1}`
      return <details key={record.id} className={`${panel} group overflow-hidden`}><summary className="flex cursor-pointer list-none items-center justify-between gap-3 p-5 focus-visible:outline-2 focus-visible:outline-teal-600"><div className="min-w-0"><h3 className="break-words text-sm font-semibold text-slate-800">{title}</h3><p className="mt-1 text-xs text-slate-500">{valueText(values.fecha_emision || values.fecha_inicio || values.fecha_evaluacion || values.fecha || values.ciclo_anio || values.tipo || section.label)}</p></div><div className="flex shrink-0 items-center gap-3">{(values.anulado || values.estado || values.estado_legajo) && <span className={`rounded-full px-2.5 py-1 text-[11px] ${values.anulado ? 'bg-rose-50 text-rose-700' : 'bg-teal-50 text-teal-700'}`}>{values.anulado ? 'Anulado' : valueText(values.estado || values.estado_legajo)}</span>}<span className="text-xs text-teal-700 group-open:hidden">Ver detalle +</span><span className="hidden text-xs text-teal-700 group-open:inline">Cerrar −</span></div></summary><div className="border-t border-slate-100 p-5"><Fields values={values} />{section.key === 'archivos-documento' && <p className="mt-5 rounded-lg bg-slate-50 p-3 text-xs text-slate-500">Metadatos del archivo. La descarga protegida aún no está disponible.</p>}</div></details>
    })}</div>
    <Pagination data={request.data} onPage={onPage} />
  </section>
}

function Bloqueado() {
  return <section className={`${panel} p-10 text-center`}><span className="mx-auto mb-3 grid size-12 place-items-center rounded-2xl bg-slate-50 text-slate-400"><Icon name="shield" /></span><h2 className="text-sm font-semibold text-slate-700">Sección con datos sensibles</h2><p className="mt-2 text-xs text-slate-500">Tu perfil no tiene acceso a datos sensibles ni de salud (Directiva, art. 6.2). Solicita el acceso a la jefatura de la ORH.</p></section>
}

function Detail({ usuario }) {
  const veSensibles = usePuede('datos_sensibles.ver')
  const puedeEditar = usePuede('legajo.editar') && veSensibles
  const [editing, setEditing] = useState(false)
  const [saved, setSaved] = useState(false)
  const request = useRead(`/expedientes/${usuario}`)
  const [params, setParams] = useSearchParams()
  const categoria = params.get('categoria') || 'general'
  const selected = categoria === 'tiempo-servicios-reconocidos' ? 'seccion-06' : categoria
  function change(key, value) {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    setParams(next)
  }
  const data = request.data?.data
  const group = legajoSections.find(item => item.categories.includes(selected) || (!item.sinDocumentos && selected === `documentos-${item.number}`))
  const categorias = item => [...item.categories, ...(item.sinDocumentos ? [] : [`documentos-${item.number}`])]
  // 2.4 siempre está en el menú; atenuada si la persona no tiene período de prueba.
  const noAplica = key => key === 's02-prueba' && !data?.tiene_periodo_prueba
  const isDocuments = selected.startsWith('documentos-')
  const section = group && data?.sections.find(item => item.key === (isDocuments ? 'documentos-usuario' : selected))
  const documentSection = group && data ? findDocumentSection(group, data.document_sections) : null
  const sectionFilters = new URLSearchParams(params)
  if (isDocuments && documentSection) sectionFilters.set('seccion', documentSection.id)
  return <>
    <Link to="/legajos" className="mb-5 inline-block text-xs font-medium text-teal-700 hover:underline">← Todos los expedientes</Link>
    <Feedback {...request} />
    {data && <>
      <PersonalHeader data={data} usuario={usuario} />
      <div className="grid items-start gap-6 lg:grid-cols-[280px_minmax(0,1fr)]"><nav aria-label="Secciones del legajo" className={`${panel} p-3`}>{legajoSections.map(item => <details key={item.number} open={group?.number === item.number} className="border-b border-slate-100 py-2 last:border-b-0"><summary className="cursor-pointer px-3 py-2 text-xs font-semibold leading-relaxed text-slate-700">{item.title}</summary><div className="mt-1 space-y-1">{categorias(item).map(key => <button key={key} aria-current={selected === key ? 'page' : undefined} onClick={() => change('categoria', key)} className={`w-full rounded-lg px-3 py-2.5 text-left text-xs ${selected === key ? 'bg-teal-50 font-semibold text-teal-800' : noAplica(key) ? 'text-slate-400 hover:bg-slate-50' : 'text-slate-500 hover:bg-slate-50'}`}>{key === 'seccion-06' ? 'Documentos de compensaciones' : key === 'seccion-04' ? 'Documentos y tiempo de experiencia' : key === 'seccion-03' ? 'Documentos y avance de formación' : key === "reconocimientos" ? "Reconocimientos" : key === "sanciones" ? "Sanciones disciplinarias" : gruposSeccion01[key] ? gruposSeccion01[key].titulo : subseccionesSeccion02[key] ? <>{subseccionesSeccion02[key].titulo}{noAplica(key) && <span className="ml-1 text-[10px]">· No aplica</span>}</> : categoriasSeccion05[key] ? categoriasSeccion05[key].titulo : subseccionesSeccion07[key] ? subseccionesSeccion07[key].titulo : categoriasSeccion09[key] ? categoriasSeccion09[key].titulo : key.startsWith('documentos-') ? 'Documentos de la sección' : data.sections.find(category => category.key === key)?.label}</button>)}</div></details>)}</nav>
        <div className="min-w-0">{(selected === "reconocimientos" || selected === "sanciones") ? <Seccion08Panel key={`${usuario}:${selected}`} usuario={usuario} categoria={selected} /> : (categoriasSensibles.includes(selected) || categoriasSeccion09[selected]) && !veSensibles ? <Bloqueado /> : subseccionesSeccion02[selected] ? <Seccion02Panel key={`${usuario}:${selected}`} usuario={usuario} categoria={selected} onNavigate={key => change('categoria', key)} /> : selected === 'seccion-06' ? <Seccion06Panel key={usuario} usuario={usuario} /> : selected === 'seccion-04' ? <Seccion04Panel key={usuario} usuario={usuario} /> : selected === 'seccion-03' ? <Seccion03Panel key={usuario} usuario={usuario} /> : categoriasSeccion05[selected] ? <Seccion05Panel key={`${usuario}:${selected}`} usuario={usuario} categoria={selected} /> : categoriasSeccion09[selected] ? <Seccion09Panel key={`${usuario}:${selected}`} usuario={usuario} categoria={selected} /> : subseccionesSeccion07[selected] ? <Seccion07Panel key={`${usuario}:${selected}`} usuario={usuario} categoria={selected} onNavigate={key => change('categoria', key)} /> : gruposSeccion01[selected] && selected !== 'general' ? <Seccion01Panel key={`${usuario}:${selected}`} usuario={usuario} categoria={selected} /> : selected === 'general' ? <><section className={`${panel} p-6`}>{editing ? <GeneralDataEditor usuario={usuario} data={data} onCancel={() => setEditing(false)} onSaved={updated => { request.setData(updated); setEditing(false); setSaved(true) }} /> : <><div className="mb-6 flex items-center justify-between gap-4"><h2 className="text-lg font-semibold text-slate-800">Información personal</h2>{puedeEditar && <button onClick={() => { setEditing(true); setSaved(false) }} className="rounded-xl border border-teal-200 bg-teal-50 px-4 py-2 text-sm font-semibold text-teal-800 hover:bg-teal-100">Editar</button>}</div>{data.datos_sensibles_ocultos && <p className="mb-5 rounded-lg bg-slate-50 p-3 text-xs text-slate-500">Se muestran solo los datos de identificación. Los datos personales sensibles (nacimiento, domicilio, teléfono, estado civil, discapacidad) requieren acceso a datos sensibles.</p>}{saved && <p role="status" className="mb-5 rounded-lg bg-teal-50 p-3 text-sm text-teal-800">Cambios guardados.</p>}<Fields values={Object.fromEntries([...['nombres', 'apellido_paterno', 'apellido_materno'].map(key => [key, data.usuario.values[key]]), ...Object.entries(data.usuario.values).filter(([key]) => !['nombres', 'apellido_paterno', 'apellido_materno', 'foto_path'].includes(key))])} /></>}</section>{veSensibles ? <Seccion01Panel key={`${usuario}:general`} usuario={usuario} categoria="general" /> : <p className="mt-6 rounded-xl border border-slate-200 bg-white p-5 text-xs text-slate-500">Los documentos de identidad y la ficha de datos (sección 01) requieren acceso a datos sensibles.</p>}</> : group && isDocuments && !documentSection ? <section className={`${panel} p-6`}><h2 className="text-lg font-semibold text-slate-800">Documentos: {group?.title}</h2><p className="mt-3 text-sm text-slate-500">Esta sección todavía no tiene un catálogo documental asociado en la base de datos.</p></section> : section ? <SectionRecords key={`${usuario}:${section.key}`} usuario={usuario} section={section} filters={sectionFilters} onFilter={change} documentSections={isDocuments ? [documentSection] : data.document_sections} documentStates={data.document_states} page={params.get('page') || '1'} onPage={page => change('page', page)} /> : <p role="alert" className="p-6 text-sm text-slate-500">La categoría solicitada no existe. Selecciona una categoría del menú.</p>}</div>
      </div>
    </>}
  </>
}

export default function LegajosPage() {
  const { usuario } = useParams()
  return usuario ? <Detail key={usuario} usuario={usuario} /> : <Directory />
}
