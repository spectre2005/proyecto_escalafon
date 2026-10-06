import { useState } from 'react'
import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { usePuede } from '../../../auth/session'
import { anularSeccion07 } from '../../api/seccion07Api'
import { categoriasDocente, formulariosDatos07, resultadosRatificacion, rutaFormulario, subseccionesSeccion07, tiposDesplazamiento, tiposRatificacion } from '../../config/seccion07'
import { coloresEstadoDocumento, estadosDocumento, estadosSubseccion } from '../../config/seccion02'
import { etiqueta } from '../../config/seccion01'
import { folios } from '../../config/documentos'
import ListaDocumentos from '../documentos/ListaDocumentos'
import SubirDocumentoForm from '../documentos/SubirDocumentoForm'
import AbrirLegajo from '../AbrirLegajo'
import { DesplazamientoForm, EvaluacionForm, ProgresionForm, RatificacionForm } from './Formularios'
import Registros from './Registros'

const fecha = value => value ? value.split('-').reverse().join('/') : 'No registrada'
const texto = value => value === null || value === undefined || value === '' ? 'No registrado' : String(value).replaceAll('_', ' ')
const enlace = 'text-sm font-semibold text-teal-700 disabled:opacity-50'
const categoriaDe = Object.fromEntries(Object.entries(subseccionesSeccion07).map(([key, item]) => [item.codigo, key]))

const exigencia = {
  true: ['Se exige', 'bg-teal-50 text-teal-800'],
  false: ['No se exige', 'bg-slate-100 text-slate-600'],
  null: ['Por determinar', 'bg-amber-50 text-amber-800'],
}

function Aviso({ tono = 'amber', children, accion }) {
  const clases = { amber: 'border-amber-200 bg-amber-50 text-amber-900', rose: 'border-rose-200 bg-rose-50 text-rose-800', slate: 'border-slate-200 bg-slate-50 text-slate-600' }[tono]
  return <div role="note" className={`mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4 text-sm ${clases}`}><div>{children}</div>{accion}</div>
}

function Etiqueta({ children, clase }) {
  return <span className={`rounded-full px-2.5 py-1 text-[11px] font-medium ${clase}`}>{children}</span>
}

function Reservado() {
  return <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] text-slate-500" title="Pendiente de definir con la institución quién puede ver calificaciones y puntajes.">Reservado</span>
}

// Resumen de la sección: pendientes, exigidos con documento cargado y con recepción, y estado de cada subsección.
// La fórmula de completitud del legajo la define el cliente: los números van por separado.
function Resumen({ data, onNavigate }) {
  const r = data.resumen
  return <section aria-label="Resumen de la sección 07" className="mb-8 rounded-2xl border border-teal-100 bg-white p-5">
    <div className="flex flex-wrap gap-x-10 gap-y-4">
      <div><p className="text-xs text-slate-500">Tipos de documento pendientes</p><p className="mt-1 text-2xl font-bold text-slate-800">{r.pendientes} <span className="text-sm font-normal text-slate-500">de {r.tipos}</span></p></div>
      <div><p className="text-xs text-slate-500">Exigidos con documento cargado</p><p className="mt-1 text-2xl font-bold text-slate-800">{r.exigidos_cargados} <span className="text-sm font-normal text-slate-500">de {r.exigidos.length}</span></p></div>
      <div><p className="text-xs text-slate-500">Exigidos con recepción registrada</p><p className="mt-1 text-2xl font-bold text-slate-800">{r.exigidos_recepcionados} <span className="text-sm font-normal text-slate-500">de {r.exigidos.length}</span></p></div>
    </div>
    <p className="mt-3 text-xs text-slate-500">{data.perfil.es_carrera ? '' : 'La subsección 7.2 no aplica (la persona no es servidor/a de carrera) y no se cuenta. '}Los documentos de progresión y de desplazamiento existen solo si hubo el movimiento: no se exigen. Cargados y recepcionados se muestran por separado: el criterio de completitud está pendiente de definir.</p>
    <div className="mt-4 grid gap-2 sm:grid-cols-3">{data.subsecciones.map(sub => {
      const [nombre, clase] = estadosSubseccion[sub.estado]
      return <button key={sub.codigo} onClick={() => onNavigate(categoriaDe[sub.codigo])} className={`rounded-xl border border-slate-200 p-3 text-left hover:bg-slate-50 ${sub.aplica ? '' : 'opacity-60'}`}>
        <span className="block text-xs font-semibold text-slate-700">{subseccionesSeccion07[categoriaDe[sub.codigo]]?.titulo || sub.nombre}</span>
        <span className="mt-2 flex flex-wrap items-center gap-2"><Etiqueta clase={clase}>{nombre}</Etiqueta>{sub.aplica && sub.exigidos > 0 && <span className="text-[11px] text-slate-500">{sub.exigidos_cargados}/{sub.exigidos} cargados · {sub.exigidos_recepcionados} recepcionados</span>}</span>
      </button>
    })}</div>
  </section>
}

