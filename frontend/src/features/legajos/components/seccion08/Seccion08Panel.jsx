import { useState } from 'react'
import { apiErrorMessage } from '../../../../axios'
import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { usePuede } from '../../../auth/session'
import AbrirLegajo from '../AbrirLegajo'
import EspacioSeccion from '../documentos/EspacioSeccion'
import TablaTiposDocumentales from '../documentos/TablaTiposDocumentales'
import { tituloSeccion } from '../../config/espacioSeccion'
import { vistaDeItem } from '../../config/vistasDocumentales'
import VisorPdf from '../documentos/VisorPdf'
import Revision03Form from '../seccion03/Revision03Form'
import { revisarDocumento08 } from '../../api/seccion08Api'
import { useNoAplicables } from '../../api/useNoAplicables'
import { ofrecerTipo } from '../../config/aplicacion'
import { folios } from '../../config/documentos'
import { estados08, resultadoRecurso08, tipoSancion08 } from '../../config/seccion08'
import Documento08Form from './Documento08Form'

const boton = 'rounded-xl border border-teal-200 bg-teal-50 px-3 py-2 text-xs font-semibold text-teal-800 hover:bg-teal-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 disabled:opacity-50'
const fecha = value => value ? value.split('-').reverse().join('/') : 'No registrada'

function Estado({ estado }) {
  const [texto, clase] = estados08[estado] || estados08.opcional
  return <span className={`inline-block rounded-full px-2.5 py-1 text-xs font-medium ${clase}`}>{texto}</span>
}

function fechaEvento(documento) {
  return documento.fecha_emision ? fecha(documento.fecha_emision) : 'Fecha no registrada'
}

