import AvisosExpediente from '../components/documentos/AvisosExpediente'
import { useCallback, useEffect, useState } from 'react'
import { Link, useLocation, useParams, useSearchParams } from 'react-router-dom'
import { useLiveQuery } from '../../../shared/queries/useLiveQuery'
import { conModulosOcultos, primeraCategoria, seccionesVisibles } from '../../../shared/funcionalidadesEstado'
import { useFuncionalidades } from '../../../shared/useFuncionalidades'
import CompletaPerfil from '../components/CompletaPerfil'
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
import Seccion10Panel from '../components/seccion10/Seccion10Panel'
import Seccion11Panel from '../components/seccion11/Seccion11Panel'
import { categoriasSeccion11 } from '../config/seccion11'
import { puestoActual, subseccionesSeccion02, textoRegimen } from '../config/seccion02'
import Seccion05Panel from '../components/seccion05/Seccion05Panel'
import { categoriasSeccion05 } from '../config/seccion05'
import { categoriasSensibles, gruposSeccion01 } from '../config/seccion01'
import { ReadOnlyContext, usePuede } from '../../auth/session'
import PersonalHeader from '../components/PersonalHeader'
import GeneralDataEditor from '../components/GeneralDataEditor'
import PersonalDocuments from '../components/PersonalDocuments'
import { CATEGORIA_DOCUMENTOS, categoriaVigente, legajoSections, subseccionesSeccion10 } from '../config/legajoSections'
import Icon from '../../../components/ui/Icon'
import Paginacion from '../../../shared/Paginacion'
import { conPagina, conPorPagina, leerPaginacion, paginaCorregida } from '../../../shared/paginacionUrl'
import { banderasPuesto, esBandera, textoBandera } from '../../../shared/banderas'

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
  email_institucional: 'Correo electrónico', regimen_laboral: 'Tipo de vínculo',
  regimen_normativo_id: 'Ley / régimen normativo', unidad_organica_id: 'Unidad orgánica',
  ley: 'Ley / régimen normativo', tipo_vinculo: 'Tipo de vínculo vigente',
  puesto_actual: 'Puesto actual (vínculo vigente)', unidad_actual: 'Unidad orgánica actual (vínculo vigente)',
  puesto_persona: 'Puesto registrado en la persona', unidad_persona: 'Unidad orgánica registrada en la persona',
  fecha_nacimiento: 'Fecha de nacimiento', direccion: 'Dirección', telefono: 'Teléfono',
  ubicacion: 'Ubicación', calificacion: 'Calificación', descripcion: 'Descripción',
  anulado: 'Registro anulado', activo: 'Activo', legajo_activo: 'Legajo activo', numero_legajo: 'Número de legajo',
  fecha_apertura: 'Fecha de apertura', nombre_puesto: 'Puesto', ultimo_folio: 'Último folio',
  tamano_bytes: 'Tamaño en bytes', mime_type: 'Tipo de archivo', num_paginas: 'Páginas',
  es_dato_sensible: 'Dato sensible', es_dato_salud: 'Dato de salud',
  obligado_djbr: 'Obligación de declaración jurada de bienes y rentas',
}

function label(key) {
  return titles[key] || key.replace(/_id$/, '').replaceAll('_', ' ').replace(/^./, c => c.toUpperCase())
}

function valueText(value, key) {
  // Banderas del perfil: Sí / No / Sin responder (null no es No).
  if (esBandera(key)) return textoBandera(value)
  if (value === null || value === undefined || value === '') return 'No registrado'
  if (typeof value === 'boolean') return value ? 'Sí' : 'No'
  if (/^\d{4}-\d{2}-\d{2}$/.test(String(value))) return String(value).split('-').reverse().join('/')
  return String(value).replaceAll('_', ' ')
}

