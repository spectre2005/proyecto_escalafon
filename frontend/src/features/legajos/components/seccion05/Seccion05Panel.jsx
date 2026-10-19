import Icon from '../../../../components/ui/Icon'
import { useState } from 'react'
import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { usePuede } from '../../../auth/session'
import { anularSeccion05 } from '../../api/seccion05Api'
import { useNoAplicables } from '../../api/useNoAplicables'
import { ofrecerTipo, tiposQueAcreditan } from '../../config/aplicacion'
import { precargaDesdeRegistro } from '../../config/acreditacion'
import { folios } from '../../config/documentos'
import { etiqueta } from '../../config/seccion01'
import { coloresEstadoDocumento, estadosDocumento } from '../../config/seccion02'
import { categoriasSeccion05, coloresGoce, formasPermiso, formulariosSeccion05, movimientosLicencia, movimientoDe, tiposGoce, tiposLicencia } from '../../config/seccion05'
import AbrirLegajo from '../AbrirLegajo'
import EspacioSeccion, { DocumentoDeRegistro, EnZona, SinAsociar } from '../documentos/EspacioSeccion'
import { atajosDe, idBloque, idTipo, mostrarEstado, tituloPaso, tituloSeccion } from '../../config/espacioSeccion'
import AdjuntarDocumento from '../documentos/AdjuntarDocumento'
import GruposTipos from '../documentos/GruposTipos'
import ListaDocumentos from '../documentos/ListaDocumentos'
import EnlazarDocumento from '../documentos/EnlazarDocumento'
import SubirDocumentoForm from '../documentos/SubirDocumentoForm'
import Registros from '../seccion02/Registros'
import { GoceForm, LicenciaForm, PeriodoVacacionalForm } from './Formularios'

const fecha = value => value ? value.split('-').reverse().join('/') : 'No registrada'
const texto = value => value === null || value === undefined || value === '' ? 'No registrado' : typeof value === 'boolean' ? (value ? 'Sí' : 'No') : String(value)
const enlace = 'text-sm font-semibold text-teal-700 disabled:opacity-50'
const rango = v => `${fecha(v.fecha_inicio)} – ${fecha(v.fecha_fin)} · ${v.dias} día(s)`

function Aviso({ tono = 'amber', children, accion }) {
  const clases = { amber: 'border-amber-200 bg-amber-50 text-amber-900', slate: 'border-slate-200 bg-slate-50 text-slate-600' }[tono]
  return <div role="note" className={`mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4 text-sm ${clases}`}><div>{children}</div>{accion}</div>
}

function Etiqueta({ children, clase }) {
  return <span className={`rounded-full px-2.5 py-1 text-[11px] font-medium ${clase}`}>{children}</span>
}

// Permiso con su forma (papeleta, memorándum u otro); los registrados antes de existir el campo, sin forma.
const nombreLicencia = v => v.tipo === 'permiso' ? `Permiso · ${formasPermiso[v.forma_permiso]?.etiqueta || 'forma no registrada'}` : etiqueta(tiposLicencia, v.tipo)
const etiquetaLicencia = v => `${nombreLicencia(v)} · ${rango(v)}`
const etiquetaGoce = (v, data) => `${etiqueta(tiposGoce, v.tipo)} · ${rango(v)} · período ${data.periodos.find(p => p.id === v.periodo_vacacional_id)?.values.periodo_anio ?? 'anulado'}`

// Registros sin documento que un tipo de documento puede acreditar (según los tipos de movimiento que admite).
function enlacesDe(tipo, data) {
  const admite = record => !record.values.documento_id && (!tipo.registros || tipo.registros.includes(record.values.tipo))
  if (tipo.formulario_datos === 'licencias_permisos') return data.licencias.filter(admite).map(r => ({ id: r.id, etiqueta: etiquetaLicencia(r.values) }))
  if (tipo.formulario_datos === 'goces_vacacionales') return data.goces.filter(admite).map(r => ({ id: r.id, etiqueta: etiquetaGoce(r.values, data) }))
  return null
}

// Número del documento ya guardado en el movimiento elegido (numero_documento), editable.
function precargaDe(tipo, data, registroId) {
  const lista = tipo.formulario_datos === 'licencias_permisos' ? data.licencias : tipo.formulario_datos === 'goces_vacacionales' ? data.goces : []
  return precargaDesdeRegistro(tipo, lista.find(record => record.id === registroId)?.values)
}

