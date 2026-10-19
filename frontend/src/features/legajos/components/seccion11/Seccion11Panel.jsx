import Aviso from '../documentos/AvisoSeccion'
import Icon from '../../../../components/ui/Icon'
import { useState } from 'react'
import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { usePuede } from '../../../auth/session'
import { anularDesvinculacion } from '../../api/seccion11Api'
import { useNoAplicables } from '../../api/useNoAplicables'
import { ofrecerTipo, tiposQueAcreditan } from '../../config/aplicacion'
import { precargaDesdeRegistro } from '../../config/acreditacion'
import { bloquesDocumentos11, categoriasSeccion11, etiquetaVinculo11, motivosCese, permisosSeccion11 } from '../../config/seccion11'
import { coloresEstadoDocumento, estadosDocumento, tipoVinculo } from '../../config/seccion02'
import { etiqueta } from '../../config/seccion01'
import { folios } from '../../config/documentos'
import AdjuntarDocumento from '../documentos/AdjuntarDocumento'
import GruposTipos from '../documentos/GruposTipos'
import ListaDocumentos from '../documentos/ListaDocumentos'
import EnlazarDocumento from '../documentos/EnlazarDocumento'
import SubirDocumentoForm from '../documentos/SubirDocumentoForm'
import AbrirLegajo from '../AbrirLegajo'
import EspacioSeccion, { DocumentoDeRegistro, EnZona, SinAsociar } from '../documentos/EspacioSeccion'
import { atajosDe, idBloque, idTipo, mostrarEstado, tituloPaso, tituloSeccion } from '../../config/espacioSeccion'
import Registros from '../seccion07/Registros'
import DesvinculacionForm from './DesvinculacionForm'

const fecha = value => value ? value.split('-').reverse().join('/') : 'No registrada'
const texto = value => value === null || value === undefined || value === '' ? 'No registrado' : String(value)
const enlace = 'text-sm font-semibold text-teal-700 disabled:opacity-50'



function Etiqueta({ children, clase }) {
  return <span className={`rounded-full px-2.5 py-1 text-[11px] font-medium ${clase}`}>{children}</span>
}

// Vínculos que pueden recibir una desvinculación nueva: con cese registrado en la 02 y sin desvinculación vigente.
const disponibles = data => data.vinculos.filter(v => v.values.fecha_cese && !v.desvinculacion_id)
const vinculoDe = (data, id) => data.vinculos.find(v => v.id === id)
const etiquetaDesvinculacion = (data, d) => `${etiqueta(motivosCese, d.values.motivo_cese)} · cese ${fecha(d.values.fecha_cese)} · ${vinculoDe(data, d.values.vinculo_laboral_id)?.values.nombre_puesto || 'vínculo no encontrado'}`

// Desvinculaciones a las que se puede enlazar un documento de este tipo (las mismas reglas que valida el backend).
function enlacesDe(tipo, data) {
  if (!tipo.formulario_datos) return null
  return data.desvinculaciones.filter(d => {
    if (!tipo.principal) return true
    const vinculo = vinculoDe(data, d.values.vinculo_laboral_id)
    return !d.values.documento_id && (!tipo.motivos || tipo.motivos.includes(d.values.motivo_cese)) && (!tipo.solo_plazo_determinado || vinculo?.values.regimen_laboral === 'plazo_determinado')
  }).map(d => ({ id: d.id, etiqueta: etiquetaDesvinculacion(data, d) }))
}

function enlazadoA(tipo, documento, data) {
  if (!tipo.formulario_datos) return null
  const vinculo = vinculoDe(data, documento.values.vinculo_laboral_id)
  const acredita = data.desvinculaciones.find(d => d.values.documento_id === documento.id)
  if (acredita) return `Acredita: ${etiquetaDesvinculacion(data, acredita)}`
  return vinculo ? `Vínculo: ${etiquetaVinculo11(vinculo)}` : 'Sin vínculo enlazado'
}