// Avisos que no bloquean: evaluación del ciclo en curso, plan de mejora sin acta, ratificaciones por vencer o vencidas.
function Avisos({ data, codigo }) {
  const a = data.avisos
  const porVencer = data.ratificaciones.filter(r => r.alerta)
  return <>
    {codigo === '7.1' && !a.evaluacion_ciclo_en_curso && <Aviso>No hay evaluación de desempeño registrada para el ciclo {a.ciclo_en_curso}.</Aviso>}
    {codigo === '7.1' && a.planes_mejora_sin_acta > 0 && <Aviso>{a.planes_mejora_sin_acta} evaluación(es) con plan de mejora y ningún acta de retroalimentación (7.1 b) cargada.</Aviso>}
    {codigo === '7.2' && porVencer.map(r => <Aviso key={r.id} tono={r.alerta === 'vencida' ? 'rose' : 'amber'}>{r.alerta === 'vencida'
      ? `El período de la ${etiqueta(tiposRatificacion, r.values.tipo).toLowerCase()} ${r.values.numero_resolucion} venció el ${fecha(r.values.fecha_vencimiento_periodo)} y el resultado sigue pendiente.`
      : `El período de la ${etiqueta(tiposRatificacion, r.values.tipo).toLowerCase()} ${r.values.numero_resolucion} vence el ${fecha(r.values.fecha_vencimiento_periodo)} (en ${r.dias_para_vencer} día(s)). Se avisa con ${a.dias_aviso_ratificacion} días de anticipación.`}</Aviso>)}
  </>
}

const etiquetaEvaluacion = e => `Evaluación del ciclo ${e.values.ciclo_anio}`
const etiquetaProgresion = p => `Progresión ${p.values.nivel_anterior || 'sin nivel previo'} → ${p.values.nivel_nuevo} · ${p.values.numero_resolucion}`
const etiquetaRatificacion = r => `${etiqueta(tiposRatificacion, r.values.tipo)} · ${r.values.numero_resolucion} · ${fecha(r.values.fecha_resolucion)}`
const etiquetaDesplazamiento = d => `${etiqueta(tiposDesplazamiento, d.values.tipo)} · desde ${fecha(d.values.fecha_inicio)}${d.values.numero_documento ? ` · ${d.values.numero_documento}` : ''}`