// Información personal: la ley y el tipo de vínculo vigente salen de data.regimen (regimen_laboral de la persona es solo
// un respaldo del código de ley y no se muestra como régimen). Las condiciones del puesto se registran en el vínculo: se
// muestra el valor que vale (condiciones.banderas), no la columna antigua de la persona. Con vínculo vigente, el puesto y
// la unidad guardados antes en la persona no se muestran: los actuales salen del vínculo.
function valoresGenerales(data) {
  const values = data.usuario.values
  const regimen = textoRegimen(data.regimen)
  const banderas = data.condiciones?.banderas || {}
  const conVinculo = data.puesto_actual?.origen === 'vinculo'
  const ocultos = ['nombres', 'apellido_paterno', 'apellido_materno', 'foto_path', 'regimen_laboral', 'regimen_normativo_id', ...(conVinculo ? ['nombre_puesto', 'unidad_organica_id'] : [])]
  return Object.fromEntries([...['nombres', 'apellido_paterno', 'apellido_materno'].map(key => [key, values[key]]),
    ...Object.entries(values).filter(([key]) => !ocultos.includes(key))
      .map(([key, value]) => [{ nombre_puesto: 'puesto_persona', unidad_organica_id: 'unidad_persona', activo: 'legajo_activo' }[key] || key,
        banderasPuesto.includes(key) && key in banderas ? banderas[key] : value]),
    ['ley', regimen.ley], ['tipo_vinculo', regimen.tipo],
    // El puesto y la unidad guardados en la persona (nombre_puesto, unidad_organica_id) siguen arriba como dato histórico.
    ...(data.puesto_actual?.origen === 'vinculo' ? [['puesto_actual', puestoActual(data.puesto_actual).puesto], ['unidad_actual', puestoActual(data.puesto_actual).unidad]] : [])])
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
  return <dl className="grid gap-x-8 gap-y-5 sm:grid-cols-2 xl:grid-cols-3">{Object.entries(values).map(([key, value]) => <div key={key} className="min-w-0"><dt className="text-[11px] font-medium text-slate-500">{label(key)}</dt><dd className={`mt-1.5 whitespace-pre-wrap break-words text-sm ${value === null || value === '' ? 'text-slate-400' : 'text-slate-800'}`}>{valueText(value, key)}</dd></div>)}</dl>
}

// Pie común (Paginacion) con las medidas que ya tenía Legajos. El servidor devuelve el paginador en la raíz.
function Pagination({ data, porPagina, onPage, onPorPagina, sustantivo, etiqueta, ocupado }) {
  if (!data?.total || paginaCorregida({ total: data.total, pagina: data.current_page, paginas: data.last_page })) return null
  return <Paginacion tamano="legajo" etiqueta={etiqueta} sustantivo={sustantivo} desde={data.from ?? 0} hasta={data.to ?? 0} total={data.total}
    pagina={data.current_page} paginas={data.last_page} porPagina={porPagina} onPagina={onPage} onPorPagina={onPorPagina} ocupado={ocupado} />
}

// Página inexistente (URL editada o guardada): se reemplaza por la última válida, con los mismos filtros y tamaño.
function useCorregirPagina(data, corregir) {
  const corregida = data ? paginaCorregida({ total: data.total, pagina: data.current_page, paginas: data.last_page }) : null
  useEffect(() => {
    if (corregida) corregir(corregida)
  }, [corregida, corregir])
  return corregida
}