function Vinculos({ data, onNavigate }) {
  if (!data.vinculos.length) return <Aviso accion={<button onClick={() => onNavigate('s02-vinculo')} className={enlace}>Ir a 2.2</button>}>La persona no tiene vínculos laborales registrados. La desvinculación se registra sobre un vínculo de la sección 02.</Aviso>
  return <section aria-label="Vínculos laborales" className="mb-8">
    <h3 className="mb-3 text-base font-semibold text-slate-800">Vínculos laborales</h3>
    <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white"><table className="w-full text-left text-xs">
      <thead className="bg-slate-50 text-slate-500"><tr>{['Vínculo', 'Tipo de vínculo', 'Fecha de cese (sección 02)', 'Desvinculación', 'Documentos'].map(t => <th key={t} className="px-4 py-3 font-medium">{t}</th>)}</tr></thead>
      <tbody>{data.vinculos.map(v => <tr key={v.id} className="border-t border-slate-100 text-slate-700">
        <td className="px-4 py-3">{etiquetaVinculo11(v)}</td>
        <td className={`px-4 py-3 ${v.values.regimen_laboral ? '' : 'text-slate-600'}`}>{tipoVinculo(v.values.regimen_laboral)}</td>
        <td className="px-4 py-3">{v.values.fecha_cese ? fecha(v.values.fecha_cese) : <span className="text-slate-600">Sin cese: regístralo primero en la sección 02</span>}</td>
        <td className="px-4 py-3">{v.desvinculacion_id ? <Etiqueta clase="bg-teal-50 text-teal-800">Registrada</Etiqueta> : <Etiqueta clase="bg-slate-100 text-slate-600">{v.values.fecha_cese ? 'Pendiente de registrar' : 'No aplica aún'}</Etiqueta>}</td>
        <td className="px-4 py-3">{v.documentos}</td>
      </tr>)}</tbody>
    </table></div>
    {data.vinculos.some(v => !v.values.fecha_cese) && <p className="mt-2 text-xs text-slate-500">La fecha de cese de referencia es la del vínculo. <button onClick={() => onNavigate('s02-vinculo')} className="font-semibold text-teal-700 hover:underline">Registrar el cese en 2.2</button></p>}
  </section>
}

// Desvinculaciones anuladas: vista aparte, atenuada, solo con sistema.administrar (el backend responde 403 sin él).
function ListaAnuladas({ usuario, data }) {
  const request = useLiveQuery(`/expedientes/${usuario}/seccion-11/anulados`)
  const lista = request.data?.data.desvinculaciones || []
  if (request.error) return <p role="alert" className="mt-3 text-sm text-rose-700">No se pudieron cargar las anuladas. <button onClick={request.retry} className="underline">Reintentar</button></p>
  if (!request.data) return <p role="status" className="mt-3 text-sm text-slate-500">Cargando anuladas…</p>
  if (!lista.length) return <p className="mt-3 text-sm text-slate-500">No hay desvinculaciones anuladas.</p>
  return <div className="mt-3 space-y-3">{lista.map(d => <article key={d.id} className="rounded-2xl border border-rose-100 bg-white p-4 opacity-75">
    <div className="flex flex-wrap items-start justify-between gap-2"><h4 className="text-sm font-semibold text-slate-700 line-through decoration-rose-300">{etiquetaDesvinculacion(data, d)}</h4><Etiqueta clase="bg-rose-50 text-rose-700">Anulada</Etiqueta></div>
    <p className="mt-2 text-xs text-slate-600"><span className="text-rose-700">Motivo de anulación:</span> {d.values.motivo_anulacion} · registrada por {d.values.registrado_por || 'no registrado'}</p>
  </article>)}</div>
}

function Anuladas({ usuario, data }) {
  const puede = usePuede('sistema.administrar')
  const [abierto, setAbierto] = useState(false)
  if (!puede) return null
  return <div className="mb-8 -mt-4">
    <button onClick={() => setAbierto(!abierto)} className="text-xs font-semibold text-slate-500 hover:text-slate-700">{abierto ? 'Ocultar desvinculaciones anuladas' : 'Ver desvinculaciones anuladas'}</button>
    {abierto && <ListaAnuladas usuario={usuario} data={data} />}
  </div>
}

// N.º de resolución de la desvinculación elegida: solo para los documentos del término (acreditan esa resolución).
function precargaDe(tipo, data, registroId) {
  return precargaDesdeRegistro(tipo, data.desvinculaciones.find(record => record.id === registroId)?.values)
}

