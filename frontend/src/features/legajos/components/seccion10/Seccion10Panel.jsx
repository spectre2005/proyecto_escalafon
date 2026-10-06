import { useState } from 'react'
import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { usePuede } from '../../../auth/session'
import { anularSeccion10 } from '../../api/seccion10Api'
import { bloquesSeccion10, categoriasSeccion10, estadosTramite, permisosSeccion10, tiposAfiliacionSalud, tiposRegistroSst, tramites } from '../../config/seccion10'
import { coloresEstadoDocumento, estadosDocumento } from '../../config/seccion02'
import { etiqueta } from '../../config/seccion01'
import { folios } from '../../config/documentos'
import ListaDocumentos from '../documentos/ListaDocumentos'
import SubirDocumentoForm from '../documentos/SubirDocumentoForm'
import VisorPdf from '../documentos/VisorPdf'
import AbrirLegajo from '../AbrirLegajo'
import Registros from '../seccion07/Registros'
import { AfiliacionSaludForm, RegistroSstForm } from './Formularios'

const fecha = value => value ? value.split('-').reverse().join('/') : 'No registrada'
const texto = value => value === null || value === undefined || value === '' ? 'No registrado' : String(value)
const enlace = 'text-sm font-semibold text-teal-700 disabled:opacity-50'

function Aviso({ children, accion }) {
  return <div role="note" className="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><div>{children}</div>{accion}</div>
}

function Etiqueta({ children, clase }) {
  return <span className={`rounded-full px-2.5 py-1 text-[11px] font-medium ${clase}`}>{children}</span>
}

const etiquetaRegistro = r => `${etiqueta(tiposRegistroSst, r.values.tipo)} · ${fecha(r.values.fecha)}`
const etiquetaAfiliacion = a => `${etiqueta(tiposAfiliacionSalud, a.values.tipo)} · ${a.values.entidad} · desde ${fecha(a.values.fecha_inicio)}`

// Registros vigentes, sin documento, que un documento de este tipo puede acreditar (cada tipo solo los suyos).
function enlacesDe(tipo, data) {
  const sinDocumento = lista => lista.filter(record => !record.values.documento_id && tipo.registros?.includes(record.values.tipo))
  if (tipo.formulario_datos === 'registros_sst_bienestar') return sinDocumento(data.registros).map(r => ({ id: r.id, etiqueta: etiquetaRegistro(r) }))
  if (tipo.formulario_datos === 'afiliaciones') return sinDocumento(data.afiliaciones).map(a => ({ id: a.id, etiqueta: etiquetaAfiliacion(a) }))
  return null
}

function enlazadoA(tipo, documento, data) {
  const lista = tipo.formulario_datos === 'registros_sst_bienestar' ? data.registros : tipo.formulario_datos === 'afiliaciones' ? data.afiliaciones : null
  if (!lista) return null
  const record = lista.find(item => item.values.documento_id === documento.id)
  if (!record) return 'Sin registro enlazado (documento adicional)'
  return tipo.formulario_datos === 'afiliaciones' ? etiquetaAfiliacion(record) : etiquetaRegistro(record)
}

// Formulario que se abre al subir un documento con formulario de datos; el tipo del registro lo fija el documento.
function FormularioEnlazado({ usuario, tipo, documentoId, onDone }) {
  const props = { usuario, documentoId, onSaved: () => onDone(true), onCancel: () => onDone(false) }
  if (tipo.formulario_datos === 'registros_sst_bienestar') return <RegistroSstForm permitidos={tipo.registros} salud={tipo.es_dato_salud} {...props} />
  if (tipo.formulario_datos === 'afiliaciones') return <AfiliacionSaludForm {...props} />
  return null
}