function Directory() {
  const [params, setParams] = useSearchParams()
  const search = params.get('q') || ''
  const { pagina, porPagina } = leerPaginacion(params)
  const request = useRead(`/expedientes?${conPagina(conPorPagina(params, porPagina), pagina)}`)
  const corregida = useCorregirPagina(request.data, useCallback(numero => setParams(conPagina(params, numero), { replace: true }), [params, setParams]))
  function change(key, value) {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    setParams(next, { replace: true })
  }
  return <>
    <div className="mb-7 flex flex-wrap items-end justify-between gap-4"><div><p className="mb-2 text-[10px] font-semibold uppercase tracking-[.2em] text-teal-700">Archivo del personal</p><h1 className="text-3xl font-bold text-slate-800">Legajos</h1><p className="mt-3 text-sm text-slate-500">Documentos, antecedentes y trayectoria en una ficha por persona.</p></div></div>
    <section className={`${panel} mb-6 p-5`} aria-label="Buscar expedientes"><form onSubmit={event => { event.preventDefault(); change('q', search.trim()) }} className="flex flex-wrap gap-3"><label className="flex min-w-60 flex-1 items-center gap-2 rounded-xl border border-slate-200 px-3"><Icon name="search" size={18} /><input className="w-full py-3 text-sm outline-none" aria-label="Buscar por nombre, documento o número de legajo" placeholder="Nombre, documento o número de legajo" value={search} onChange={event => change('q', event.target.value)} /></label><select aria-label="Tipo de personal" className={control} value={params.get('tipo') || ''} onChange={event => change('tipo', event.target.value)}><option value="">Todo el personal</option><option value="docente">Docentes</option><option value="administrativo">Administrativos</option></select><select aria-label="Disponibilidad de legajo" className={control} value={params.get('legajo') || ''} onChange={event => change('legajo', event.target.value)}><option value="">Con y sin legajo</option><option value="con">Con legajo</option><option value="sin">Sin legajo</option></select><button className="rounded-xl bg-teal-700 px-5 py-3 text-sm font-medium text-white hover:bg-teal-800">Buscar</button></form></section>
    <Feedback {...request} />
    {request.data && <section className={`${panel} overflow-hidden`}><div className="border-b border-slate-100 px-6 py-5"><h2 className="text-sm font-semibold text-slate-800">Directorio de expedientes <span className="ml-2 rounded-full bg-teal-50 px-2 py-1 text-xs text-teal-700">{request.data.total}</span></h2></div><div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead className="bg-slate-50 text-xs text-slate-500"><tr>{['Personal', 'Unidad / puesto', 'Legajos', 'Documentos', ''].map((title, i) => <th className="px-6 py-4 font-medium" key={i}>{title}</th>)}</tr></thead><tbody>{request.data.data.map(user => <tr key={user.id} className="border-t border-slate-100 hover:bg-teal-50/30"><td className="px-6 py-5"><Link to={`/legajos/${user.id}`} className="font-semibold text-teal-800 hover:underline">{user.nombres} {user.apellido_paterno} {user.apellido_materno}</Link><p className="mt-1 text-xs text-slate-500">{user.tipo_doc_identidad?.toUpperCase() || 'Documento'}: {user.numero_doc_identidad || 'No registrado'} · {({ docente: 'Docente', administrativo: 'Administrativo' })[user.tipo_personal] || user.tipo_personal}</p></td><td className="px-6 py-5 text-slate-600">{puestoActual(user.puesto_actual).unidad || 'Sin unidad registrada'}<p className="mt-1 text-xs text-slate-400">{puestoActual(user.puesto_actual).puesto || 'Sin puesto registrado'}</p></td><td className="px-6 py-5">{user.legajos_count || <span className="text-xs text-amber-700">Sin legajo</span>}</td><td className="px-6 py-5">{user.documentos_usuario_por_usuario_count}</td><td className="px-6 py-5"><Link className="whitespace-nowrap text-xs font-semibold text-teal-700 hover:underline" to={`/legajos/${user.id}`}>Abrir ficha →</Link></td></tr>)}</tbody></table></div>{corregida && request.data.total > 0 ? <p role="status" className="p-10 text-center text-sm text-slate-500">La página {request.data.current_page} no existe. Mostrando la última página disponible…</p> : !request.data.data.length && <p className="p-10 text-center text-sm text-slate-500">No se encontraron personas con estos filtros.</p>}<div className="px-6 pb-5"><Pagination data={request.data} etiqueta="Paginación del directorio de expedientes" sustantivo="personas" porPagina={porPagina} ocupado={request.loading} onPage={numero => setParams(conPagina(params, numero))} onPorPagina={numero => setParams(conPorPagina(params, numero))} /></div></section>}
  </>
}