// «Adjuntar documento» en la tarjeta de la desvinculación: los del término (si aún no tiene) y los complementarios.
function Adjuntar({ usuario, data, record, sinLegajo }) {
  const noAplica = useNoAplicables(usuario)
  const tipos = tiposQueAcreditan(data.tipos.filter(tipo => !tipo.restringido), noAplica, tipo => enlacesDe(tipo, data), record.id)
  return <AdjuntarDocumento usuario={usuario} seccion={11} record={record} etiqueta={etiquetaDesvinculacion(data, record)} tipos={tipos} titular={data.titular}
    precarga={(tipo, id) => precargaDe(tipo, data, id)} bloqueo={sinLegajo} />
}

function Desvinculaciones({ usuario, data, sinLegajo, onNavigate }) {
  const libres = disponibles(data)
  const bloqueo = sinLegajo || (!libres.length && 'No hay vínculos con cese registrado pendientes de desvinculación (el cese se registra en la sección 02).')
  return <>
    <Vinculos data={data} onNavigate={onNavigate} />
    {data.desvinculaciones.some(d => !d.coincide_fecha_vinculo) && <Aviso>Hay desvinculaciones cuya fecha de cese ya no coincide con la del vínculo (se corrigió en la sección 02). Corrígelas para que coincidan.</Aviso>}
    <Registros documento={record => <DocumentoDeRegistro tipos={data.tipos} record={record} />} titulo="Desvinculaciones" agregar="Registrar desvinculación" registros={data.desvinculaciones} bloqueo={bloqueo} permisos={permisosSeccion11}
      adjuntar={record => <Adjuntar usuario={usuario} data={data} record={record} sinLegajo={sinLegajo} />}
      vacio="No hay desvinculaciones registradas. No todas las personas han cesado."
      tituloRegistro={d => etiqueta(motivosCese, d.values.motivo_cese)} subtitulo={d => `${vinculoDe(data, d.values.vinculo_laboral_id) ? etiquetaVinculo11(vinculoDe(data, d.values.vinculo_laboral_id)) : 'Vínculo no encontrado'} · cese ${fecha(d.values.fecha_cese)}`}
      campos={[
        ['N.º de resolución', v => texto(v.numero_resolucion)],
        ['Entrega de cargo', v => fecha(v.fecha_entrega_cargo)],
        ['Informe de gestión', v => v.informe_gestion_presentado ? 'Presentado' : 'No registrado'],
        ['Fecha de liquidación', v => fecha(v.fecha_liquidacion)],
        ['Observaciones', v => texto(v.observaciones)],
      ]}
      onAnular={(record, motivo) => anularDesvinculacion(usuario, record.id, motivo)}
      renderForm={props => <DesvinculacionForm key={props.record?.id || 'nuevo'} usuario={usuario}
        vinculos={props.record ? data.vinculos.filter(v => v.values.fecha_cese && (!v.desvinculacion_id || v.id === props.record.values.vinculo_laboral_id)) : libres} {...props} />} />
    <Anuladas usuario={usuario} data={data} />
  </>
}

