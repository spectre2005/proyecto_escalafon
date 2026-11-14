import { Link, useParams, useSearchParams } from 'react-router-dom'
import { useLiveQuery } from '../../../shared/queries/useLiveQuery'
import { usePuede } from '../../auth/session'
import Paginacion from '../../../shared/Paginacion'
import { conPagina, conPorPagina, leerPaginacion } from '../../../shared/paginacionUrl'

const fecha = value => value ? value.split('-').reverse().join('/') : 'No registrada'
const texto = value => value === null || value === '' ? 'No registrado' : typeof value === 'boolean' ? (value ? 'Sí' : 'No') : String(value)

const etiquetasFicha = {
  tipo_doc_identidad: 'Tipo de documento', numero_doc_identidad: 'Documento de identidad', nombres: 'Nombres', apellido_paterno: 'Apellido paterno',
  apellido_materno: 'Apellido materno', email_institucional: 'Correo electrónico', telefono: 'Teléfono', tipo_personal: 'Tipo de personal',
  nacionalidad: 'Nacionalidad', estado_civil: 'Estado civil', domicilio: 'Domicilio', tiene_derechohabientes: 'Tiene derechohabientes',
  con_discapacidad: 'Con discapacidad', fecha_nacimiento: 'Fecha de nacimiento', region: 'Región', provincia: 'Provincia', distrito: 'Distrito',
  ley: 'Ley / régimen normativo', puesto: 'Puesto al cierre', unidad_organica: 'Unidad orgánica al cierre',
}

function FichaAlCierre({ usuario, legajo }) {
  const consulta = useLiveQuery(`/expedientes/${usuario}/legajos/${legajo}`)
  const etapa = consulta.data?.data
  return <section className="mt-5 rounded-2xl border border-slate-200 bg-white p-5">
    <h2 className="text-sm font-semibold text-slate-800">Ficha conservada al cierre</h2>
    {consulta.error && <p role="alert" className="mt-3 text-sm text-rose-800">No se pudo consultar esta etapa. <button type="button" onClick={consulta.retry} className="underline">Reintentar</button></p>}
    {!etapa && consulta.loading && <p role="status" className="mt-3 text-sm text-slate-500">Consultando ficha histórica…</p>}
    {etapa && <>
      <p className="mt-2 text-xs text-slate-500">Legajo {etapa.numero_legajo} · Apertura: {fecha(etapa.fecha_apertura)} · Pase a pasivo: {fecha(etapa.fecha_pasivo)}</p>
      {etapa.datos_al_cierre ? <>
        <p className="mt-2 text-xs text-slate-500">Datos registrados al cerrar esta etapa. Se conservan aunque la ficha actual de la persona cambie.</p>
        <dl className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">{Object.entries(etapa.datos_al_cierre).map(([key, value]) => <div key={key}><dt className="text-xs text-slate-500">{etiquetasFicha[key] || key}</dt><dd className="mt-1 break-words text-sm font-medium text-slate-800">{key === 'fecha_nacimiento' ? fecha(value) : texto(value)}</dd></div>)}</dl>
      </> : <p className="mt-3 text-sm text-slate-500">{etapa.estado_legajo === 'activo' ? 'Esta etapa está activa. Su ficha se conservará al cerrar el legajo.' : 'Este legajo se cerró antes de habilitar la conservación de la ficha. Sus documentos y registros siguen disponibles; los datos personales al cierre no están disponibles.'}</p>}
    </>}
  </section>
}