function SectionRecords({ usuario, section, page, porPagina, onPage, onPorPagina, onCorregirPagina, filters, onFilter, documentSections, documentStates }) {
  const query = conPagina(conPorPagina(new URLSearchParams(), porPagina), page)
  if (section.key === 'documentos-usuario') {
    if (filters.get('seccion')) query.set('seccion_id', filters.get('seccion'))
    if (filters.get('estado')) query.set('estado', filters.get('estado'))
  }
  const request = useRead(`/expedientes/${usuario}/${section.key}?${query}`)
  const corregida = useCorregirPagina(request.data, onCorregirPagina)
  return <section aria-label={section.label}>
    <div className="mb-5 flex items-start justify-between gap-4"><div><h2 className="text-xl font-semibold text-slate-800">{section.label}</h2><p className="mt-2 text-xs leading-relaxed text-slate-500">{section.scope === 'persona' ? 'Antecedentes de la persona, compartidos entre sus legajos.' : section.scope === 'catalogo' ? 'Catálogo de tipos documentales y sus reglas registradas. No representa documentos presentados ni determina por sí solo requisitos pendientes.' : 'Registros de todos los legajos de esta persona.'}</p></div>{request.data && <span className="rounded-lg bg-teal-50 px-3 py-1.5 text-xs font-semibold text-teal-800">{request.data.total}</span>}</div>
    {section.key === 'documentos-usuario' && <div className="mb-5 flex flex-wrap gap-3"><select className={control} aria-label="Sección documental" disabled={documentSections.length === 1} value={filters.get('seccion') || ''} onChange={event => onFilter('seccion', event.target.value)}><option value="">Todas las secciones</option>{documentSections.map(item => <option key={item.id} value={item.id}>{item.nombre}</option>)}</select><select className={control} aria-label="Estado documental" value={filters.get('estado') || ''} onChange={event => onFilter('estado', event.target.value)}><option value="">Todos los estados</option>{documentStates.map(state => <option key={state} value={state}>{valueText(state)}</option>)}</select></div>}
    <Feedback {...request} />
    {corregida && request.data.total > 0 ? <p role="status" className={`${panel} p-10 text-center text-sm text-slate-500`}>La página {request.data.current_page} no existe. Mostrando la última página disponible…</p> : request.data?.data.length === 0 && <div className={`${panel} p-10 text-center`}><span className="mx-auto mb-3 grid size-12 place-items-center rounded-2xl bg-slate-50 text-slate-400"><Icon name="folder" /></span><h3 className="text-sm font-medium text-slate-700">Sin registros para esta consulta</h3><p className="mt-2 text-xs text-slate-500">Revisa los filtros o selecciona otra categoría.</p></div>}
    <div className="space-y-3">{request.data?.data.map((record, index) => {
      const values = record.values
      const title = values.nombre || values.denominacion || values.numero_legajo || values.nombre_original || values.tipo_documento_id || values.entidad_empresa || values.concepto || values.titulo || values.numero_resolucion || `Registro ${(request.data.current_page - 1) * request.data.per_page + index + 1}`
      return <details key={record.id} className={`${panel} group overflow-hidden`}><summary className="flex cursor-pointer list-none items-center justify-between gap-3 p-5 focus-visible:outline-2 focus-visible:outline-teal-600"><div className="min-w-0"><h3 className="break-words text-sm font-semibold text-slate-800">{title}</h3><p className="mt-1 text-xs text-slate-500">{valueText(values.fecha_emision || values.fecha_inicio || values.fecha_evaluacion || values.fecha || values.ciclo_anio || values.tipo || section.label)}</p></div><div className="flex shrink-0 items-center gap-3">{(values.anulado || values.estado || values.estado_legajo) && <span className={`rounded-full px-2.5 py-1 text-[11px] ${values.anulado ? 'bg-rose-50 text-rose-700' : 'bg-teal-50 text-teal-700'}`}>{values.anulado ? 'Anulado' : valueText(values.estado || values.estado_legajo)}</span>}<span className="text-xs text-teal-700 group-open:hidden">Ver detalle +</span><span className="hidden text-xs text-teal-700 group-open:inline">Cerrar −</span></div></summary><div className="border-t border-slate-100 p-5"><Fields values={values} />{section.key === 'archivos-documento' && <p className="mt-5 rounded-lg bg-slate-50 p-3 text-xs text-slate-500">Metadatos del archivo. La descarga protegida aún no está disponible.</p>}</div></details>
    })}</div>
    <Pagination data={request.data} etiqueta={`Paginación de ${section.label}`} sustantivo="registros" porPagina={porPagina} ocupado={request.loading} onPage={onPage} onPorPagina={onPorPagina} />
  </section>
}