// Un tipo de documento: estado, lista (recepción y visor comunes) y carga con enlace a la desvinculación o al vínculo.
function TipoDocumento({ usuario, data, tipo, sinLegajo, ofrecer, modo }) {
  // Cargar uno nuevo solo si el tipo se ofrece; subsanar uno existente también si ya no se ofrece (lo admite el backend).
  const puedeCargar = usePuede('documento.subir')
  const puedeSubir = puedeCargar && ofrecer
  const [subiendo, setSubiendo] = useState(modo === 'subir')
  const [subsanando, setSubsanando] = useState(null)
  // Documento ya cargado que quedó sin desvinculación: se enlaza a una existente o (los del término) se registra.
  const [recuperando, setRecuperando] = useState(null)
  const [registrando, setRegistrando] = useState(null)
  const [aviso, setAviso] = useState('')
  const libres = disponibles(data).filter(v => !tipo.solo_plazo_determinado || v.values.regimen_laboral === 'plazo_determinado')
  // Solo plazo determinado: un vínculo con el tipo Sin definir no se ofrece hasta definirlo (mismo mensaje que el backend).
  const sinDefinir = tipo.solo_plazo_determinado && data.vinculos.some(v => v.values.fecha_cese && v.values.regimen_laboral === null)
  function subido(documento) {
    setSubiendo(false)
    setSubsanando(null)
    // Una subsanación no crea otra desvinculación: la anterior sigue acreditada por la cadena de subsanación.
    const abre = tipo.principal && !documento.enlazado && !documento.subsana_a_documento_id
    setAviso(`Documento cargado (folios ${folios(documento.folio_inicio, documento.folio_fin)}).${documento.enlazado ? ' Quedó enlazado.' : abre ? ' Registra la desvinculación que acredita o cancela si es un documento adicional.' : tipo.formulario_datos ? ' Quedó sin vínculo: para agruparlo, súbelo eligiendo la desvinculación.' : ''}`)
    if (abre) setRegistrando(documento.id)
  }
  return <li id={idTipo(tipo.id)} className="scroll-mt-4 p-4">
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium text-slate-800">{tipo.literal ? <span className="mr-1 text-slate-500">{tipo.literal})</span> : tipo.codigo === 'S11_X_ACEPTACION_RENUNCIA' && <span className="mr-1 text-slate-500">Anexo 05 ·</span>}{tipo.nombre}</p>
        <p className="mt-1 text-xs text-slate-500">{tipo.descripcion ? `${tipo.descripcion} ` : ''}Opcional; admite varios documentos.{tipo.motivos ? ` Solo para el motivo: ${tipo.motivos.map(m => etiqueta(motivosCese, m).toLowerCase()).join(', ')}.` : ''}</p>
        {sinDefinir && <p role="note" className="mt-2 rounded-lg bg-slate-50 p-2 text-xs text-slate-700">Hay vínculos con el tipo de vínculo sin definir y este documento solo corresponde a plazo determinado. Define el tipo de vínculo en la sección 02 (2.2: «Completar tipo» si el vínculo está cerrado).</p>}
        {tipo.restringido ? <p className="mt-2 text-[11px] text-slate-400">Documento con datos sensibles: requiere acceso a datos sensibles.</p>
          : <ListaDocumentos usuario={usuario} tipo={tipo} enlazado={documento => enlazadoA(tipo, documento, data)}
            onSubsanar={puedeCargar && !sinLegajo && !subiendo && !subsanando && !registrando && !recuperando ? documento => { setAviso(''); setSubsanando(documento) } : null}
            onEnlazar={puedeSubir && !sinLegajo && !subiendo && !subsanando && !registrando && !recuperando ? documento => { setAviso(''); setRecuperando(documento) } : null} />}
      </div>
      <div className="flex shrink-0 flex-wrap items-center gap-2">
        {tipo.es_dato_sensible && <Etiqueta clase="bg-rose-50 text-rose-700">Dato sensible</Etiqueta>}
        {!tipo.restringido && mostrarEstado(tipo) && <Etiqueta clase={coloresEstadoDocumento[tipo.estado] || 'bg-slate-100 text-slate-700'}>{etiqueta(estadosDocumento, tipo.estado)}{tipo.documentos > 1 ? ` · ${tipo.documentos} documentos` : ''}</Etiqueta>}
        {puedeSubir && <button disabled={subiendo || !!registrando || !!sinLegajo || tipo.restringido} title={tipo.restringido ? 'Requiere acceso a datos sensibles.' : sinLegajo || undefined} onClick={() => { setAviso(''); setSubiendo(true) }} className={`${enlace} inline-flex items-center gap-2`}><Icon name="paperclip" size={18} />Subir documento</button>}
      </div>
    </div>
    {aviso && <p role="status" className="mt-3 rounded-lg bg-teal-50 p-3 text-sm text-teal-800">{aviso}</p>}
    {subiendo && <div className="mt-4"><SubirDocumentoForm usuario={usuario} seccion={11} tipo={tipo} titular={data.titular} enlaces={enlacesDe(tipo, data)} precarga={id => precargaDe(tipo, data, id)} onSaved={subido} onCancel={() => setSubiendo(false)} /></div>}
    {recuperando && <EnZona titulo={tituloPaso('Enlazar', tipo, recuperando)}><EnlazarDocumento usuario={usuario} documento={recuperando} opciones={enlacesDe(tipo, data)}
      onRegistrarNuevo={tipo.principal ? () => { setRegistrando(recuperando.id); setRecuperando(null) } : null}
      onDone={registro => { setRecuperando(null); setAviso(`Documento enlazado${registro ? ` a: ${registro}` : ''}.`) }} onCancel={() => setRecuperando(null)} /></EnZona>}
    {subsanando && <div className="mt-4"><SubirDocumentoForm usuario={usuario} seccion={11} tipo={tipo} titular={data.titular} subsanar={subsanando} onSaved={subido} onCancel={() => setSubsanando(null)} /></div>}
    {registrando && <EnZona titulo={tituloPaso('Registrar la desvinculación que acredita', tipo)}>{libres.length
      ? <div className="mt-4"><DesvinculacionForm usuario={usuario} vinculos={libres} motivos={tipo.motivos} documentoId={registrando} onSaved={() => { setRegistrando(null); setAviso('Desvinculación registrada y acreditada por el documento.') }} onCancel={() => { setRegistrando(null); setAviso('El documento quedó cargado sin desvinculación enlazada.') }} /></div>
      : <div className="mt-4"><Aviso accion={<button onClick={() => setRegistrando(null)} className={enlace}>Entendido</button>}>El documento quedó cargado, pero no hay vínculos con cese registrado pendientes de desvinculación{tipo.solo_plazo_determinado ? ' de plazo determinado' : ''}. Registra el cese en la sección 02.</Aviso></div>}</EnZona>}
  </li>
}