// «Adjuntar documento» en la tarjeta del movimiento. Los tipos con datos de salud restringidos no se ofrecen aquí.
function Adjuntar({ usuario, data, record, etiqueta: texto, sinLegajo }) {
  const noAplica = useNoAplicables(usuario)
  const tipos = tiposQueAcreditan(data.tipos.filter(tipo => !tipo.restringido), noAplica, tipo => enlacesDe(tipo, data), record.id)
  return <AdjuntarDocumento usuario={usuario} seccion={5} record={record} etiqueta={texto} tipos={tipos} titular={data.titular}
    precarga={(tipo, id) => precargaDe(tipo, data, id)} bloqueo={sinLegajo} />
}

// Registros que acredita un documento ya cargado.
function enlazadoA(tipo, documento, data) {
  const lista = tipo.formulario_datos === 'licencias_permisos' ? data.licencias.filter(r => r.values.documento_id === documento.id).map(r => etiquetaLicencia(r.values))
    : tipo.formulario_datos === 'goces_vacacionales' ? data.goces.filter(r => r.values.documento_id === documento.id).map(r => etiquetaGoce(r.values, data)) : null
  if (!lista) return null
  return lista.length ? lista.join('; ') : 'Sin movimiento enlazado'
}

// Formulario del movimiento que se abre al subir un documento cuyo tipo lo tiene, enlazado a ese documento.
function FormularioEnlazado({ usuario, data, tipo, documentoId, onDone }) {
  const props = { usuario, documentoId, permitidos: tipo.registros, onSaved: () => onDone(true), onCancel: () => onDone(false) }
  if (tipo.formulario_datos === 'licencias_permisos') return <LicenciaForm veSalud={data.ve_datos_salud} {...props} />
  if (!data.periodos.length) return <Aviso accion={<button onClick={() => onDone(false)} className={enlace}>Entendido</button>}>El documento quedó cargado, pero la persona no tiene un período vacacional registrado. Registra primero el período y luego el goce.</Aviso>
  return <GoceForm periodos={data.periodos} {...props} />
}