function Bloqueado() {
  return <section className={`${panel} p-10 text-center`}><span className="mx-auto mb-3 grid size-12 place-items-center rounded-2xl bg-slate-50 text-slate-400"><Icon name="shield" /></span><h2 className="text-sm font-semibold text-slate-700">Sección con datos sensibles</h2><p className="mt-2 text-xs text-slate-500">Tu perfil no tiene acceso a datos sensibles ni de salud (Directiva, art. 6.2). Solicita el acceso a la jefatura de la ORH.</p></section>
}

// Entrada de una funcionalidad que la institución desactivó (por ejemplo, abierta desde un enlace guardado).
function Desactivada() {
  return <section className={`${panel} p-10 text-center`}><span className="mx-auto mb-3 grid size-12 place-items-center rounded-2xl bg-slate-50 text-slate-400"><Icon name="settings" /></span><h2 className="text-sm font-semibold text-slate-700">Función no disponible</h2><p className="mt-2 text-xs text-slate-500">La institución desactivó esta función en su configuración. Lo registrado antes se conserva; para volver a usarla, debe activarse en Configuración.</p></section>
}

// Aviso al redirigir desde una entrada que su condición oculta (condiciones.modulos_ocultos de la ficha): módulos de la
// sección 01 y la 2.4. Se ocultan solo con la condición en No y sin datos registrados.
const avisosModuloOculto = {
  's02-prueba': '«2.4 Período de prueba» no se muestra: «Tiene período de prueba» está en No en el vínculo laboral y no hay períodos ni documentos registrados. Para verlo, cámbialo a Sí en 2.2.',
  's01-familia': '«Familia y derechohabientes» no se muestra: la persona figura sin derechohabientes y no tiene familiares ni documentos registrados. Para verlo, cambia «Tiene derechohabientes» en Información personal.',
  's01-discapacidad': '«Discapacidad» no se muestra: la persona figura sin discapacidad (el servidor ni sus hijos) y no tiene certificados ni documentos registrados. Para verlo, cambia «Con discapacidad» en Información personal.',
}

function DestinoLegajo() {
  const { hash, pathname, search } = useLocation()
  useEffect(() => {
    if (!hash) return
    let id
    try { id = decodeURIComponent(hash.slice(1)) } catch { return }
    if (!/^(perfil-pendiente-|registro-|bloque-|item-)[a-zA-Z0-9_-]+$/.test(id)) return
    let frame
    const observer = new MutationObserver(enfocar)
    function enfocar() {
      const destino = document.getElementById(id)
      if (!destino) return
      observer.disconnect()
      frame = requestAnimationFrame(() => {
        for (let padre = destino.parentElement; padre; padre = padre.parentElement) {
          if (padre.tagName === 'DETAILS') padre.open = true
        }
        destino.scrollIntoView({ behavior: 'smooth', block: 'center' })
        destino.tabIndex = -1
        destino.focus({ preventScroll: true })
      })
    }
    observer.observe(document.body, { childList: true, subtree: true })
    enfocar()
    const timeout = setTimeout(() => observer.disconnect(), 10000)
    return () => { observer.disconnect(); clearTimeout(timeout); cancelAnimationFrame(frame) }
  }, [hash, pathname, search])
  return null
}