// Documentos de un tipo: estado, lista (recepción y visor comunes) y carga del PDF con sus metadatos.
function Documentos({ usuario, data, tipo, sinLegajo }) {
  const puedeSubir = usePuede('documento.subir')
  const [subiendo, setSubiendo] = useState(false)
  const [enlazando, setEnlazando] = useState(null)
  const [aviso, setAviso] = useState('')
  if (!tipo) return <Aviso>El tipo de documento de este bloque no está cargado en la base de datos (Seccion10CatalogoSeeder).</Aviso>
  function subido(documento) {
    setSubiendo(false)
    const abre = documento.formulario_datos && !documento.enlazado
    setAviso(`Documento cargado (folios ${folios(documento.folio_inicio, documento.folio_fin)}).${abre ? ' Completa los datos que acredita o cancela si es un documento adicional.' : ''}`)
    if (abre) setEnlazando(documento.id)
  }
  function enlazado(guardado) {
    setEnlazando(null)
    setAviso(guardado ? 'Datos guardados y enlazados al documento.' : 'El documento quedó cargado sin registro de datos enlazado.')
  }
  return <div className="mt-4 rounded-2xl border border-slate-200 bg-white p-4">
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium text-slate-800">Documentos: {tipo.nombre}</p>
        <p className="mt-1 text-xs text-slate-500">{tipo.descripcion ? `${tipo.descripcion} ` : ''}Opcional; admite varios documentos.{tipo.formulario_datos ? '' : ' Solo metadatos (sin formulario de datos).'}{sinLegajo && puedeSubir ? ` ${sinLegajo}` : ''}</p>
      </div>
      <div className="flex shrink-0 flex-wrap items-center gap-2">
        {tipo.es_dato_salud && <Etiqueta clase="bg-rose-50 text-rose-700">Dato de salud</Etiqueta>}
        <Etiqueta clase={coloresEstadoDocumento[tipo.estado] || 'bg-slate-100 text-slate-700'}>{etiqueta(estadosDocumento, tipo.estado)}{tipo.documentos > 1 ? ` · ${tipo.documentos} documentos` : ''}</Etiqueta>
        {puedeSubir && <button disabled={subiendo || !!enlazando || !!sinLegajo} title={sinLegajo || undefined} onClick={() => { setAviso(''); setSubiendo(true) }} className={enlace}>Subir documento</button>}
      </div>
    </div>
    {aviso && <p role="status" className="mt-3 rounded-lg bg-teal-50 p-3 text-sm text-teal-800">{aviso}</p>}
    <ListaDocumentos usuario={usuario} tipo={tipo} enlazado={documento => enlazadoA(tipo, documento, data)} />
    {subiendo && <div className="mt-4"><SubirDocumentoForm usuario={usuario} seccion={10} tipo={tipo} titular={data.titular} enlaces={enlacesDe(tipo, data)} onSaved={subido} onCancel={() => setSubiendo(false)} /></div>}
    {enlazando && <div className="mt-4"><FormularioEnlazado usuario={usuario} tipo={tipo} documentoId={enlazando} onDone={enlazado} /></div>}
  </div>
}

// 10.2 c) Descansos médicos: consulta de los registros de la sección 05, con su documento. No se registran aquí.
function DescansosMedicos({ usuario, data, onNavigate }) {
  const puedeVer = usePuede('legajo.ver')
  const [visor, setVisor] = useState(null)
  const descansos = data.descansos_medicos
  return <div>
    <p className="mb-4 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">Consulta de solo lectura. El descanso médico se registra, documenta y anula en la sección 05 (Movimientos del personal); aquí no se duplica el registro ni el PDF. Dónde se archiva físicamente está pendiente de definir con la universidad.</p>
    {descansos.length === 0 ? <p className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">No hay descansos médicos registrados en la sección 05.</p>
      : <div className="space-y-3">{descansos.map(d => <article key={d.id} className="rounded-2xl border border-slate-200 bg-white p-4">
        <div className="flex flex-wrap items-start justify-between gap-2"><h4 className="text-sm font-semibold text-slate-800">Del {fecha(d.values.fecha_inicio)} al {fecha(d.values.fecha_fin)} · {d.values.dias} día(s)</h4><Etiqueta clase="bg-rose-50 text-rose-700">Dato de salud</Etiqueta></div>
        <dl className="mt-2 grid gap-2 text-xs text-slate-600 sm:grid-cols-3">
          <div><dt className="text-slate-400">N.º de documento</dt><dd>{texto(d.values.numero_documento)}</dd></div>
          <div><dt className="text-slate-400">Con goce</dt><dd>{d.values.con_goce ? 'Sí' : 'No'}</dd></div>
          <div><dt className="text-slate-400">Documento (sección 05)</dt><dd>{d.documento ? `Folios ${folios(d.documento.folio_inicio, d.documento.folio_fin)} · ${etiqueta(estadosDocumento, d.documento.estado_visible)}` : 'Sin documento enlazado'}</dd></div>
        </dl>
        {puedeVer && d.documento?.tiene_archivo && <button type="button" onClick={() => setVisor(visor === d.id ? null : d.id)} className="mt-2 text-xs font-semibold text-teal-700">{visor === d.id ? 'Ocultar PDF' : 'Ver PDF'}</button>}
        {visor === d.id && <VisorPdf usuario={usuario} documento={d.documento} titulo={`Descanso médico · folios ${folios(d.documento.folio_inicio, d.documento.folio_fin)}`} onClose={() => setVisor(null)} />}
      </article>)}</div>}
    <button onClick={() => onNavigate('s05-licencias')} className="mt-4 text-xs font-semibold text-teal-700 hover:underline">Ir a la sección 05 · Licencias, permisos y suspensiones</button>
  </div>
}