// Tipos de documento del bloque (literales del Anexo 01): estado, documentos cargados y carga por el mecanismo común.
// Un documento no se elimina ni se reemplaza: se subsana con otro.
// subiendo/setSubiendo: tipo cuyo formulario de carga está abierto (lo controla el panel para abrir el rol de vacaciones).
function Documentos({ usuario, data, tipos, sinLegajo, subiendo, setSubiendo }) {
  const puedeSubir = usePuede('documento.subir')
  const noAplica = useNoAplicables(usuario)
  const [enlazando, setEnlazando] = useState(null)
  const [subsanando, setSubsanando] = useState(null)
  // Documento ya cargado que quedó sin registro: se enlaza a uno existente o se registran sus datos.
  const [recuperando, setRecuperando] = useState(null)
  const [aviso, setAviso] = useState('')
  if (!tipos.length) return <Aviso>El catálogo de documentos de la sección 05 no está cargado en la base de datos (Seccion05CatalogoSeeder).</Aviso>
  const ocupado = !!subiendo || !!enlazando || !!subsanando || !!recuperando
  function subido(tipo, documento) {
    setSubiendo(null)
    setSubsanando(null)
    // Una subsanación no crea otro movimiento: el anterior sigue acreditado por la cadena de subsanación.
    const abre = documento.formulario_datos && !documento.enlazado && !documento.subsana_a_documento_id
    setAviso(`Documento cargado (folios ${folios(documento.folio_inicio, documento.folio_fin)}).${abre ? ` Registra el movimiento (${(formulariosSeccion05[documento.formulario_datos] || '').toLowerCase()}) que acredita.` : ''}`)
    if (abre) setEnlazando({ tipoId: tipo.id, documentoId: documento.id })
  }
  function enlazado(guardado) {
    setEnlazando(null)
    setAviso(guardado ? 'Movimiento guardado y enlazado al documento.' : 'El documento quedó cargado sin movimiento enlazado.')
  }
  const enlazar = puedeSubir && !sinLegajo && !ocupado ? (tipo, documento) => { setAviso(''); setRecuperando({ tipoId: tipo.id, documento }) } : null
  return <section id={idBloque.documentos} aria-label="Documentos" className="scroll-mt-4 mb-8">
    <h3 className="mb-1 text-base font-semibold text-slate-800">Documentos</h3>
    <p className="mb-4 text-xs text-slate-500">Sube cada documento desde su tipo. Si acredita un registro, también puedes adjuntarlo desde la tarjeta del registro.{sinLegajo && puedeSubir ? ` ${sinLegajo}` : ''}</p>
    {aviso && <p role="status" className="mb-4 rounded-lg bg-teal-50 p-3 text-sm text-teal-800">{aviso}</p>}
    <SinAsociar tipos={tipos} onEnlazar={enlazar} />
    <GruposTipos tipos={tipos} noAplica={noAplica} render={tipo => <li key={tipo.id} id={idTipo(tipo.id)} className="scroll-mt-4 p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <p className="text-sm font-medium text-slate-800">{tipo.literal && <span className="mr-1 text-slate-500">{tipo.literal})</span>}{tipo.nombre}</p>
          <p className="mt-1 text-xs text-slate-500">{tipo.descripcion}{tipo.formulario_datos ? ` Acredita: ${(formulariosSeccion05[tipo.formulario_datos] || tipo.formulario_datos).toLowerCase()}.` : ''}{tipo.codigo === 'S05_D_ROL_VACACIONES' ? ' Solo documento: está pendiente de confirmar si además requiere datos estructurados.' : ''}</p>
          {tipo.restringido ? <p className="mt-2 text-[11px] text-slate-400">Dato de salud: sus documentos requieren acceso a datos sensibles (Directiva, num. 6.2).</p>
            : <ListaDocumentos usuario={usuario} tipo={tipo} enlazado={documento => enlazadoA(tipo, documento, data)}
              onSubsanar={puedeSubir && !sinLegajo && !ocupado ? documento => { setAviso(''); setSubsanando({ tipoId: tipo.id, documento }) } : null}
              onEnlazar={puedeSubir && !sinLegajo && !ocupado && ofrecerTipo(noAplica, tipo) ? documento => { setAviso(''); setRecuperando({ tipoId: tipo.id, documento }) } : null} />}
        </div>
        <div className="flex shrink-0 flex-wrap items-center gap-2">
          {!tipo.restringido && mostrarEstado(tipo) && <Etiqueta clase={coloresEstadoDocumento[tipo.estado] || 'bg-slate-100 text-slate-700'}>{etiqueta(estadosDocumento, tipo.estado)}{tipo.documentos > 1 ? ` · ${tipo.documentos} documentos` : ''}</Etiqueta>}
          {puedeSubir && !tipo.restringido && ofrecerTipo(noAplica, tipo) && <button disabled={ocupado || !!sinLegajo} onClick={() => { setAviso(''); setSubiendo(tipo.id) }} className={`${enlace} inline-flex items-center gap-2`}><Icon name="paperclip" size={18} />Subir documento</button>}
        </div>
      </div>
      {subiendo === tipo.id && <div className="mt-4"><SubirDocumentoForm usuario={usuario} seccion={5} tipo={tipo} titular={data.titular} enlaces={enlacesDe(tipo, data)} precarga={id => precargaDe(tipo, data, id)} onSaved={documento => subido(tipo, documento)} onCancel={() => setSubiendo(null)} /></div>}
      {recuperando?.tipoId === tipo.id && <EnZona onCancel={() => setRecuperando(null)} titulo={tituloPaso('Enlazar', tipo, recuperando.documento)}><EnlazarDocumento usuario={usuario} documento={recuperando.documento} opciones={enlacesDe(tipo, data)}
        onRegistrarNuevo={() => { setEnlazando({ tipoId: tipo.id, documentoId: recuperando.documento.id }); setRecuperando(null) }}
        onDone={registro => { setRecuperando(null); setAviso(`Documento enlazado${registro ? ` a: ${registro}` : ''}.`) }} onCancel={() => setRecuperando(null)} /></EnZona>}
      {subsanando?.tipoId === tipo.id && <div className="mt-4"><SubirDocumentoForm usuario={usuario} seccion={5} tipo={tipo} titular={data.titular} subsanar={subsanando.documento} onSaved={documento => subido(tipo, documento)} onCancel={() => setSubsanando(null)} /></div>}
      {enlazando?.tipoId === tipo.id && <EnZona onCancel={() => setEnlazando(null)} titulo={tituloPaso('Completar los datos que acredita', tipo)}><div className="mt-4"><FormularioEnlazado usuario={usuario} data={data} tipo={tipo} documentoId={enlazando.documentoId} onDone={enlazado} /></div></EnZona>}
    </li>} />
  </section>
}

// Registros anulados: vista aparte, atenuada, solo con sistema.administrar (el backend responde 403 sin él).
function Anulados({ usuario, clave, nombre, titulo }) {
  const puede = usePuede('sistema.administrar')
  const [abierto, setAbierto] = useState(false)
  if (!puede) return null
  return <div className="mb-8 -mt-4">
    <button onClick={() => setAbierto(!abierto)} className="text-xs font-semibold text-slate-500 hover:text-slate-700">{abierto ? `Ocultar ${nombre}` : `Ver ${nombre}`}</button>
    {abierto && <ListaAnulados usuario={usuario} clave={clave} titulo={titulo} />}
  </div>
}