function Detail({ usuario }) {
  const funcionalidades = useFuncionalidades()
  const veSensibles = usePuede('datos_sensibles.ver')
  const permisoEditar = usePuede('legajo.editar') && veSensibles
  const permisoSubir = usePuede('documento.subir')
  const [uploadCode, setUploadCode] = useState(null)
  const [editing, setEditing] = useState(false)
  const [saved, setSaved] = useState(false)
  const request = useRead(`/expedientes/${usuario}`)
  const [params, setParams] = useSearchParams()
  const categoria = params.get('categoria') || 'general'
  const pedida = categoriaVigente(categoria)
  function change(key, value) {
    const next = new URLSearchParams(params)
    if (value) next.set(key, value)
    else next.delete(key)
    if (key !== 'page') next.delete('page')
    next.delete('oculto')
    setParams(next)
  }
  // Registros de la sección: página y tamaño en la URL; el tamaño vuelve a la primera página.
  const paginacion = leerPaginacion(params)
  function cambiarPorPagina(numero) {
    const next = conPorPagina(params, numero)
    next.delete('oculto')
    setParams(next)
  }
  const corregirPagina = useCallback(numero => setParams(conPagina(params, numero), { replace: true }), [params, setParams])
  const data = request.data?.data
  // Lo que desactiva Funcionalidades muestra «Función no disponible»; lo que oculta una condición en No (sin
  // datos registrados) desaparece del menú y redirige a la primera entrada disponible, con un aviso breve.
  const modulosOcultos = data?.condiciones?.modulos_ocultos || []
  const porFuncionalidad = seccionesVisibles(legajoSections, funcionalidades)
  const secciones = seccionesVisibles(legajoSections, conModulosOcultos(funcionalidades, modulosOcultos))
  const ocultaPorBandera = modulosOcultos.includes(pedida)
  const selected = ocultaPorBandera ? primeraCategoria(secciones) : pedida
  useEffect(() => {
    if (!ocultaPorBandera) return
    setParams(previos => {
      const next = new URLSearchParams(previos)
      next.set('categoria', selected)
      next.set('oculto', pedida)
      next.delete('page')
      return next
    }, { replace: true })
  }, [ocultaPorBandera, pedida, selected, setParams])
  // Solo mientras siga oculto: si la bandera cambia (p. ej. a Sí), el aviso ya no aplica.
  const avisoOculto = modulosOcultos.includes(params.get('oculto')) ? avisosModuloOculto[params.get('oculto')] : null
  const hasActiveLegajo = data?.legajos.some(legajo => legajo.values.estado_legajo === 'activo')
  const readOnly = !!data?.legajos.length && !hasActiveLegajo
  const puedeEditar = permisoEditar && !readOnly
  const puedeSubir = permisoSubir && veSensibles && !readOnly
  const group = legajoSections.find(item => item.categories.includes(selected))
  // La categoría existe pero su funcionalidad o su sección está desactivada (no cuenta lo que oculta una bandera).
  const desactivada = !!group && !porFuncionalidad.some(item => item.categories.includes(selected))
  // «Todos los documentos»: una sola entrada para el legajo, filtrable por las secciones visibles.
  const isDocuments = selected === CATEGORIA_DOCUMENTOS
  const section = isDocuments ? data?.sections.find(item => item.key === 'documentos-usuario') : group && data?.sections.find(item => item.key === selected)
  const documentSections = (data?.document_sections || []).filter(item => secciones.some(visible => Number(visible.number) === Number(item.id)))
  function tituloCategoria(key) {
    const definicion = subseccionesSeccion10[key] || gruposSeccion01[key] || subseccionesSeccion02[key] || categoriasSeccion05[key] || subseccionesSeccion07[key] || categoriasSeccion09[key] || categoriasSeccion11[key]
    const nombres = { 'seccion-03': 'Documentos y avance de formación', 'seccion-04': 'Documentos y tiempo de experiencia', 'seccion-06': 'Documentos de compensaciones', reconocimientos: 'Reconocimientos', sanciones: 'Sanciones disciplinarias' }
    return definicion?.titulo || nombres[key] || (key === CATEGORIA_DOCUMENTOS ? 'Todos los documentos' : data?.sections.find(category => category.key === key)?.label) || key
  }
  return <ReadOnlyContext.Provider key={readOnly ? 'consulta' : 'activo'} value={readOnly}>
    <Link to="/legajos" className="mb-5 inline-block text-xs font-medium text-teal-700 hover:underline">← Todos los expedientes</Link>
    <Feedback {...request} />
    {data && <>
      {/* En una sección, la persona en una línea y el perfil plegado: la sección de trabajo queda en la primera pantalla. */}
      <PersonalHeader data={data} usuario={usuario} compacto={selected !== 'general'} onVerFicha={() => change('categoria', 'general')} />
      <DestinoLegajo />
      {!readOnly && <CompletaPerfil usuario={usuario} pendientes={data.condiciones?.pendientes} legajoActivo={hasActiveLegajo} plegado={selected !== 'general'} />}
      {avisoOculto && <p role="status" className="mb-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">{avisoOculto}</p>}
      {readOnly && <p role="status" className="mb-5 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">Legajo cerrado. Puedes consultar las secciones y visualizar o descargar sus documentos.</p>}
      {(hasActiveLegajo || data.legajos.length > 0) ? <div className="grid items-start gap-6 lg:grid-cols-[280px_minmax(0,1fr)]"><div className={`${panel} p-4 lg:hidden`}><label className="block text-xs font-semibold text-slate-600" htmlFor="seleccion-seccion-legajo">Ir a una sección</label><select id="seleccion-seccion-legajo" value={selected} onChange={event => change('categoria', event.target.value)} className="mt-2 w-full min-w-0 rounded-xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-800 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"><option value={CATEGORIA_DOCUMENTOS}>{tituloCategoria(CATEGORIA_DOCUMENTOS)}</option>{secciones.map(item => <optgroup key={item.number} label={`${item.number} · ${item.title}`}>{item.categories.map(key => <option key={key} value={key}>{tituloCategoria(key)}</option>)}</optgroup>)}</select></div><nav aria-label="Secciones del legajo" className={`${panel} hidden p-3 lg:block`}><button aria-current={isDocuments ? 'page' : undefined} onClick={() => change('categoria', CATEGORIA_DOCUMENTOS)} className={`mb-1 w-full rounded-lg border-b border-slate-100 px-3 py-2.5 text-left text-xs font-semibold ${isDocuments ? 'bg-teal-50 text-teal-800' : 'text-slate-700 hover:bg-slate-50'}`}>{tituloCategoria(CATEGORIA_DOCUMENTOS)}</button>{secciones.map(item => <details key={item.number} open={group?.number === item.number} className="border-b border-slate-100 py-2 last:border-b-0"><summary className="cursor-pointer px-3 py-2 text-xs font-semibold leading-relaxed text-slate-700">{item.title}</summary><div className="mt-1 space-y-1">{item.categories.map(key => <button key={key} aria-current={selected === key ? 'page' : undefined} onClick={() => change('categoria', key)} className={`w-full rounded-lg px-3 py-2.5 text-left text-xs ${selected === key ? 'bg-teal-50 font-semibold text-teal-800' : 'text-slate-500 hover:bg-slate-50'}`}>{tituloCategoria(key)}</button>)}</div></details>)}</nav>
        <div className="min-w-0">{desactivada ? <Desactivada /> : (selected === "reconocimientos" || selected === "sanciones") ? <Seccion08Panel key={`${usuario}:${selected}`} usuario={usuario} categoria={selected} /> : (categoriasSensibles.includes(selected) || categoriasSeccion09[selected]) && !veSensibles ? <Bloqueado /> : subseccionesSeccion02[selected] ? <Seccion02Panel key={`${usuario}:${selected}`} usuario={usuario} categoria={selected} onNavigate={key => change('categoria', key)} /> : subseccionesSeccion10[selected] ? <Seccion10Panel key={usuario + ':' + selected} usuario={usuario} subseccion={subseccionesSeccion10[selected].codigo} /> : selected === 'seccion-06' ? <Seccion06Panel key={usuario} usuario={usuario} /> : selected === 'seccion-04' ? <Seccion04Panel key={usuario} usuario={usuario} /> : selected === 'seccion-03' ? <Seccion03Panel key={usuario} usuario={usuario} /> : categoriasSeccion05[selected] ? <Seccion05Panel key={`${usuario}:${selected}`} usuario={usuario} categoria={selected} /> : categoriasSeccion11[selected] ? <Seccion11Panel key={`${usuario}:${selected}`} usuario={usuario} categoria={selected} onNavigate={key => change('categoria', key)} /> : categoriasSeccion09[selected] ? <Seccion09Panel key={`${usuario}:${selected}`} usuario={usuario} categoria={selected} /> : subseccionesSeccion07[selected] ? <Seccion07Panel key={`${usuario}:${selected}`} usuario={usuario} categoria={selected} onNavigate={key => change('categoria', key)} /> : gruposSeccion01[selected] && selected !== 'general' ? <Seccion01Panel key={`${usuario}:${selected}`} usuario={usuario} categoria={selected} /> : selected === 'general' ? <><section className={`${panel} relative overflow-hidden p-6 before:absolute before:inset-y-0 before:left-0 before:w-1 before:bg-teal-700`}>{editing && !readOnly && <GeneralDataEditor usuario={usuario} data={data} onUploadDni={puedeSubir ? () => setUploadCode('S01_B_DOCUMENTO_IDENTIDAD') : undefined} onUploadFicha={puedeSubir ? () => setUploadCode('S01_A_FICHA_DATOS') : undefined} onCancel={() => setEditing(false)} onSaved={updated => { request.setData(updated); setEditing(false); setSaved(true) }} />}<><div className="mb-6 flex flex-wrap items-center justify-between gap-4"><div className="flex items-center gap-4"><span className="grid size-14 shrink-0 place-items-center rounded-2xl bg-teal-50 text-teal-700"><Icon name="user" size={27} /></span><div><p className="text-xs text-slate-500">01 · Información personal y familiar</p><h2 className="mt-1 text-xl font-bold tracking-tight text-slate-800 sm:text-2xl">Información personal</h2></div></div><div className="flex flex-wrap items-center gap-3">{puedeSubir && <button type="button" onClick={() => setUploadCode('S01_A_FICHA_DATOS')} className="inline-flex items-center gap-2 rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800"><Icon name="upload" size={17} />Subir ficha personal</button>}{puedeSubir && <button type="button" onClick={() => setUploadCode('S01_B_DOCUMENTO_IDENTIDAD')} className="inline-flex items-center gap-2 rounded-xl border border-teal-200 bg-white px-4 py-2 text-sm font-semibold text-teal-800 hover:bg-teal-50"><Icon name="upload" size={17} />Subir copia de DNI</button>}{puedeEditar && <button onClick={() => { setEditing(true); setSaved(false) }} className="rounded-xl border border-teal-200 bg-teal-50 px-4 py-2 text-sm font-semibold text-teal-800 hover:bg-teal-100">Editar</button>}</div></div><AvisosExpediente usuario={usuario} avisos={data.condiciones?.avisos || []} />{data.datos_sensibles_ocultos && <p className="mb-5 rounded-lg bg-slate-50 p-3 text-xs text-slate-500">Se muestran solo los datos de identificación. Los datos personales sensibles (nacimiento, domicilio, teléfono, estado civil, discapacidad) requieren acceso a datos sensibles.</p>}{saved && <p role="status" className="mb-5 rounded-lg bg-teal-50 p-3 text-sm text-teal-800">Cambios guardados.</p>}<Fields values={valoresGenerales(data)} /></>{veSensibles && <PersonalDocuments usuario={usuario} uploadCode={readOnly ? null : uploadCode} onClose={() => setUploadCode(null)} />}</section></> : section ? <SectionRecords key={`${usuario}:${section.key}`} usuario={usuario} section={section} filters={params} onFilter={change} documentSections={documentSections} documentStates={data.document_states} page={paginacion.pagina} porPagina={paginacion.porPagina} onPage={page => change('page', page)} onPorPagina={cambiarPorPagina} onCorregirPagina={corregirPagina} /> : <p role="alert" className="p-6 text-sm text-slate-500">La categoría solicitada no existe. Selecciona una categoría del menú.</p>}</div>
      </div> : <section role="status" className={`${panel} p-6`}>
        <h2 className="text-lg font-semibold text-slate-800">Legajo inactivo</h2>
        <p className="mt-2 text-sm text-slate-500">Las 11 secciones se habilitan cuando esta persona tiene un legajo activo. Abre su legajo desde la ficha superior para continuar.</p>
      </section>}
    </>}
  </ReadOnlyContext.Provider>
}

export default function LegajosPage() {
  const { usuario } = useParams()
  return usuario ? <Detail key={usuario} usuario={usuario} /> : <Directory />
}