// Registros existentes, sin documento, que un documento de este tipo puede acreditar.
function enlacesDe(tipo, data) {
  const sinDocumento = lista => lista.filter(record => !record.values.documento_id)
  if (tipo.formulario_datos === 'evaluaciones_desempeno') return sinDocumento(data.evaluaciones).map(e => ({ id: e.id, etiqueta: etiquetaEvaluacion(e) }))
  if (tipo.formulario_datos === 'progresiones_carrera') return [...sinDocumento(data.progresiones).map(p => ({ id: p.id, etiqueta: etiquetaProgresion(p) })), ...sinDocumento(data.ratificaciones).map(r => ({ id: r.id, etiqueta: etiquetaRatificacion(r) }))]
  if (tipo.formulario_datos === 'desplazamientos') return sinDocumento(data.desplazamientos).filter(d => !tipo.desplazamientos || tipo.desplazamientos.includes(d.values.tipo)).map(d => ({ id: d.id, etiqueta: etiquetaDesplazamiento(d) }))
  return null
}

// Registro que acredita un documento (el enlace lo guarda el backend al subir o al registrar el formulario).
function enlazadoA(tipo, documento, data) {
  const buscar = (lista, nombre) => {
    const record = lista.find(item => item.values.documento_id === documento.id)
    return record ? nombre(record) : null
  }
  if (tipo.formulario_datos === 'evaluaciones_desempeno') return buscar(data.evaluaciones, etiquetaEvaluacion) || 'Sin evaluación enlazada'
  if (tipo.formulario_datos === 'progresiones_carrera') return buscar(data.progresiones, etiquetaProgresion) || buscar(data.ratificaciones, etiquetaRatificacion) || 'Sin progresión ni ratificación enlazada'
  if (tipo.formulario_datos === 'desplazamientos') return buscar(data.desplazamientos, etiquetaDesplazamiento) || 'Sin desplazamiento enlazado'
  return null
}

// Formulario de datos que se abre al subir un documento cuyo tipo lo tiene. 7.2 a): progresión (administrativo de
// carrera) o ratificación (docente), según el perfil de la persona; si tiene ambos, se elige.
function FormularioEnlazado({ usuario, data, tipo, documentoId, onDone }) {
  const [eleccion, setEleccion] = useState(null)
  const props = { usuario, documentoId, onSaved: () => onDone(true), onCancel: () => onDone(false) }
  const { vinculos_carrera: vinculos, perfiles_docentes: perfiles, unidades } = data.opciones
  if (tipo.formulario_datos === 'evaluaciones_desempeno') return <EvaluacionForm veCalificacion={data.ve_calificacion} {...props} />
  if (tipo.formulario_datos === 'desplazamientos') return <DesplazamientoForm permitidos={tipo.desplazamientos} unidades={unidades} {...props} />
  if (tipo.formulario_datos !== 'progresiones_carrera') return null
  const opciones = [vinculos.length && 'progresiones_carrera', perfiles.length && 'ratificaciones_docentes'].filter(Boolean)
  const formulario = opciones.length === 1 ? opciones[0] : eleccion
  if (!opciones.length) return <Aviso accion={<button onClick={() => onDone(false)} className={enlace}>Entendido</button>}>El documento quedó cargado, pero la persona no tiene un vínculo administrativo de carrera ni un perfil docente para registrar sus datos. Revisa la sección 2.2.</Aviso>
  if (!formulario) return <Aviso tono="slate" accion={<div className="flex flex-wrap gap-3">{opciones.map(o => <button key={o} onClick={() => setEleccion(o)} className={enlace}>{formulariosDatos07[o]}</button>)}<button onClick={() => onDone(false)} className="text-sm text-slate-500">Más tarde</button></div>}>La persona es administrativa de carrera y docente: ¿qué acredita esta resolución?</Aviso>
  return formulario === 'progresiones_carrera' ? <ProgresionForm vinculos={vinculos} {...props} /> : <RatificacionForm perfiles={perfiles} {...props} />
}