export default function Seccion08Panel({ usuario, categoria }) {
  const request = useLiveQuery(`/expedientes/${usuario}/seccion-08`)
  const data = request.data?.data
  const puedeSubir = usePuede('documento.subir')
  const noAplica = useNoAplicables(usuario)
  const puedeRegistrarSanciones = usePuede('seccion08.sanciones.registrar')
  const puedeRevisarDocumentos = usePuede('documento.revisar')
  const puedeRevisarSanciones = usePuede('seccion08.sanciones.revisar')
  const tienePermisoVerSanciones = usePuede('seccion08.sanciones.ver')
  const puedeAnular = usePuede('sistema.administrar')
  const [formulario, setFormulario] = useState(null)
  const [visor, setVisor] = useState(null)
  const [aviso, setAviso] = useState('')
  function cerrar(mensaje = '') {
    const origen = mensaje ? `item-${formulario?.item}` : formulario?.origen
    setFormulario(null)
    if (mensaje) { setAviso(mensaje); request.retry() }
    setTimeout(() => document.getElementById(origen)?.focus({ preventScroll: true }), 0)
  }
  const error = request.error && <p role="alert" className="mb-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-800">{request.error.response?.status === 403 ? 'No tienes permiso para ver esta sección.' : apiErrorMessage(request.error)} <button className="font-semibold underline" onClick={request.retry}>Reintentar</button></p>
  if (!data) return error || <p role="status" className="p-6 text-sm text-slate-500">Cargando {categoria === 'sanciones' ? 'sanciones disciplinarias…' : 'reconocimientos…'}</p>

  const grupoActual = categoria === 'sanciones' ? 'sanciones' : 'reconocimientos'
  const titulo = grupoActual === 'sanciones' ? 'Sanciones disciplinarias' : 'Reconocimientos'
  const items = data.grupos[grupoActual]
  const documentos = items?.flatMap(item => item.documentos) || []
  const documentosVigentes = documentos.filter(doc => !doc.subsanado && doc.estado !== 'anulado')
  const listaTiempo = (data.linea_tiempo || []).filter(doc => doc.grupo === grupoActual)
  const resumen = {
    registrados: grupoActual === 'sanciones' ? data.resumen.sanciones || 0 : items?.reduce((total, item) => total + item.cantidad, 0) || 0,
    vigentes: data.resumen.vigentes || 0,
    dejadasSinEfecto: data.resumen.dejadas_sin_efecto || 0,
    pendientes: documentosVigentes.filter(doc => doc.estado === 'pendiente').length,
    observados: documentosVigentes.filter(doc => doc.estado === 'observado').length,
  }
  return <EspacioSeccion seccion={tituloSeccion('08')} subseccion={titulo} etiquetaResumen="Ver resumen"
    cifras={items === null ? undefined : `${resumen.registrados} registrado(s) · ${resumen.pendientes} en revisión · ${resumen.observados} observado(s).`}
    resumen={<div className="space-y-3"><p className="text-slate-500">{grupoActual === 'sanciones' ? 'Documentos de sanciones disciplinarias y recursos impugnatorios, incluido el dato disponible sobre el RNSSC.' : 'Documentos que acreditan reconocimientos al mérito y por tiempo de servicios.'}</p>{items !== null && <>
      <div className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-5 sm:grid-cols-2 xl:grid-cols-4">
        <div><p className="text-xs text-slate-500">{grupoActual === 'sanciones' ? 'Sanciones registradas' : 'Reconocimientos registrados'}</p><p className="mt-1 text-2xl font-bold text-slate-800">{resumen.registrados}</p></div>
        {grupoActual === 'sanciones' && data.puede_ver_sanciones && <div><p className="text-xs text-slate-500">Vigentes / dejadas sin efecto</p><p className="mt-1 text-2xl font-bold text-slate-800">{resumen.vigentes} / {resumen.dejadasSinEfecto}</p></div>}
        <div><p className="text-xs text-slate-500">En revisión</p><p className="mt-1 text-2xl font-bold text-slate-800">{resumen.pendientes}</p></div>
        <div><p className="text-xs text-slate-500">Observados</p><p className="mt-1 text-2xl font-bold text-slate-800">{resumen.observados}</p></div>
        <div className="flex flex-wrap content-center gap-2 text-xs sm:col-span-2 xl:col-span-4">{data.perfil.tipos_personal.map(tipo => <span key={tipo} className="rounded-full bg-slate-100 px-3 py-1.5">{tipo === 'docente' ? 'Docente' : 'Administrativo'}</span>)}{(data.perfil.puestos.length ? data.perfil.puestos : ['Puesto no registrado']).map(puesto => <span key={puesto} className="max-w-full break-words rounded-full bg-slate-100 px-3 py-1.5">{puesto}</span>)}</div>
      </div>
    </>}</div>}>
    <div className="min-w-0 space-y-5">
    {error}
    {!data.legajo && <div className="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700"><p className="mb-3">Primero abre el legajo para agregar documentos.</p><AbrirLegajo usuario={usuario} /></div>}
    {aviso && <p role="status" className="rounded-xl bg-teal-50 p-4 text-sm text-teal-800">{aviso}</p>}
    {items === null ? <div role="alert" className="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">No tienes permiso para ver la documentación de sanciones disciplinarias.</div> : <>
      <section className="space-y-3" aria-label={titulo}>
        {/* Documentos por vista: Pendientes · Con documentos · Opcionales · No corresponde (cada ítem conserva su formulario). */}
        <TablaTiposDocumentales elementos={items} idDe={item => item.codigo}
          clasificar={item => vistaDeItem(item, ofrecerTipo(noAplica, item))} estado={item => <Estado estado={item.estado} />}
          puedeSubir={item => !!data.legajo && (grupoActual === 'sanciones' ? puedeRegistrarSanciones : puedeSubir) && ofrecerTipo(noAplica, item)}
          seleccionado={formulario?.item}
      onSubir={item => { setAviso(''); setFormulario({ item: item.codigo, origen: `item-${item.codigo}` }) }}>
          {item => (
          <div className="space-y-4 rounded-2xl border border-slate-200 bg-white p-5">
          {item.motivo && <p className="text-xs leading-relaxed text-slate-500">{item.motivo}{item.requiere_fedateo && ' Requiere fedateo.'}</p>}
            {item.documentos.length === 0 && <p className="text-sm text-slate-500">{item.estado === 'no_aplica' ? 'No aplica al tipo de personal registrado.' : 'No hay documentos registrados. Este ítem es opcional; agrega un documento si corresponde.'}</p>}
            {item.documentos.map(doc => <article key={doc.id} className="min-w-0 rounded-xl border border-slate-200 p-4">
              <div className="flex flex-wrap items-start justify-between gap-2"><h5 className="min-w-0 break-words text-sm font-semibold text-slate-800">{doc.nombre}</h5><Estado estado={doc.estado} /></div>
              <p className="mt-2 break-words text-xs text-slate-600">{doc.emisor || 'Emisor no registrado'} · Emitido el {fecha(doc.fecha_emision)}{doc.num_resolucion && ` · Resolución ${doc.num_resolucion}`}</p>
              {doc.motivo && <p className="mt-2 text-xs text-slate-600">Motivo: {doc.motivo}</p>}
              {doc.tipo_sancion && <p className="mt-2 text-xs text-slate-600">{tipoSancion08[doc.tipo_sancion] || doc.tipo_sancion}{doc.dias_suspension ? ` · ${doc.dias_suspension} día(s) de suspensión` : ''} · Estado derivado: {doc.estado_sancion === 'dejada_sin_efecto' ? 'Dejada sin efecto' : doc.estado_sancion === 'vigente' ? 'Vigente' : 'Por determinar: hay resultados distintos en los recursos'} · RNSSC: {doc.registrada_en_rnssc ? 'Sí' : 'No'}</p>}
              {doc.tipo_sancion && doc.referencia_procedimiento && <p className="mt-2 break-words text-xs text-slate-600">Referencia del expediente o procedimiento: {doc.referencia_procedimiento}</p>}
              {doc.tiempo_servicio_reconocido && <p className="mt-2 text-xs text-slate-600">Tiempo reconocido en el documento: {doc.tiempo_servicio_reconocido.anios} años, {doc.tiempo_servicio_reconocido.meses} meses y {doc.tiempo_servicio_reconocido.dias} días. Experiencia calculada: {data.tiempo_experiencia_calculada.anios} años, {data.tiempo_experiencia_calculada.meses} meses y {data.tiempo_experiencia_calculada.dias} días.{Object.keys(data.tiempo_experiencia_calculada).some(key => doc.tiempo_servicio_reconocido[key] !== data.tiempo_experiencia_calculada[key]) && <strong className="ml-1 text-slate-600">Los valores difieren; esta comparación es informativa.</strong>}</p>}
              {doc.tipo_sancion && doc.registrada_en_rnssc && (doc.fecha_registro_rnssc || doc.referencia_rnssc) && <p className="mt-2 break-words text-xs text-slate-600">RNSSC: {doc.fecha_registro_rnssc ? `registrada el ${fecha(doc.fecha_registro_rnssc)}` : ''}{doc.referencia_rnssc ? ` · ${doc.referencia_rnssc}` : ''}</p>}
              <div className="mt-2 flex flex-wrap gap-x-4 gap-y-2 text-xs text-slate-500"><span>Folios {folios(doc.folio_inicio, doc.folio_fin)}</span><span>{doc.fedateado ? 'Fedateado' : 'Sin fedatear'}</span><span>{doc.archivo ? `PDF · antivirus: ${doc.archivo.estado_antivirus}` : 'Sin archivo'}</span><span>Recepción: {fecha(doc.fecha_recepcion)}</span></div>
              {doc.recursos?.map(recurso => <p key={recurso.id} className="mt-2 rounded-lg bg-slate-50 p-3 text-xs text-slate-700">Recurso del {fecha(recurso.fecha)} · {resultadoRecurso08[recurso.resultado] || recurso.resultado} · {recurso.nombre_documento || 'Documento asociado'}</p>)}
              {doc.observaciones.map((obs, index) => <p key={index} className="mt-3 break-words rounded-lg bg-rose-50 p-3 text-xs text-rose-800"><strong>Observación:</strong> {obs.descripcion} · Plazo: {fecha(obs.plazo_subsanacion)}</p>)}
              <div className="mt-3 flex flex-wrap gap-2">
                {doc.archivo && doc.archivo.estado_antivirus !== 'infectado' && (doc.grupo !== 'sanciones' || tienePermisoVerSanciones || puedeRevisarSanciones) && <button className={boton} onClick={() => setVisor(visor?.id === doc.id ? null : doc)}>Ver PDF</button>}
                {/* Subsanar un documento vigente también si su tipo ya no se ofrece (lo admite el backend); propone sus datos. */}
                {data.legajo && (grupoActual === 'sanciones' ? puedeRegistrarSanciones : puedeSubir) && !doc.subsanado && doc.estado !== 'anulado' && !formulario && <button id={`subsanar-${doc.id}`} className={boton} onClick={() => { setAviso(''); setFormulario({ item: item.codigo, subsana: doc.id, origen: `subsanar-${doc.id}` }) }}>Subsanar</button>}
                {(doc.grupo === 'sanciones' ? puedeRevisarSanciones : puedeRevisarDocumentos) && doc.puede_revisar && doc.estado !== 'aceptado' && <button className={boton} onClick={() => setFormulario({ item: item.codigo, documento: doc, estado: 'aceptado', origen: `item-${item.codigo}` })}>Aceptar</button>}
                {(doc.grupo === 'sanciones' ? puedeRevisarSanciones : puedeRevisarDocumentos) && doc.puede_revisar && doc.estado !== 'observado' && <button className={boton} onClick={() => setFormulario({ item: item.codigo, documento: doc, estado: 'observado', origen: `item-${item.codigo}` })}>Observar</button>}
                {puedeAnular && doc.estado !== 'anulado' && <button className={`${boton} border-rose-200 bg-rose-50 text-rose-800 hover:bg-rose-100`} onClick={() => setFormulario({ item: item.codigo, documento: doc, estado: 'anulado', origen: `item-${item.codigo}` })}>Anular</button>}
              </div>
              {visor?.id === doc.id && <VisorPdf usuario={usuario} documento={doc} titulo={doc.nombre} onClose={() => setVisor(null)} />}
            </article>)}
            {/* Subsanar no depende de que el tipo aplique (lo admite el backend); la carga nueva sí (ofrecerTipo). */}

          {data.legajo && (grupoActual === 'sanciones' ? puedeRegistrarSanciones : puedeSubir) && ofrecerTipo(noAplica, item) && <button id={`agregar-${item.codigo}`} className={boton} onClick={() => { setAviso(''); setFormulario({ item: item.codigo, origen: `agregar-${item.codigo}` }) }}>Subir documento</button>}
          </div>
          )}
        </TablaTiposDocumentales>
    {(items || []).filter(item => item.codigo === formulario?.item).map(item => <div key={item.codigo}>
      {formulario.documento ? item.documentos.filter(doc => formulario.documento.id === doc.id).map(doc => <div key={doc.id}><div className="mt-4"><Revision03Form key={`${doc.id}:${formulario.estado}`} usuario={usuario} documento={doc} item={item} estado={formulario.estado} revisar={revisarDocumento08} fechaActual={data.fecha_actual} onCancel={() => cerrar()} onSaved={() => cerrar(formulario.estado === 'aceptado' ? 'Documento aceptado.' : formulario.estado === 'anulado' ? 'Documento anulado; se conserva en el historial.' : 'Observación registrada. La corrección se presenta con un documento nuevo.')} nota="La revisión queda en el historial. Los documentos originales se conservan sin editar ni eliminar." /></div></div>) : <Documento08Form usuario={usuario} item={item} datos={data} subsana={formulario.subsana || ''} soloSubsanar={!ofrecerTipo(noAplica, item)} onCancel={() => cerrar()} onSaved={() => cerrar('Documento guardado correctamente.')} />}
    </div>)}
      </section>
      <section className="rounded-2xl border border-slate-200 bg-white p-5" aria-label={`Línea de tiempo de ${titulo.toLowerCase()}`}>
        <h3 className="font-semibold text-slate-800">Línea de tiempo</h3>
        {listaTiempo.length === 0 ? <p className="mt-2 text-sm text-slate-500">Todavía no hay documentos registrados.</p> : <ol className="mt-4 space-y-3">{[...listaTiempo].reverse().map(doc => <li key={doc.id} className="flex min-w-0 gap-3 border-l-2 border-teal-200 pl-4"><span aria-hidden="true" className={`mt-1 size-2.5 shrink-0 rounded-full ${doc.grupo === 'sanciones' ? 'bg-rose-600' : 'bg-teal-700'}`} /><div className="min-w-0"><p className="break-words text-sm font-medium text-slate-800">{doc.nombre}</p><p className="mt-1 text-xs text-slate-500">{fechaEvento(doc)} · {doc.grupo === 'sanciones' ? 'Sanción disciplinaria' : 'Reconocimiento'} · Folios {folios(doc.folio_inicio, doc.folio_fin)}</p></div></li>)}</ol>}
      </section>
      <p className="text-sm font-semibold text-slate-800">Número de folios: {data.numero_folios}</p>
      <details className="rounded-xl border border-slate-200 bg-white p-4 text-xs text-slate-500"><summary className="cursor-pointer font-semibold text-slate-600">Cómo se conservan los documentos</summary><p className="mt-2 leading-relaxed">Los folios se asignan correlativamente. Los documentos registrados se conservan sin editar ni eliminar.</p></details>
    </>}
    </div>
  </EspacioSeccion>
}