function ListaAnulados({ usuario, clave, titulo }) {
  const request = useLiveQuery(`/expedientes/${usuario}/seccion-05/anulados`)
  const registros = request.data?.data[clave] || []
  if (request.error) return <p role="alert" className="mt-3 text-sm text-rose-700">No se pudieron cargar los anulados. <button onClick={request.retry} className="underline">Reintentar</button></p>
  if (!request.data) return <p role="status" className="mt-3 text-sm text-slate-500">Cargando anulados…</p>
  if (!registros.length) return <p className="mt-3 text-sm text-slate-500">No hay registros anulados.</p>
  return <div className="mt-3 space-y-3">{registros.map(record => <article key={record.id} className="rounded-2xl border border-rose-100 bg-white p-4 opacity-75">
    <div className="flex flex-wrap items-start justify-between gap-2"><h4 className="text-sm font-semibold text-slate-700 line-through decoration-rose-300">{titulo(record.values)}</h4><Etiqueta clase="bg-rose-50 text-rose-700">Anulado</Etiqueta></div>
    <dl className="mt-3 grid gap-2 text-xs sm:grid-cols-2">
      <div><dt className="text-slate-500">Registrado por</dt><dd className="mt-0.5 text-slate-700">{record.values.registrado_por || 'No registrado'}</dd></div>
      <div><dt className="text-slate-500">Anulado el</dt><dd className="mt-0.5 text-slate-700">{record.values.updated_at || 'No registrado'}</dd></div>
      <div className="sm:col-span-2"><dt className="text-rose-700">Motivo de anulación</dt><dd className="mt-0.5 text-slate-700">{record.values.motivo_anulacion}</dd></div>
    </dl>
  </article>)}</div>
}

function Licencias({ usuario, data, sinLegajo }) {
  return <>
    <Registros documento={record => <DocumentoDeRegistro tipos={data.tipos} record={record} />} titulo="Licencias, permisos y suspensiones" agregar="Registrar movimiento" registros={data.licencias} bloqueo={sinLegajo}
      adjuntar={record => <Adjuntar usuario={usuario} data={data} record={record} etiqueta={etiquetaLicencia(record.values)} sinLegajo={sinLegajo} />}
      vacio="No hay licencias, permisos, suspensiones ni descansos médicos registrados."
      tituloRegistro={record => nombreLicencia(record.values)} subtitulo={record => rango(record.values)}
      campos={[['Con goce de remuneraciones', v => movimientosLicencia[movimientoDe(v.tipo)]?.conGoce === false ? 'Sin definir (pendiente de validar con la universidad)' : texto(v.con_goce)], ['N.º de documento', v => texto(v.numero_documento)]]}
      onAnular={(record, motivo) => anularSeccion05(usuario, 'licencias-permisos', record.id, motivo)}
      renderForm={props => <LicenciaForm usuario={usuario} veSalud={data.ve_datos_salud} {...props} />} />
    {!data.ve_datos_salud && <p className="-mt-6 mb-8 text-xs text-slate-500">Los descansos médicos son datos de salud y no se muestran sin acceso a datos sensibles.</p>}
    <Anulados usuario={usuario} clave="licencias_permisos" nombre="movimientos anulados" titulo={v => etiquetaLicencia(v)} />
  </>
}

