import Aviso from '../documentos/AvisoSeccion'
import Icon from '../../../../components/ui/Icon'
import { useState } from 'react'
import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { usePuede } from '../../../auth/session'
import { anularSeccion09 } from '../../api/seccion09Api'
import { useNoAplicables } from '../../api/useNoAplicables'
import { ofrecerTipo, tiposQueAcreditan } from '../../config/aplicacion'
import { categoriasSeccion09, estadosControversia, instancias, permisosSeccion09, tipoControversiaDe } from '../../config/seccion09'
import { coloresEstadoDocumento, estadosDocumento } from '../../config/seccion02'
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
import { AfiliacionForm, ControversiaForm } from './Formularios'

const fecha = value => value ? value.split('-').reverse().join('/') : 'No registrada'
const texto = value => value === null || value === undefined || value === '' ? 'No registrado' : String(value)
const enlace = 'text-sm font-semibold text-teal-700 disabled:opacity-50'



function Etiqueta({ children, clase }) {
  return <span className={`rounded-full px-2.5 py-1 text-[11px] font-medium ${clase}`}>{children}</span>
}

const etiquetaControversia = c => `${etiqueta(instancias, c.values.instancia)} · ${c.values.materia}`
const etiquetaAfiliacion = a => `${a.values.entidad} · desde ${fecha(a.values.fecha_inicio)}`

// Registros vigentes, sin documento, que un documento de este tipo puede acreditar (a solo individuales, b solo colectivas, c solo sindicato).
function enlacesDe(tipo, data) {
  const sinDocumento = lista => lista.filter(record => !record.values.documento_id)
  if (tipo.formulario_datos === 'controversias') return sinDocumento(data.controversias).filter(c => tipo.registros?.includes(c.values.tipo)).map(c => ({ id: c.id, etiqueta: etiquetaControversia(c) }))
  if (tipo.formulario_datos === 'afiliaciones') return sinDocumento(data.afiliaciones).map(a => ({ id: a.id, etiqueta: etiquetaAfiliacion(a) }))
  return null
}

// «Adjuntar documento» en la tarjeta del registro (a, b o c según su tipo). Sin datos que proponer: los de la controversia
// o la afiliación no son los del documento.
function Adjuntar({ usuario, data, record, etiqueta: texto, sinLegajo }) {
  const noAplica = useNoAplicables(usuario)
  const tipos = tiposQueAcreditan(data.tipos, noAplica, tipo => enlacesDe(tipo, data), record.id)
  return <AdjuntarDocumento usuario={usuario} seccion={9} record={record} etiqueta={texto} tipos={tipos} titular={data.titular} bloqueo={sinLegajo} />
}

// Registro que acredita un documento (el enlace lo guarda el backend al subir o al registrar el formulario).
function enlazadoA(tipo, documento, data) {
  const lista = tipo.formulario_datos === 'controversias' ? data.controversias : tipo.formulario_datos === 'afiliaciones' ? data.afiliaciones : null
  if (!lista) return null
  const record = lista.find(item => item.values.documento_id === documento.id)
  if (record) return tipo.formulario_datos === 'controversias' ? `Controversia: ${etiquetaControversia(record)}` : `Afiliación: ${etiquetaAfiliacion(record)}`
  return tipo.formulario_datos === 'controversias' ? 'Sin controversia enlazada (documento adicional)' : 'Sin afiliación enlazada'
}

// Formulario de datos que se abre al subir un documento cuyo tipo lo tiene, con el tipo de registro fijado por el documento.
function FormularioEnlazado({ usuario, tipo, documentoId, onDone }) {
  const props = { usuario, documentoId, onSaved: () => onDone(true), onCancel: () => onDone(false) }
  if (tipo.formulario_datos === 'controversias') return <ControversiaForm tipo={tipo.registros[0]} {...props} />
  if (tipo.formulario_datos === 'afiliaciones') return <AfiliacionForm {...props} />
  return null
}