function Bloque({ usuario, data, bloque, sinLegajo, onNavigate }) {
  const tipo = data.tipos.find(t => t.codigo === bloque.documento)
  const titulo = `${bloque.literal ? `${bloque.literal}) ` : ''}${bloque.titulo}`
  return <section aria-label={bloque.titulo} className="mb-10 border-b border-slate-100 pb-8 last:border-b-0">
    {bloque.consulta ? <><h3 className="mb-4 text-base font-semibold text-slate-800">{titulo}</h3><DescansosMedicos usuario={usuario} data={data} onNavigate={onNavigate} /></>
      : bloque.afiliacion ? <Registros titulo={titulo} agregar="Registrar afiliación" registros={data.afiliaciones} bloqueo={sinLegajo} permisos={permisosSeccion10}
        vacio="No hay afiliaciones a EPS o Seguro Social registradas."
        tituloRegistro={a => `${etiqueta(tiposAfiliacionSalud, a.values.tipo)} · ${a.values.entidad}`} subtitulo={a => `Desde ${fecha(a.values.fecha_inicio)}${a.values.fecha_fin ? ` hasta ${fecha(a.values.fecha_fin)}` : ''}`}
        campos={[['N.º de afiliación', v => texto(v.numero_afiliacion)]]}
        onAnular={(record, motivo) => anularSeccion10(usuario, 'afiliaciones', record.id, motivo)}
        renderForm={props => <AfiliacionSaludForm key={props.record?.id || 'nuevo'} usuario={usuario} {...props} />} />
        : <Registros titulo={titulo} agregar="Registrar" registros={data.registros.filter(r => bloque.registros.includes(r.values.tipo))} bloqueo={sinLegajo} permisos={permisosSeccion10}
          vacio="No hay registros. No todas las personas los tienen."
          aviso={bloque.salud && <p className="mb-4 rounded-lg bg-rose-50 p-3 text-xs text-rose-800">Dato de salud: se registra solo el hecho, la fecha y una descripción breve, sin diagnósticos ni resultados médicos. Los otros incidentes del párrafo general aún no tienen tipo en el sistema (pendiente de validar).</p>}
          tituloRegistro={etiquetaRegistro} subtitulo={r => tramites.includes(r.values.tipo) ? `Estado: ${etiqueta(estadosTramite, r.values.estado_tramite)}` : null}
          campos={[['Descripción', v => texto(v.descripcion)]]}
          onAnular={(record, motivo) => anularSeccion10(usuario, 'registros', record.id, motivo)}
          renderForm={props => <RegistroSstForm key={props.record?.id || 'nuevo'} usuario={usuario} permitidos={bloque.registros} salud={bloque.salud} {...props} />} />}
    {bloque.documento && <Documentos usuario={usuario} data={data} tipo={tipo} sinLegajo={sinLegajo} />}
  </section>
}

export default function Seccion10Panel({ usuario, categoria, onNavigate }) {
  const request = useLiveQuery(`/expedientes/${usuario}/seccion-10`)
  const data = request.data?.data
  const { subseccion, titulo } = categoriasSeccion10[categoria]
  const error = request.error && <p role="alert" className="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{request.error.response?.status === 403 ? 'No tienes permiso para ver la sección 10 (datos sensibles y de salud).' : 'No se pudo actualizar la sección. Lo que ves puede no estar al día.'} <button onClick={request.retry} className="font-semibold underline">Reintentar</button></p>
  if (!data) return error || <p role="status" className="rounded-xl bg-slate-100 p-6 text-sm text-slate-500">Cargando…</p>
  const sinLegajo = !data.legajo && 'La persona no tiene un legajo abierto.'
  const otros = data.tipos.find(t => t.codigo === 'S10_OTROS_UNIVERSIDAD')

  return <section aria-label={titulo}>
    {error}
    <h2 className="mb-1 text-xl font-semibold text-slate-800">{titulo}</h2>
    <p className="mb-6 text-xs text-slate-500">Sección 10 · Seguridad y Salud en el Trabajo y Bienestar Social (Anexo 01). Información sensible: solo con acceso a datos sensibles. Ningún documento es obligatorio: se incorpora solo si existe.</p>
    {sinLegajo && <Aviso accion={<AbrirLegajo usuario={usuario} />}>{sinLegajo} Los formularios y la carga de documentos de la sección 10 están deshabilitados hasta abrirlo.</Aviso>}
    {subseccion ? bloquesSeccion10[subseccion].map(bloque => <Bloque key={bloque.key} usuario={usuario} data={data} bloque={bloque} sinLegajo={sinLegajo} onNavigate={onNavigate} />)
      : <Documentos usuario={usuario} data={data} tipo={otros} sinLegajo={sinLegajo} />}
  </section>
}