export default function HistorialLegajoPage() {
  const { usuario, legajo } = useParams()
  const [params, setParams] = useSearchParams()
  const ficha = useLiveQuery(`/expedientes/${usuario}`).data?.data
  const sensible = usePuede('datos_sensibles.ver')
  const evaluaciones = usePuede('seccion07.ver')
  const verSanciones = usePuede('seccion08.sanciones.ver')
  const registrarSanciones = usePuede('seccion08.sanciones.registrar')
  const revisarSanciones = usePuede('seccion08.sanciones.revisar')
  const opciones = [
    ['formacion-academica', 'Formación académica'], ['capacitaciones', 'Capacitaciones'], ['experiencia-laboral', 'Experiencia laboral'], ['colegiaturas', 'Colegiaturas'], ['reconocimientos', 'Reconocimientos'],
    ['tiempo-servicios-reconocidos', 'Tiempo de servicio reconocido'], ['compensaciones-beneficios', 'Compensaciones y beneficios'], ['vinculo-adendas', 'Adendas'],
    ['vinculos-laborales', 'Vínculos laborales'], ['desvinculaciones', 'Desvinculaciones'], ['inducciones', 'Inducciones'], ['periodos-prueba', 'Períodos de prueba'],
    ['licencias-permisos', 'Licencias y permisos'], ['periodos-vacacionales', 'Períodos vacacionales'], ['goces-vacacionales', 'Goces vacacionales'],
    ...(evaluaciones ? [['evaluaciones-desempeno', 'Evaluaciones'], ['progresiones-carrera', 'Progresiones'], ['ratificaciones-docentes', 'Ratificaciones'], ['desplazamientos', 'Desplazamientos']] : []),
    ...(verSanciones || registrarSanciones || revisarSanciones ? [['sanciones', 'Sanciones']] : []),
    ...(sensible ? [['familiares', 'Familiares'], ['certificados-discapacidad', 'Certificados de discapacidad'], ['registros-sst-bienestar', 'SST y bienestar'], ['declaraciones-verificaciones', 'Declaraciones y verificaciones'], ['controversias', 'Controversias'], ['afiliaciones', 'Afiliaciones']] : []),
  ]
  const categoria = opciones.some(([key]) => key === params.get('categoria')) ? params.get('categoria') : 'vinculos-laborales'
  const { pagina, porPagina } = leerPaginacion(params)
  const query = conPagina(conPorPagina(new URLSearchParams(), porPagina), pagina)
  if (legajo !== 'todos') query.set('legajo_id', legajo)
  const consulta = useLiveQuery(`/expedientes/${usuario}/${categoria}?${query}`)
  const etapa = ficha?.legajos.find(item => item.id === legajo)
  return <>
    <Link to={`/legajos/${usuario}`} className="text-xs font-semibold text-teal-700 hover:underline">← Etapa actual / ficha de la persona</Link>
    <h1 className="mt-4 text-2xl font-bold text-slate-800">Historial de legajos</h1>
    <p className="mt-2 text-sm text-slate-600">{ficha?.nombre} · {etapa ? `Legajo ${etapa.values.numero_legajo} (${etapa.values.estado_legajo})` : legajo === 'todos' ? 'Todas las etapas, incluidos los antecedentes sin legajo identificado' : 'Consultando legajo…'}</p>
    <p className="mt-2 text-xs text-slate-500">Vista de consulta. Estos registros no se incorporan automáticamente al nuevo legajo.</p>
    {legajo !== 'todos' && <Link to={`/legajos/${usuario}?categoria=documentos&legajo_id=${legajo}`} className="mt-3 inline-block text-sm font-semibold text-teal-700 underline">Consultar documentos de este legajo</Link>}
    <section aria-label="Incorporaciones de la persona" className="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
      {ficha?.legajos.map((item, index) => <Link key={item.id} to={`/legajos/${usuario}/historial/${item.id}`} aria-current={item.id === legajo ? 'page' : undefined} className={`rounded-xl border p-4 text-sm ${item.id === legajo ? 'border-teal-500 bg-teal-50' : 'border-slate-200 bg-white hover:border-teal-300'}`}>
        <p className="font-semibold text-slate-800">Incorporación {ficha.legajos.length - index} · Legajo {item.values.numero_legajo}</p>
        <p className="mt-2 text-xs text-slate-600">{item.values.estado_legajo === 'activo' ? 'Activo · Etapa actual' : 'Pasivo · Cerrado · Solo consulta'}</p>
        <p className="mt-2 text-xs text-slate-500">Apertura: {fecha(item.values.fecha_apertura)} · Cierre: {fecha(item.values.fecha_pasivo)}</p>
      </Link>)}
    </section>
    {legajo !== 'todos' && <FichaAlCierre key={legajo} usuario={usuario} legajo={legajo} />}
    <label className="my-5 grid gap-2 text-xs font-semibold text-slate-600">Información a consultar<select value={categoria} onChange={event => setParams({ categoria: event.target.value })} className="rounded-xl border border-slate-200 bg-white p-3 text-sm">{opciones.map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>
    {consulta.error && <p role="alert" className="rounded-xl bg-rose-50 p-4 text-sm text-rose-800">No se pudo consultar este historial. <button type="button" onClick={consulta.retry} className="underline">Reintentar</button></p>}
    {!consulta.data && consulta.loading && <p role="status">Consultando antecedentes…</p>}
    {consulta.data && <section className="space-y-3">
      {!consulta.data.data.length && <p className="rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-500">No hay registros para esta consulta.</p>}
      {consulta.data.data.map(record => {
        const values = record.values
        const numero = ficha?.legajos.find(item => item.id === values.legajo_id)?.values.numero_legajo
        return <details key={record.id} className="rounded-xl border border-slate-200 bg-white p-5">
          <summary className="cursor-pointer text-sm font-semibold text-slate-800">{values.nombre_puesto || values.numero_resolucion || values.tipo || opciones.find(([key]) => key === categoria)?.[1]} · {numero || (etapa ? etapa.values.numero_legajo : 'Etapa sin identificar')}{values.fecha_cese ? ` · cese ${fecha(values.fecha_cese)}` : ''}</summary>
          <dl className="mt-4 grid gap-4 sm:grid-cols-2">{Object.entries(values).filter(([key]) => !key.endsWith('_id') && !['id', 'legajo_id'].includes(key)).map(([key, value]) => <div key={key}><dt className="text-xs text-slate-500">{key.replaceAll('_', ' ')}</dt><dd className="mt-1 whitespace-pre-wrap break-words text-sm text-slate-800">{texto(value)}</dd></div>)}</dl>
        </details>
      })}
      <Paginacion etiqueta="Paginación del historial" sustantivo="registros" desde={consulta.data.from || 0} hasta={consulta.data.to || 0} total={consulta.data.total} pagina={consulta.data.current_page} paginas={consulta.data.last_page} porPagina={porPagina} ocupado={consulta.loading}
        onPagina={numero => setParams(conPagina(params, numero))} onPorPagina={numero => setParams(conPorPagina(params, numero))} />
    </section>}
  </>
}