// Tipos de documento del bloque: estado, documentos cargados y carga por el mecanismo común (folios, recepción, visor).
function Documentos({ usuario, data, tipos, sinLegajo }) {
  const puedeSubir = usePuede('documento.subir')
  const noAplica = useNoAplicables(usuario)
  const [subiendo, setSubiendo] = useState(null)
  const [enlazando, setEnlazando] = useState(null)
  const [subsanando, setSubsanando] = useState(null)
  // Documento ya cargado que quedó sin registro: se enlaza a uno existente o se registran sus datos.
  const [recuperando, setRecuperando] = useState(null)
  const [aviso, setAviso] = useState('')
  if (!tipos.length) return <Aviso>El catálogo de documentos de este bloque no está cargado en la base de datos (Seccion09CatalogoSeeder).</Aviso>
  const ocupado = !!subiendo || !!enlazando || !!subsanando || !!recuperando
  function subido(tipo, documento) {
    setSubiendo(null)
    setSubsanando(null)
    // Una subsanación no crea otro registro: el anterior sigue acreditado por la cadena de subsanación.
    const abre = documento.formulario_datos && !documento.enlazado && !documento.subsana_a_documento_id
    setAviso(`Documento cargado (folios ${folios(documento.folio_inicio, documento.folio_fin)}).${abre ? ' Completa los datos que acredita o cancela si es un documento adicional de un registro existente.' : ''}`)
    if (abre) setEnlazando({ tipo, documentoId: documento.id })
  }
  function enlazado(guardado) {
    setEnlazando(null)
    setAviso(guardado ? 'Datos guardados y enlazados al documento.' : 'El documento quedó cargado sin registro de datos enlazado.')
  }
  const enlazar = puedeSubir && !sinLegajo && !ocupado ? (tipo, documento) => { setAviso(''); setRecuperando({ tipoId: tipo.id, documento }) } : null
  return <section id={idBloque.documentos} aria-label="Documentos del bloque" className="scroll-mt-4">
    <h3 className="mb-1 text-base font-semibold text-slate-800">Documentos</h3>
    <p className="mb-4 text-xs text-slate-500">Sube cada documento desde su tipo. Si acredita un registro, también puedes adjuntarlo desde la tarjeta del registro.{sinLegajo && puedeSubir ? ` ${sinLegajo}` : ''}</p>
    {aviso && <p role="status" className="mb-4 rounded-lg bg-teal-50 p-3 text-sm text-teal-800">{aviso}</p>}
    <SinAsociar tipos={tipos} onEnlazar={enlazar} />
    <GruposTipos tipos={tipos} noAplica={noAplica} render={tipo => <li key={tipo.id} id={idTipo(tipo.id)} className="scroll-mt-4 p-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <p className="text-sm font-medium text-slate-800">{tipo.literal && <span className="mr-1 text-slate-500">{tipo.literal})</span>}{tipo.nombre}</p>
          <p className="mt-1 text-xs text-slate-500">{tipo.descripcion ? `${tipo.descripcion} ` : ''}Admite varios documentos.{tipo.formulario_datos ? '' : ' Solo metadatos (sin formulario de datos).'}</p>
          <ListaDocumentos usuario={usuario} tipo={tipo} enlazado={documento => enlazadoA(tipo, documento, data)}
            onSubsanar={puedeSubir && !sinLegajo && !ocupado ? documento => { setAviso(''); setSubsanando({ tipoId: tipo.id, documento }) } : null}
              onEnlazar={puedeSubir && !sinLegajo && !ocupado && ofrecerTipo(noAplica, tipo) ? documento => { setAviso(''); setRecuperando({ tipoId: tipo.id, documento }) } : null} />
        </div>
        <div className="flex shrink-0 flex-wrap items-center gap-2">
          <Etiqueta clase="bg-slate-100 text-slate-600">Opcional</Etiqueta>
          {mostrarEstado(tipo) && <Etiqueta clase={coloresEstadoDocumento[tipo.estado] || 'bg-slate-100 text-slate-700'}>{etiqueta(estadosDocumento, tipo.estado)}{tipo.documentos > 1 ? ` · ${tipo.documentos} documentos` : ''}</Etiqueta>}
          {puedeSubir && ofrecerTipo(noAplica, tipo) && <button disabled={ocupado || !!sinLegajo} title={sinLegajo || undefined} onClick={() => { setAviso(''); setSubiendo(tipo.id) }} className={`${enlace} inline-flex items-center gap-2`}><Icon name="paperclip" size={18} />Subir documento</button>}
        </div>
      </div>
      {subiendo === tipo.id && <div className="mt-4"><SubirDocumentoForm usuario={usuario} seccion={9} tipo={tipo} titular={data.titular} enlaces={enlacesDe(tipo, data)} onSaved={documento => subido(tipo, documento)} onCancel={() => setSubiendo(null)} /></div>}
      {recuperando?.tipoId === tipo.id && <EnZona titulo={tituloPaso('Enlazar', tipo, recuperando.documento)}><EnlazarDocumento usuario={usuario} documento={recuperando.documento} opciones={enlacesDe(tipo, data)}
        onRegistrarNuevo={() => { setEnlazando({ tipo, documentoId: recuperando.documento.id }); setRecuperando(null) }}
        onDone={registro => { setRecuperando(null); setAviso(`Documento enlazado${registro ? ` a: ${registro}` : ''}.`) }} onCancel={() => setRecuperando(null)} /></EnZona>}
      {subsanando?.tipoId === tipo.id && <div className="mt-4"><SubirDocumentoForm usuario={usuario} seccion={9} tipo={tipo} titular={data.titular} subsanar={subsanando.documento} onSaved={documento => subido(tipo, documento)} onCancel={() => setSubsanando(null)} /></div>}
      {enlazando?.tipo.id === tipo.id && <EnZona titulo={tituloPaso('Completar los datos que acredita', tipo)}><div className="mt-4"><FormularioEnlazado usuario={usuario} tipo={enlazando.tipo} documentoId={enlazando.documentoId} onDone={enlazado} /></div></EnZona>}
    </li>} />
  </section>
}