// Tipos de documento de la subsección con su exigencia, estado y documentos; subida del PDF con sus metadatos.
function Documentos({ usuario, data, subseccion, sinLegajo, aplica }) {
  const puedeSubir = usePuede('documento.subir')
  const [subiendo, setSubiendo] = useState(null)
  const [enlazando, setEnlazando] = useState(null)
  const [aviso, setAviso] = useState('')
  if (!subseccion) return <Aviso>El catálogo de documentos de esta subsección no está cargado en la base de datos (Seccion07CatalogoSeeder).</Aviso>
  const ocupado = !!subiendo || !!enlazando
  function subido(tipo, documento) {
    setSubiendo(null)
    const abre = documento.formulario_datos && !documento.enlazado
    setAviso(`Documento cargado (folios ${folios(documento.folio_inicio, documento.folio_fin)}).${abre ? ' Completa los datos que acredita.' : ''}`)
    if (abre) setEnlazando({ tipo, documentoId: documento.id })
  }
  function enlazado(guardado) {
    setEnlazando(null)
    setAviso(guardado ? 'Datos guardados y enlazados al documento.' : 'El documento quedó cargado sin registro de datos enlazado.')
  }
  return <section aria-label="Documentos de la subsección" className={aplica ? '' : 'opacity-60'}>
    <h3 className="mb-1 text-base font-semibold text-slate-800">Documentos</h3>
    <p className="mb-4 text-xs text-slate-500">Cada documento se sube en PDF con sus metadatos. Los folios son correlativos en toda la sección.{sinLegajo && puedeSubir ? ` ${sinLegajo}` : ''}</p>
    {aviso && <p role="status" className="mb-4 rounded-lg bg-teal-50 p-3 text-sm text-teal-800">{aviso}</p>}
    <ul className="divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200 bg-white">{subseccion.tipos.map(tipo => {
      const [exigeTexto, exigeClase] = exigencia[String(aplica ? tipo.exigido : false)]
      const datos = tipo.formulario_datos === 'progresiones_carrera' ? 'progresión (administrativos) o ratificación (docentes)' : formulariosDatos07[tipo.formulario_datos]?.toLowerCase()
      return <li key={tipo.id} className="p-4">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0 flex-1">
            <p className="text-sm font-medium text-slate-800">{tipo.literal && <span className="mr-1 text-slate-500">{tipo.literal})</span>}{tipo.nombre}</p>
            <p className="mt-1 text-xs text-slate-500">{aplica ? tipo.motivo : 'No aplica a esta persona.'}{tipo.descripcion ? ` ${tipo.descripcion}` : ''}{tipo.permite_multiples ? ' Admite varios documentos.' : ''}{datos ? ` Datos: ${datos}.` : ' Solo metadatos (sin formulario de datos).'}</p>
            <ListaDocumentos usuario={usuario} tipo={tipo} enlazado={documento => enlazadoA(tipo, documento, data)} />
          </div>
          <div className="flex shrink-0 flex-wrap items-center gap-2">
            <Etiqueta clase={exigeClase}>{exigeTexto}</Etiqueta>
            <Etiqueta clase={coloresEstadoDocumento[tipo.estado] || 'bg-slate-100 text-slate-700'}>{etiqueta(estadosDocumento, tipo.estado)}{tipo.documentos > 1 ? ` · ${tipo.documentos} documentos` : ''}</Etiqueta>
            {puedeSubir && <button disabled={ocupado || !!sinLegajo || !aplica} title={!aplica ? 'No aplica a esta persona.' : sinLegajo || undefined} onClick={() => { setAviso(''); setSubiendo(tipo.id) }} className={enlace}>Subir documento</button>}
          </div>
        </div>
        {subiendo === tipo.id && <div className="mt-4"><SubirDocumentoForm usuario={usuario} seccion={7} tipo={tipo} titular={data.titular} enlaces={enlacesDe(tipo, data)} onSaved={documento => subido(tipo, documento)} onCancel={() => setSubiendo(null)} /></div>}
        {enlazando?.tipo.id === tipo.id && <div className="mt-4"><FormularioEnlazado usuario={usuario} data={data} tipo={enlazando.tipo} documentoId={enlazando.documentoId} onDone={enlazado} /></div>}
      </li>
    })}</ul>
  </section>
}