function Documentos({ usuario, data, sinLegajo }) {
  const puedeSubir = usePuede('documento.subir')
  const noAplica = useNoAplicables(usuario)
  if (!data.tipos.length) return <Aviso>El catálogo de documentos de la sección 11 no está cargado en la base de datos (Seccion11CatalogoSeeder).</Aviso>
  // Los que quedaron sin desvinculación: arriba, con acceso a su tipo (cada tipo ofrece «Enlazar ahora»).
  return <div id={idBloque.documentos} className="scroll-mt-4"><SinAsociar tipos={data.tipos} />{Object.entries(bloquesDocumentos11).map(([bloque, titulo]) => <section key={bloque} aria-label={titulo} className="mb-8">
    <h3 className="mb-3 text-base font-semibold text-slate-800">{titulo}</h3>
    <GruposTipos tipos={data.tipos.filter(t => t.bloque === bloque)} noAplica={noAplica}
      puedeSubir={tipo => puedeSubir && ofrecerTipo(noAplica, tipo)} deshabilitado={tipo => !!sinLegajo || tipo.restringido}
      render={(tipo, modo) => <TipoDocumento modo={modo} key={tipo.id} usuario={usuario} data={data} tipo={tipo} sinLegajo={sinLegajo} ofrecer={ofrecerTipo(noAplica, tipo)} />} />
  </section>)}</div>
}

export default function Seccion11Panel({ usuario, categoria, onNavigate }) {
  const request = useLiveQuery(`/expedientes/${usuario}/seccion-11`)
  const data = request.data?.data
  const { titulo } = categoriasSeccion11[categoria]
  const error = request.error && <p role="alert" className="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{request.error.response?.status === 403 ? 'No tienes permiso para ver la sección 11.' : 'No se pudo actualizar la sección. Lo que ves puede no estar al día.'} <button onClick={request.retry} className="font-semibold underline">Reintentar</button></p>
  if (!data) return error || <p role="status" className="rounded-xl bg-slate-100 p-6 text-sm text-slate-500">Cargando…</p>
  const sinLegajo = !data.legajo && 'La persona no tiene un legajo abierto (activo).'

  const enRegistros = categoria === 's11-desvinculacion'
  return <EspacioSeccion seccion={tituloSeccion('11')} subseccion={titulo} atajos={atajosDe(enRegistros ? { registros: data.desvinculaciones.length } : { tipos: data.tipos })} etiquetaResumen="Acerca de esta sección"
    resumen={<p className="text-slate-500">Sección 11 · Desvinculación (Anexo 01, literales a a g; la aceptación de renuncia es del Anexo 05). Ningún documento es obligatorio para todos: depende del cese y del régimen. El legajo no pasa a pasivo automáticamente.</p>}>
    {error}
    {sinLegajo && <Aviso accion={<AbrirLegajo usuario={usuario} />}>{sinLegajo} Los formularios y la carga de documentos de la sección 11 están deshabilitados.</Aviso>}
    {enRegistros ? <div id={idBloque.registros} className="scroll-mt-4"><Desvinculaciones usuario={usuario} data={data} sinLegajo={sinLegajo} onNavigate={onNavigate} /></div>
      : <Documentos usuario={usuario} data={data} sinLegajo={sinLegajo} />}
  </EspacioSeccion>
}