function Controversias({ usuario, data, bloque, sinLegajo }) {
  const tipo = tipoControversiaDe[bloque]
  const nombre = tipo === 'individual' ? 'individuales' : 'colectivas'
  return <Registros documento={record => <DocumentoDeRegistro tipos={data.tipos} record={record} />} titulo={`Controversias ${nombre}`} agregar="Registrar controversia" registros={data.controversias.filter(c => c.values.tipo === tipo)} bloqueo={sinLegajo} permisos={permisosSeccion09}
    adjuntar={record => <Adjuntar usuario={usuario} data={data} record={record} etiqueta={etiquetaControversia(record)} sinLegajo={sinLegajo} />}
    vacio={`No hay controversias ${nombre} registradas. No todas las personas las tienen.`}
    tituloRegistro={c => c.values.materia} subtitulo={c => `${etiqueta(instancias, c.values.instancia)} · desde ${fecha(c.values.fecha_inicio)}`}
    campos={[
      ['Estado', v => etiqueta(estadosControversia, v.estado)],
      ['Fecha de resolución', v => fecha(v.fecha_resolucion)],
      ['Resultado', v => texto(v.resultado)],
    ]}
    onAnular={(record, motivo) => anularSeccion09(usuario, 'controversias', record.id, motivo)}
    renderForm={props => <ControversiaForm key={props.record?.id || 'nuevo'} usuario={usuario} tipo={tipo} {...props} />} />
}

function Afiliaciones({ usuario, data, sinLegajo }) {
  return <Registros documento={record => <DocumentoDeRegistro tipos={data.tipos} record={record} />} titulo="Afiliación al sindicato" agregar="Registrar afiliación" registros={data.afiliaciones} bloqueo={sinLegajo} permisos={permisosSeccion09}
    adjuntar={record => <Adjuntar usuario={usuario} data={data} record={record} etiqueta={etiquetaAfiliacion(record)} sinLegajo={sinLegajo} />}
    vacio="No hay afiliación sindical registrada. No todas las personas están afiliadas."
    tituloRegistro={a => a.values.entidad} subtitulo={a => `Desde ${fecha(a.values.fecha_inicio)}${a.values.fecha_fin ? ` hasta ${fecha(a.values.fecha_fin)}` : ''}`}
    campos={[['N.º de afiliación', v => texto(v.numero_afiliacion)]]}
    onAnular={(record, motivo) => anularSeccion09(usuario, 'afiliaciones', record.id, motivo)}
    renderForm={props => <AfiliacionForm key={props.record?.id || 'nuevo'} usuario={usuario} {...props} />} />
}

export default function Seccion09Panel({ usuario, categoria }) {
  const request = useLiveQuery(`/expedientes/${usuario}/seccion-09`)
  const data = request.data?.data
  const { bloque, titulo, literales } = categoriasSeccion09[categoria]
  const error = request.error && <p role="alert" className="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{request.error.response?.status === 403 ? 'No tienes permiso para ver la sección 09 (datos sensibles).' : 'No se pudo actualizar la sección. Lo que ves puede no estar al día.'} <button onClick={request.retry} className="font-semibold underline">Reintentar</button></p>
  if (!data) return error || <p role="status" className="rounded-xl bg-slate-100 p-6 text-sm text-slate-500">Cargando…</p>
  const sinLegajo = !data.legajo && 'La persona no tiene un legajo abierto.'

  const tipos = data.tipos.filter(tipo => tipo.bloque === bloque)
  const registros = bloque in tipoControversiaDe ? data.controversias.filter(c => c.values.tipo === tipoControversiaDe[bloque]).length : bloque === 'afiliacion' ? data.afiliaciones.length : undefined
  return <EspacioSeccion seccion={tituloSeccion('09')} subseccion={titulo} atajos={atajosDe({ registros, tipos })} etiquetaResumen="Acerca de esta sección"
    resumen={<p className="text-slate-500">Sección 09 · Relaciones laborales individuales y colectivas{literales ? ` · literal ${literales} del Anexo 01` : ' · documentos requeridos por la universidad'}. Información sensible: solo con acceso a datos sensibles.</p>}>
    {error}
    {sinLegajo && <Aviso accion={<AbrirLegajo usuario={usuario} />}>{sinLegajo} Los formularios y la carga de documentos de la sección 09 están deshabilitados hasta abrirlo.</Aviso>}
    {/* Primero los documentos (qué falta, qué hay, qué es opcional); después los registros que acreditan. */}
    <Documentos usuario={usuario} data={data} tipos={tipos} sinLegajo={sinLegajo} />
    {registros !== undefined && <div id={idBloque.registros} className="mt-8 scroll-mt-4">
      {bloque in tipoControversiaDe && <Controversias usuario={usuario} data={data} bloque={bloque} sinLegajo={sinLegajo} />}
      {bloque === 'afiliacion' && <Afiliaciones usuario={usuario} data={data} sinLegajo={sinLegajo} />}
    </div>}
  </EspacioSeccion>
}