const anular = (usuario, formulario) => (record, motivo) => anularSeccion07(usuario, rutaFormulario[formulario], record.id, motivo)

function Desempeno({ usuario, data, bloqueo }) {
  return <Registros titulo="Evaluaciones de desempeño" agregar="Registrar evaluación" registros={data.evaluaciones} bloqueo={bloqueo}
    vacio={bloqueo ? `No hay evaluaciones registradas. ${bloqueo}` : 'No hay evaluaciones registradas. Se registran al subir el formato de gestión del rendimiento (7.1 a) o con «Registrar evaluación».'}
    tituloRegistro={etiquetaEvaluacion} subtitulo={e => e.values.fecha_evaluacion ? `Evaluada el ${fecha(e.values.fecha_evaluacion)}` : 'Sin fecha de evaluación'}
    campos={[
      ['Calificación', (v, r) => r.calificacion_oculta ? <Reservado /> : texto(v.calificacion)],
      ['Puntaje', (v, r) => r.calificacion_oculta ? <Reservado /> : texto(v.puntaje)],
      ['Plan de mejora', v => v.plan_mejora ? 'Sí' : 'No'],
      ['Evaluador', v => texto(v.evaluador)],
    ]}
    onAnular={anular(usuario, 'evaluaciones_desempeno')}
    renderForm={props => <EvaluacionForm key={props.record?.id || 'nuevo'} usuario={usuario} veCalificacion={data.ve_calificacion} {...props} />} />
}

function Progresion({ usuario, data, bloqueo }) {
  const { vinculos_carrera: vinculos, perfiles_docentes: perfiles } = data.opciones
  const sinCarrera = vinculos.length ? null : 'Sin vínculo administrativo de carrera (se registra en 2.2).'
  const sinDocente = perfiles.length ? null : 'Sin perfil docente (datos del puesto en 2.2).'
  return <>
    <Registros titulo="Progresiones en la carrera (administrativos)" agregar="Registrar progresión" registros={data.progresiones} bloqueo={bloqueo || sinCarrera}
      vacio="No hay progresiones registradas."
      tituloRegistro={etiquetaProgresion} subtitulo={p => `Fecha efectiva: ${fecha(p.values.fecha_efectiva)}`}
      campos={[['Vínculo laboral', v => vinculos.find(item => item.id === v.vinculo_laboral_id)?.etiqueta || 'Vínculo no vigente o no encontrado']]}
      onAnular={anular(usuario, 'progresiones_carrera')}
      renderForm={props => <ProgresionForm key={props.record?.id || 'nuevo'} usuario={usuario} vinculos={vinculos} {...props} />} />
    <Registros titulo="Ratificaciones y promociones docentes" agregar="Registrar ratificación" registros={data.ratificaciones} bloqueo={bloqueo || sinDocente}
      vacio="No hay ratificaciones ni promociones registradas."
      tituloRegistro={etiquetaRatificacion} subtitulo={r => `Resultado: ${etiqueta(resultadosRatificacion, r.values.resultado)}`}
      campos={[
        ['Categoría', v => `${v.categoria_anterior ? etiqueta(categoriasDocente, v.categoria_anterior) : 'Sin categoría previa'} → ${etiqueta(categoriasDocente, v.categoria_nueva)}`],
        ['Período', v => `${fecha(v.fecha_inicio_periodo)} – ${fecha(v.fecha_vencimiento_periodo)}`],
        ['Perfil docente', v => perfiles.find(item => item.id === v.perfil_docente_id)?.etiqueta || 'No encontrado'],
      ]}
      onAnular={anular(usuario, 'ratificaciones_docentes')}
      renderForm={props => <RatificacionForm key={props.record?.id || 'nuevo'} usuario={usuario} perfiles={perfiles} {...props} />} />
  </>
}