// onRol: abre la carga del documento d) Rol de vacaciones en la lista de documentos.
function Vacaciones({ usuario, data, sinLegajo, onRol }) {
  const sinVinculo = !data.vinculos.length && 'Primero registra un vínculo laboral en 2.2: el período vacacional pertenece a un vínculo.'
  const vinculo = id => data.vinculos.find(v => v.id === id)
  return <>
    <Registros documento={record => <DocumentoDeRegistro tipos={data.tipos} record={record} />} titulo="Períodos vacacionales" agregar="Registrar período" registros={data.periodos} bloqueo={sinLegajo || sinVinculo}
      vacio="No hay períodos vacacionales registrados."
      tituloRegistro={record => `Período ${record.values.periodo_anio}`} subtitulo={record => `${record.values.dias_derecho} días de derecho · ${record.dias_registrados} día(s) de goce o adelanto registrados`}
      campos={[['Vínculo laboral', v => vinculo(v.vinculo_laboral_id)?.nombre_puesto || 'No encontrado']]}
      onAnular={(record, motivo) => anularSeccion05(usuario, 'periodos-vacacionales', record.id, motivo)}
      renderForm={props => <PeriodoVacacionalForm usuario={usuario} vinculos={data.vinculos} {...props} />} />
    <Anulados usuario={usuario} clave="periodos_vacacionales" nombre="períodos anulados" titulo={v => `Período ${v.periodo_anio}`} />
    <Registros documento={record => <DocumentoDeRegistro tipos={data.tipos} record={record} />} titulo="Goces, adelantos y postergaciones" agregar="Registrar movimiento vacacional" registros={data.goces} bloqueo={sinLegajo}
      adjuntar={record => <Adjuntar usuario={usuario} data={data} record={record} etiqueta={etiquetaGoce(record.values, data)} sinLegajo={sinLegajo} />}
      vacio="No hay goces, adelantos ni postergaciones registrados."
      tituloRegistro={record => etiqueta(tiposGoce, record.values.tipo)}
      subtitulo={record => <><Etiqueta clase={coloresGoce[record.values.tipo] || 'bg-slate-100 text-slate-700'}>{record.values.tipo === 'goce' ? 'b) Goce' : 'c) Adelanto o postergación'}</Etiqueta><span className="ml-2">{rango(record.values)}</span></>}
      campos={[['Período', v => data.periodos.find(p => p.id === v.periodo_vacacional_id)?.values.periodo_anio ?? 'No encontrado'], ['N.º de documento', v => texto(v.numero_documento)]]}
      onAnular={(record, motivo) => anularSeccion05(usuario, 'goces-vacacionales', record.id, motivo)}
      renderForm={props => <GoceForm usuario={usuario} periodos={data.periodos} onRol={onRol && (() => { props.onCancel(); onRol() })} {...props} />} />
    <Anulados usuario={usuario} clave="goces_vacacionales" nombre="goces anulados" titulo={v => `${etiqueta(tiposGoce, v.tipo)} · ${rango(v)}`} />
  </>
}

export default function Seccion05Panel({ usuario, categoria }) {
  const request = useLiveQuery(`/expedientes/${usuario}/seccion-05`)
  const [subiendo, setSubiendo] = useState(null)
  const puedeSubir = usePuede('documento.subir')
  const data = request.data?.data
  const { bloque, titulo, literales } = categoriasSeccion05[categoria]
  const error = request.error && <p role="alert" className="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{request.error.response?.status === 403 ? 'No tienes permiso para ver esta sección.' : 'No se pudo actualizar la sección. Lo que ves puede no estar al día.'} <button onClick={request.retry} className="font-semibold underline">Reintentar</button></p>
  if (!data) return error || <p role="status" className="rounded-xl bg-slate-100 p-6 text-sm text-slate-500">Cargando…</p>
  const sinLegajo = !data.legajo && 'La persona no tiene un legajo abierto.'
  // Rol de vacaciones: solo documento. Se abre su carga (mecanismo común) y se lleva la vista hasta ella.
  const tipoRol = data.tipos.find(tipo => tipo.codigo === 'S05_D_ROL_VACACIONES')
  const abrirRol = puedeSubir && tipoRol && !sinLegajo ? () => {
    setSubiendo(tipoRol.id)
    requestAnimationFrame(() => document.getElementById(`tipo-${tipoRol.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
  } : null

  const tipos = data.tipos.filter(tipo => tipo.bloque === bloque)
  const registros = { licencias: data.licencias.length, vacaciones: data.periodos.length + data.goces.length }[bloque]
  return <EspacioSeccion seccion={tituloSeccion('05')} subseccion={titulo} atajos={atajosDe({ registros, tipos })} etiquetaResumen="Acerca de esta sección"
    resumen={<p className="text-slate-500">Sección 05 · Movimientos del personal{literales ? ` · Anexo 01, literales ${literales}` : ' · otros documentos requeridos por la universidad'}. Un trabajador puede tener varios movimientos y documentos; ninguno se elimina.</p>}>
    {error}
    {sinLegajo && <Aviso accion={<AbrirLegajo usuario={usuario} />}>{sinLegajo} Los registros y la carga de documentos de la sección 05 están deshabilitados hasta abrirlo.</Aviso>}
    {/* Primero los documentos (qué falta, qué hay, qué es opcional); después los registros que acreditan. */}
    <Documentos usuario={usuario} data={data} tipos={tipos} sinLegajo={sinLegajo} subiendo={subiendo} setSubiendo={setSubiendo} />
    {registros !== undefined && <div id={idBloque.registros} className="scroll-mt-4">
      {bloque === 'licencias' && <Licencias usuario={usuario} data={data} sinLegajo={sinLegajo} />}
      {bloque === 'vacaciones' && <Vacaciones usuario={usuario} data={data} sinLegajo={sinLegajo} onRol={abrirRol} />}
    </div>}
  </EspacioSeccion>
}