function Desplazamiento({ usuario, data, bloqueo }) {
  const unidades = data.opciones.unidades
  const unidad = id => unidades.find(u => u.id === id)?.nombre
  return <Registros titulo="Desplazamientos" agregar="Registrar desplazamiento" registros={data.desplazamientos} bloqueo={bloqueo}
    vacio={bloqueo ? `No hay desplazamientos registrados. ${bloqueo}` : 'No hay desplazamientos registrados. Se registran al subir su documento (b a f) o con «Registrar desplazamiento».'}
    tituloRegistro={etiquetaDesplazamiento} subtitulo={d => d.values.fecha_fin ? `Hasta ${fecha(d.values.fecha_fin)}` : 'Sin fecha de fin'}
    campos={[
      ['Unidad de origen', v => unidad(v.unidad_origen_id) || 'No registrada'],
      ['Unidad de destino', v => unidad(v.unidad_destino_id) || 'No registrada'],
      ['Puesto de destino', v => texto(v.puesto_destino)],
    ]}
    onAnular={anular(usuario, 'desplazamientos')}
    renderForm={props => <DesplazamientoForm key={props.record?.id || 'nuevo'} usuario={usuario} unidades={unidades} {...props} />} />
}

export default function Seccion07Panel({ usuario, categoria, onNavigate }) {
  const request = useLiveQuery(`/expedientes/${usuario}/seccion-07`)
  const data = request.data?.data
  const { codigo, titulo } = subseccionesSeccion07[categoria]
  const error = request.error && <p role="alert" className="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{request.error.response?.status === 403 ? 'No tienes permiso para ver la sección 07.' : 'No se pudo actualizar la sección. Lo que ves puede no estar al día.'} <button onClick={request.retry} className="font-semibold underline">Reintentar</button></p>
  if (!data) return error || <p role="status" className="rounded-xl bg-slate-100 p-6 text-sm text-slate-500">Cargando…</p>

  const subseccion = data.subsecciones.find(item => item.codigo === codigo)
  const sinLegajo = !data.legajo && 'La persona no tiene un legajo abierto.'
  const aplica = subseccion ? subseccion.aplica : true
  const bloqueo = sinLegajo || (!aplica && 'No aplica: la persona no es servidor/a de carrera.')

  return <section aria-label={titulo}>
    {error}
    <Resumen data={data} onNavigate={onNavigate} />
    <h2 className="mb-5 text-xl font-semibold text-slate-800">{titulo}</h2>
    {sinLegajo && <Aviso accion={<AbrirLegajo usuario={usuario} />}>{sinLegajo} Los formularios y la carga de documentos de la sección 07 están deshabilitados hasta abrirlo.</Aviso>}
    {!aplica && <section className="mb-6 rounded-2xl border border-slate-200 bg-slate-50 p-6 text-slate-500">
      <p className="text-sm font-semibold">No aplica: la persona no es servidor/a civil de carrera</p>
      <p className="mt-2 text-xs">La directiva reserva la progresión en la carrera al servidor/a civil de carrera. Se activa con un vínculo vigente de régimen «carrera» (sección 2.2). Los registros anteriores, si los hay, se muestran abajo atenuados.</p>
      <button onClick={() => onNavigate('s02-vinculo')} className="mt-4 text-xs font-semibold text-teal-700 hover:underline">Revisar los vínculos en 2.2</button>
    </section>}
    <Avisos data={data} codigo={codigo} />
    <div className={aplica ? '' : 'opacity-60'}>
      {codigo === '7.1' && <Desempeno usuario={usuario} data={data} bloqueo={bloqueo} />}
      {codigo === '7.2' && <Progresion usuario={usuario} data={data} bloqueo={bloqueo} />}
      {codigo === '7.3' && <Desplazamiento usuario={usuario} data={data} bloqueo={bloqueo} />}
    </div>
    <Documentos usuario={usuario} data={data} subseccion={subseccion} sinLegajo={sinLegajo} aplica={aplica} />
  </section>
}
