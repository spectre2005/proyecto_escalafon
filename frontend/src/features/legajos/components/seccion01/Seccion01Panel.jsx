import Aviso from '../documentos/AvisoSeccion'
import AvisosExpediente from '../documentos/AvisosExpediente'
import Icon from '../../../../components/ui/Icon'
import { useState } from 'react'
import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { usePuede } from '../../../auth/session'
import { anularRegistro } from '../../api/seccion01Api'
import { useNoAplicables } from '../../api/useNoAplicables'
import { motivoNoAplica, motivoVisible, ofrecerTipo, tiposQueAcreditan } from '../../config/aplicacion'
import { precargaDesdeRegistro } from '../../config/acreditacion'
import { folios, textoSubirTipo } from '../../config/documentos'
import { etiqueta, formulariosDatos01, gruposSeccion01, parentescos, personasCertificado, resultadosDeclaracion, tiposDeclaracion, tiposDocIdentidad } from '../../config/seccion01'
import { coloresEstadoDocumento, estadosDocumento } from '../../config/seccion02'
import AbrirLegajo from '../AbrirLegajo'
import EspacioSeccion, { DocumentoDeRegistro, EnZona, SinAsociar } from '../documentos/EspacioSeccion'
import { atajosDe, idBloque, idTipo, mostrarEstado, tituloPaso, tituloSeccion } from '../../config/espacioSeccion'
import AdjuntarDocumento from '../documentos/AdjuntarDocumento'
import GruposTipos from '../documentos/GruposTipos'
import ListaDocumentos from '../documentos/ListaDocumentos'
import EnlazarDocumento from '../documentos/EnlazarDocumento'
import SubirDocumentoForm from '../documentos/SubirDocumentoForm'
import Registros from '../seccion02/Registros'
import FamiliarForm from './FamiliarForm'
import { CertificadoForm, DeclaracionForm } from './Formularios'
import RegistroPdf from './RegistroPdf'

const fecha = value => value ? value.split('-').reverse().join('/') : 'No registrada'
const enlace = 'text-sm font-semibold text-teal-700 disabled:opacity-50'
// TODO pendiente de decidir si manda el Anexo 01 o el 05: estos tipos de declaración solo figuran en el Anexo 05.
const declaracionesAnexo05 = ['antecedente_judicial', 'dj_intereses']
const relacionPorTipo = { S01_I_DNI_CONYUGE_CONCUBINO: 'conyuge_concubino', S01_J_DNI_HIJOS_MENORES: 'hijo' }

const exigencia = {
  true: ['Se exige', 'bg-teal-50 text-teal-800'],
  false: ['No se exige', 'bg-slate-100 text-slate-600'],
  null: ['Por determinar', 'bg-slate-50 text-slate-600'],
}



function Etiqueta({ children, clase }) {
  return <span className={`rounded-full px-2.5 py-1 text-[11px] font-medium ${clase}`}>{children}</span>
}

function Anexo05() {
  return <Etiqueta clase="bg-slate-50 text-slate-600">Pendiente de confirmar (Anexo 05)</Etiqueta>
}

// Resumen de la sección: exigencias (una por tipo; en i y j, una por familiar). Pendientes, cargados y recepcionados
// van por separado: la fórmula de completitud la define el cliente. Lo que no se exige no cuenta como pendiente.
// Dentro de la cabecera (EspacioSeccion) va plegado.
function Resumen({ data, compacto = false }) {
  const r = data.resumen
  return <section aria-label="Resumen de la sección 01" className={compacto ? '' : 'mb-8 rounded-2xl border border-teal-100 bg-white p-5'}>
    <div className="flex flex-wrap gap-x-10 gap-y-4">
      {[['Exigidos pendientes', r.pendientes], ['Exigidos con documento cargado', r.cargados], ['Exigidos con recepción registrada', r.recepcionados]].map(([titulo, valor]) =>
        <div key={titulo}><p className="text-xs text-slate-500">{titulo}</p><p className="mt-1 text-2xl font-bold text-slate-800">{valor} <span className="text-sm font-normal text-slate-500">de {r.exigidos}</span></p></div>)}
    </div>
    <p className="mt-3 text-xs text-slate-500">Cargados y recepcionados se muestran por separado: el criterio de completitud del legajo está pendiente de definir. Sin recepción un documento no cuenta como aprobado. Los documentos de familiares se cuentan uno por familiar.{r.por_determinar ? ` ${r.por_determinar} exigencia(s) no se pueden determinar (falta un dato, como la fecha de nacimiento).` : ''}</p>
    {r.detalle.length > 0 && <details className="mt-4"><summary className="cursor-pointer text-xs font-semibold text-teal-700">Documentos que se exigen a esta persona ({r.detalle.length})</summary>
      <ul className="mt-3 grid gap-1 text-xs text-slate-600 sm:grid-cols-2">{r.detalle.map(item => <li key={item.nombre}>{item.literal ? `${item.literal}) ` : ''}{item.nombre} — {item.recepcionado ? 'recepcionado' : item.cargado ? 'cargado' : 'pendiente'}</li>)}</ul>
    </details>}
  </section>
}

// Registros que un documento puede acreditar: del parentesco o tipo de declaración que corresponde y sin documento aún.
// Registros del formulario de datos del tipo (para precargar el documento con el registro elegido).
function registrosDe(tipo, data) {
  return { declaraciones_verificaciones: data.declaraciones, familiares: data.familiares, certificados_discapacidad: data.certificados }[tipo.formulario_datos] || []
}

// Datos del registro que corresponden al documento: fecha, número o, si es de un familiar, su titular.
function precargaDe(tipo, data, registroId) {
  return precargaDesdeRegistro(tipo, registrosDe(tipo, data).find(record => record.id === registroId)?.values, { familiares: data.familiares })
}

// «Adjuntar documento» en la tarjeta del registro, con los tipos que pueden acreditarlo.
function Adjuntar({ usuario, data, record, etiqueta: texto, noAplica }) {
  const tipos = tiposQueAcreditan(data.tipos, noAplica, tipo => enlacesDe(tipo, data), record.id)
  return <AdjuntarDocumento usuario={usuario} seccion={1} record={record} etiqueta={texto} tipos={tipos} titular={data.titular}
    relacion={tipo => relacionPorTipo[tipo.codigo]} precarga={(tipo, id) => precargaDe(tipo, data, id)} bloqueo={!data.legajo && 'La persona no tiene un legajo abierto.'} />
}

function enlacesDe(tipo, data) {
  const libres = lista => lista.filter(record => !record.values.documento_id)
  if (tipo.formulario_datos === 'declaraciones_verificaciones') return libres(data.declaraciones).filter(r => tipo.declaraciones?.includes(r.values.tipo)).map(r => ({ id: r.id, etiqueta: `${etiqueta(tiposDeclaracion, r.values.tipo)} · ${fecha(r.values.fecha)}` }))
  if (tipo.formulario_datos === 'familiares') return libres(data.familiares).filter(r => tipo.parentescos?.includes(r.values.parentesco)).map(r => ({ id: r.id, etiqueta: `${r.values.apellidos_nombres} · ${etiqueta(parentescos, r.values.parentesco)}` }))
  if (tipo.formulario_datos === 'certificados_discapacidad') return libres(data.certificados).map(r => ({ id: r.id, etiqueta: `CONADIS ${r.values.numero_conadis} · ${r.values.persona === 'familiar' ? r.familiar || 'familiar' : 'del servidor(a)'}` }))
  return null
}

// Registro de datos que acredita el documento (enlace guardado al subirlo o al registrar los datos).
function enlazadoA(tipo, documento, data) {
  const lista = { declaraciones_verificaciones: data.declaraciones, familiares: data.familiares, certificados_discapacidad: data.certificados }[tipo.formulario_datos]
  if (!lista) return null
  const registro = lista.find(r => r.values.documento_id === documento.id)
  if (!registro) return `Sin ${formulariosDatos01[tipo.formulario_datos].toLowerCase()} enlazado`
  if (tipo.formulario_datos === 'familiares') return `${registro.values.apellidos_nombres} (${etiqueta(parentescos, registro.values.parentesco).toLowerCase()})`
  if (tipo.formulario_datos === 'certificados_discapacidad') return `Certificado CONADIS ${registro.values.numero_conadis}`
  return `${etiqueta(tiposDeclaracion, registro.values.tipo)} · ${fecha(registro.values.fecha)}`
}

// Formulario de datos que se abre al subir un documento cuyo tipo lo tiene, enlazado a ese documento.
function FormularioEnlazado({ usuario, data, tipo, documentoId, onDone }) {
  const props = { usuario, documentoId, onSaved: () => onDone(true), onCancel: () => onDone(false) }
  if (tipo.formulario_datos === 'familiares') return <FamiliarForm parentescosPermitidos={tipo.parentescos} sinDerechohabientes={data.banderas?.tiene_derechohabientes === false} {...props} />
  if (tipo.formulario_datos === 'declaraciones_verificaciones') return <DeclaracionForm tipos={tipo.declaraciones} {...props} />
  if (tipo.formulario_datos === 'certificados_discapacidad') return <CertificadoForm familiares={data.familiares} {...props} />
  return null
}

// Tipos de documento del grupo: exigencia, estado, documentos (folios, recepción, visor) y carga del PDF con sus
// metadatos (documento.subir). Un documento no se elimina ni se reemplaza: se subsana con uno nuevo.
function Documentos({ usuario, data, codigos }) {
  const puedeSubir = usePuede('documento.subir')
  const noAplica = useNoAplicables(usuario)
  const [subiendo, setSubiendo] = useState(null)
  const [enlazando, setEnlazando] = useState(null)
  const [subsanando, setSubsanando] = useState(null)
  // Documento ya cargado que quedó sin registro: se enlaza a uno existente o se registran sus datos.
  const [recuperando, setRecuperando] = useState(null)
  const [aviso, setAviso] = useState('')
  const catalogo = codigos.map(codigo => data.tipos.find(tipo => tipo.codigo === codigo)).filter(Boolean)
  if (!catalogo.length) return <Aviso>El catálogo de documentos de la sección 01 no está cargado en la base de datos (Seccion01CatalogoSeeder).</Aviso>
  const sinLegajo = !data.legajo
  const ocupado = !!subiendo || !!enlazando || !!subsanando || !!recuperando
  function subido(tipo, documento) {
    setSubiendo(null)
    setSubsanando(null)
    // Una subsanación no crea otro registro: el anterior sigue acreditado por la cadena de subsanación.
    const abre = documento.formulario_datos && !documento.enlazado && !documento.subsana_a_documento_id
    setAviso(`Documento cargado (folios ${folios(documento.folio_inicio, documento.folio_fin)}).${abre ? ` Registra los datos de ${formulariosDatos01[documento.formulario_datos].toLowerCase()} que acredita.` : ''}`)
    if (abre) setEnlazando({ tipoId: tipo.id, documentoId: documento.id })
  }
  function enlazado(guardado) {
    setEnlazando(null)
    setAviso(guardado ? 'Datos guardados y enlazados al documento.' : 'El documento quedó cargado sin registro de datos enlazado.')
  }
  const enlazar = puedeSubir && !sinLegajo && !ocupado ? (tipo, documento) => { setAviso(''); setRecuperando({ tipoId: tipo.id, documento }) } : null
  return <section id={idBloque.documentos} aria-label="Documentos" className="scroll-mt-4">
    <h3 className="mb-1 text-base font-semibold text-slate-800">Documentos</h3>
    <p className="mb-4 text-xs text-slate-500">Sube cada documento desde su tipo. Si acredita un registro, también puedes adjuntarlo desde la tarjeta del registro.{sinLegajo && puedeSubir ? ' La persona no tiene un legajo abierto: la carga está deshabilitada.' : ''}</p>
    {aviso && <p role="status" className="mb-4 rounded-lg bg-teal-50 p-3 text-sm text-teal-800">{aviso}</p>}
    <SinAsociar tipos={catalogo} onEnlazar={enlazar} />
    {/* Solo los tipos que corresponden a la persona (o que ya tienen documentos), agrupados. */}
    <GruposTipos tipos={catalogo} noAplica={noAplica} puedeSubir={tipo => puedeSubir && ofrecerTipo(noAplica, tipo) && !!textoSubirTipo(tipo)}
      deshabilitado={() => ocupado || !!sinLegajo}
      seleccionado={subiendo || subsanando?.tipoId || enlazando?.tipoId || recuperando?.tipoId}
      onSubir={tipo => { setAviso(''); setSubiendo(tipo.id) }} flujos={tipo => <>
        {subiendo === tipo.id && <div className="mt-4"><SubirDocumentoForm usuario={usuario} seccion={1} tipo={tipo} titular={data.titular} relacion={relacionPorTipo[tipo.codigo]} enlaces={enlacesDe(tipo, data)} precarga={id => precargaDe(tipo, data, id)} onSaved={documento => subido(tipo, documento)} onCancel={() => setSubiendo(null)} /></div>}
        {recuperando?.tipoId === tipo.id && <EnZona titulo={tituloPaso('Enlazar', tipo, recuperando.documento)}><EnlazarDocumento usuario={usuario} documento={recuperando.documento} opciones={enlacesDe(tipo, data)}
          onRegistrarNuevo={() => { setEnlazando({ tipoId: tipo.id, documentoId: recuperando.documento.id }); setRecuperando(null) }}
          onDone={registro => { setRecuperando(null); setAviso(`Documento enlazado${registro ? ` a: ${registro}` : ''}.`) }} onCancel={() => setRecuperando(null)} /></EnZona>}
        {subsanando?.tipoId === tipo.id && <div className="mt-4"><SubirDocumentoForm usuario={usuario} seccion={1} tipo={tipo} titular={data.titular} relacion={relacionPorTipo[tipo.codigo]} subsanar={subsanando.documento} onSaved={documento => subido(tipo, documento)} onCancel={() => setSubsanando(null)} /></div>}
        {enlazando?.tipoId === tipo.id && <EnZona titulo={tituloPaso('Completar los datos que acredita', tipo)}><div className="mt-4"><FormularioEnlazado usuario={usuario} data={data} tipo={tipo} documentoId={enlazando.documentoId} onDone={enlazado} /></div></EnZona>}
      </>} render={tipo => {
      const [exigeTexto, exigeClase] = tipo.aplica ? exigencia[String(tipo.exigido)] : ['No aplica', 'bg-slate-100 text-slate-500']
      return <li key={tipo.id} id={idTipo(tipo.id)} className={`scroll-mt-4 p-4 ${tipo.aplica ? '' : 'opacity-60'}`}>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0 flex-1">
            <p className="text-sm font-medium text-slate-800">{tipo.literal && <span className="mr-1 text-slate-500">{tipo.literal})</span>}{tipo.nombre}</p>
            <p className={`mt-1 text-xs ${tipo.aplica && tipo.exigido === null ? 'text-slate-600' : 'text-slate-500'}`}>{motivoVisible(tipo)}{tipo.permite_multiples ? ' Admite varios documentos.' : ''}{tipo.formulario_datos ? ` Datos: ${formulariosDatos01[tipo.formulario_datos]}.` : ''}</p>
            <ListaDocumentos usuario={usuario} tipo={tipo} enlazado={documento => enlazadoA(tipo, documento, data)}
              onSubsanar={puedeSubir && !sinLegajo && !ocupado ? documento => { setAviso(''); setSubsanando({ tipoId: tipo.id, documento }) } : null}
              onEnlazar={puedeSubir && !sinLegajo && !ocupado && ofrecerTipo(noAplica, tipo) ? documento => { setAviso(''); setRecuperando({ tipoId: tipo.id, documento }) } : null} />
          </div>
          <div className="flex shrink-0 flex-wrap items-center gap-2">
            {tipo.solo_anexo_05 && <Anexo05 />}
            <Etiqueta clase={exigeClase}>{exigeTexto}</Etiqueta>
            {mostrarEstado(tipo) && <Etiqueta clase={coloresEstadoDocumento[tipo.estado] || 'bg-slate-100 text-slate-700'}>{etiqueta(estadosDocumento, tipo.estado)}{tipo.documentos > 1 ? ` · ${tipo.documentos} documentos` : ''}</Etiqueta>}
            {puedeSubir && ofrecerTipo(noAplica, tipo) && textoSubirTipo(tipo) && <button disabled={ocupado || sinLegajo} onClick={() => { setAviso(''); setSubiendo(tipo.id) }} className={`${enlace} inline-flex items-center gap-2`}><Icon name="paperclip" size={18} />{textoSubirTipo(tipo)}</button>}
          </div>
        </div>
      </li>
    }} />
  </section>
}

// PDF guardado antes del sistema de folios (pdf_path): solo lectura, sin folios ni metadatos inventados.
function PdfAnterior({ usuario, tipo, record }) {
  if (!record.pdf_anterior) return null
  return <div className="mt-4 rounded-xl bg-slate-50 p-3">
    <Etiqueta clase="bg-slate-50 text-slate-600">Registrado antes del sistema de folios: metadatos pendientes</Etiqueta>
    <RegistroPdf usuario={usuario} tipo={tipo} record={{ id: record.id, values: { pdf_nombre: record.pdf_anterior } }} />
  </div>
}

// Registros anulados: no cuentan ni salen en los listados. Solo administración (el backend no los envía a los demás).
function Anulados({ registros, titulo }) {
  const [abierto, setAbierto] = useState(false)
  if (!registros?.length) return null
  return <div className="-mt-4 mb-8">
    <button onClick={() => setAbierto(!abierto)} className="text-xs font-semibold text-slate-500 hover:text-slate-700">{abierto ? 'Ocultar anulados' : `Ver anulados (${registros.length})`}</button>
    {abierto && <div className="mt-3 space-y-3">{registros.map(record => <article key={record.id} className="rounded-2xl border border-rose-100 bg-white p-4 opacity-75">
      <div className="flex flex-wrap items-start justify-between gap-2"><h4 className="text-sm font-semibold text-slate-700 line-through decoration-rose-300">{titulo(record)}</h4><Etiqueta clase="bg-rose-50 text-rose-700">Anulado</Etiqueta></div>
      <p className="mt-2 text-xs text-slate-600"><span className="text-rose-700">Motivo de anulación: </span>{record.values.motivo_anulacion}</p>
    </article>)}</div>}
  </div>
}

function Declaraciones({ usuario, data, codigos, titulo }) {
  const tipos = [...new Set(codigos.flatMap(codigo => data.tipos.find(tipo => tipo.codigo === codigo)?.declaraciones || []))]
  const registros = data.declaraciones.filter(r => tipos.includes(r.values.tipo))
  const tituloRegistro = record => etiqueta(tiposDeclaracion, record.values.tipo)
  const noAplica = useNoAplicables(usuario)
  // DJBR con «Obligado a presentar declaración jurada de bienes y rentas» en No: no se ofrece como registro nuevo (el
  // backend la rechaza); una DJBR ya registrada se sigue corrigiendo.
  const tiposNuevos = motivoNoAplica(noAplica, { codigo: 'S01_E_DJ_BIENES_RENTAS' }) ? tipos.filter(tipo => tipo !== 'djbr') : tipos
  return <>
    <Registros documento={record => <DocumentoDeRegistro tipos={data.tipos} record={record} />} titulo={titulo} agregar={tiposNuevos.length ? 'Registrar' : undefined} vacio="No hay registros." registros={registros}
      adjuntar={record => <Adjuntar usuario={usuario} data={data} record={record} etiqueta={`${tituloRegistro(record)} · ${fecha(record.values.fecha)}`} noAplica={noAplica} />}
      tituloRegistro={record => <>{tituloRegistro(record)}{declaracionesAnexo05.includes(record.values.tipo) && <span className="ml-2 align-middle"><Anexo05 /></span>}</>}
      subtitulo={record => `${fecha(record.values.fecha)}${record.values.anio ? ` · Año ${record.values.anio}` : ''}`}
      campos={[['Resultado', v => etiqueta(resultadosDeclaracion, v.resultado)], ['Vigente hasta', v => v.vigente_hasta ? fecha(v.vigente_hasta) : 'Sin vencimiento registrado'], ['Observación', v => v.observacion || 'Sin observación']]}
      corregir={{ etiqueta: 'Corregir', puede: () => true }}
      onAnular={(record, motivo) => anularRegistro(usuario, 'declaraciones-verificaciones', record.id, motivo)}
      detalle={record => <PdfAnterior usuario={usuario} tipo="declaraciones-verificaciones" record={record} />}
      renderForm={props => <DeclaracionForm key={props.record?.id || 'nuevo'} usuario={usuario} tipos={props.record ? tipos : tiposNuevos} {...props} />} />
    <Anulados registros={data.anulados?.declaraciones.filter(r => tipos.includes(r.values.tipo))} titulo={tituloRegistro} />
  </>
}

function Familia({ usuario, data }) {
  const titulo = record => record.values.apellidos_nombres
  const noAplica = useNoAplicables(usuario)
  return <>
    <Registros documento={record => <DocumentoDeRegistro tipos={data.tipos} record={record} />} titulo="Familiares y derechohabientes" agregar="Agregar familiar" vacio="No hay familiares registrados." registros={data.familiares}
      adjuntar={record => <Adjuntar usuario={usuario} data={data} record={record} etiqueta={`${titulo(record)} · ${etiqueta(parentescos, record.values.parentesco)}`} noAplica={noAplica} />}
      tituloRegistro={titulo} subtitulo={record => etiqueta(parentescos, record.values.parentesco)}
      campos={[
        ['Documento', v => v.numero_doc_identidad ? `${etiqueta(tiposDocIdentidad, v.tipo_doc_identidad)} ${v.numero_doc_identidad}` : 'No registrado'],
        ['Fecha de nacimiento', (v, record) => v.fecha_nacimiento ? `${fecha(v.fecha_nacimiento)} · ${record.edad} años${record.menor_de_edad ? ' (menor de edad)' : ''}` : 'No registrada: no se puede calcular la edad'],
        ['Derechohabiente', v => v.es_derechohabiente ? 'Sí' : 'No'],
      ]}
      detalle={record => record.exigencias.length > 0 && <ul className="mt-4 space-y-2">{record.exigencias.map(item => {
        const [texto, clase] = exigencia[String(item.exigido)]
        return <li key={item.codigo} className="flex flex-wrap items-center gap-2 rounded-xl bg-slate-50 p-3 text-xs text-slate-600">
          <span className="font-medium text-slate-700">{item.literal}) DNI</span><Etiqueta clase={clase}>{texto}</Etiqueta>
          <Etiqueta clase={item.recepcionado ? coloresEstadoDocumento.recepcionado : item.cargado ? coloresEstadoDocumento.cargado : 'bg-slate-100 text-slate-600'}>{item.recepcionado ? 'Recepcionado' : item.cargado ? 'Cargado' : 'Sin documento'}</Etiqueta>
          <span>{item.motivo}</span>
        </li>
      })}</ul>}
      corregir={{ etiqueta: 'Corregir', puede: () => true }}
      onAnular={(record, motivo) => anularRegistro(usuario, 'familiares', record.id, motivo)}
      renderForm={props => <FamiliarForm key={props.record?.id || 'nuevo'} usuario={usuario} sinDerechohabientes={data.banderas?.tiene_derechohabientes === false} {...props} />} />
    <Anulados registros={data.anulados?.familiares} titulo={titulo} />
  </>
}

function Discapacidad({ usuario, data, aplica }) {
  const titulo = record => `Certificado CONADIS ${record.values.numero_conadis}`
  const noAplica = useNoAplicables(usuario)
  return <>
    {!aplica && <p className="mb-5 rounded-xl border border-slate-200 bg-slate-50 p-4 text-xs text-slate-500">No aplica: la persona figura sin discapacidad (el servidor ni sus hijos). Los certificados ya registrados se conservan; para registrar uno nuevo, cambia «Con discapacidad» a Sí en Información personal.</p>}
    {/* Con «Con discapacidad» en No no se ofrecen certificados nuevos (el backend los rechaza). */}
    <Registros documento={record => <DocumentoDeRegistro tipos={data.tipos} record={record} />} titulo="Certificados de discapacidad" agregar={aplica ? 'Registrar certificado' : undefined} vacio="No hay certificados de discapacidad registrados." registros={data.certificados}
      adjuntar={record => <Adjuntar usuario={usuario} data={data} record={record} etiqueta={titulo(record)} noAplica={noAplica} />}
      tituloRegistro={titulo} subtitulo={record => record.values.persona === 'familiar' ? `De un familiar: ${record.familiar || 'no identificado'}` : etiqueta(personasCertificado, 'servidor')}
      campos={[['Fecha de emisión', v => fecha(v.fecha_emision)], ['Vigente hasta', v => v.vigente_hasta ? fecha(v.vigente_hasta) : 'Sin vencimiento registrado']]}
      corregir={{ etiqueta: 'Corregir', puede: () => true }}
      onAnular={(record, motivo) => anularRegistro(usuario, 'certificados-discapacidad', record.id, motivo)}
      detalle={record => <PdfAnterior usuario={usuario} tipo="certificados-discapacidad" record={record} />}
      renderForm={props => <CertificadoForm key={props.record?.id || 'nuevo'} usuario={usuario} familiares={data.familiares} {...props} />} />
    <Anulados registros={data.anulados?.certificados} titulo={titulo} />
  </>
}

/**
 * Sección 01 por grupos (config/seccion01.js): datos y documentos juntos, con el resumen arriba.
 * categoria "general" se muestra debajo de los datos personales de la ficha (LegajosPage).
 */
export default function Seccion01Panel({ usuario, categoria }) {
  const request = useLiveQuery(`/expedientes/${usuario}/seccion-01`)
  const data = request.data?.data
  const grupo = gruposSeccion01[categoria]
  const error = request.error && <p role="alert" className="mb-4 rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{request.error.response?.status === 403 ? 'No tienes permiso para ver esta sección.' : 'No se pudo actualizar la sección. Lo que ves puede no estar al día.'} <button onClick={request.retry} className="font-semibold underline">Reintentar</button></p>
  if (!data) return error || <p role="status" className="rounded-xl bg-slate-100 p-6 text-sm text-slate-500">Cargando…</p>
  const discapacidad = data.tipos.find(tipo => tipo.codigo === 'S01_K_CERTIFICADO_DISCAPACIDAD')

  const r = data.resumen
  // Mismo criterio que Declaraciones: los tipos de declaración que admiten los tipos documentales del grupo.
  const tiposDeclaracion = new Set(grupo.codigos.flatMap(codigo => data.tipos.find(tipo => tipo.codigo === codigo)?.declaraciones || []))
  const registros = ['s01-antecedentes', 's01-declaraciones'].includes(categoria) ? data.declaraciones.filter(d => tiposDeclaracion.has(d.values.tipo)).length
    : { 's01-familia': data.familiares?.length, 's01-discapacidad': data.certificados?.length }[categoria]
  const tipos = data.tipos.filter(tipo => grupo.codigos.includes(tipo.codigo))

  const contenido = <>
    {error}
    <AvisosExpediente usuario={usuario} avisos={data.avisos.filter(aviso => !aviso.startsWith('Falta definir'))} />
    {!data.legajo && <Aviso accion={<AbrirLegajo usuario={usuario} />}>La persona no tiene un legajo abierto. La carga de documentos de la sección 01 está deshabilitada hasta abrirlo.</Aviso>}
    {/* Primero los documentos (qué falta, qué hay, qué es opcional); después los registros que acreditan. */}
    <Documentos usuario={usuario} data={data} codigos={grupo.codigos} />
    <div id={idBloque.registros} className="mt-8 scroll-mt-4">
    {categoria === 's01-antecedentes' && <Declaraciones usuario={usuario} data={data} codigos={grupo.codigos} titulo="Antecedentes e impedimentos registrados" />}
    {categoria === 's01-declaraciones' && <Declaraciones usuario={usuario} data={data} codigos={grupo.codigos} titulo="Declaraciones juradas registradas" />}
    {categoria === 's01-familia' && <Familia usuario={usuario} data={data} />}
    {categoria === 's01-discapacidad' && <Discapacidad usuario={usuario} data={data} aplica={discapacidad?.aplica !== false} />}
    </div>
  </>
  // «Datos personales e identidad» va bajo la información personal de la ficha: sin cabecera propia.
  if (categoria === 'general') return <section aria-label={grupo.titulo} className="mt-6">{contenido}</section>
  return <EspacioSeccion seccion={tituloSeccion('01')} subseccion={grupo.titulo} cifras={`Sección: ${r.pendientes} de ${r.exigidos} exigidos pendientes · ${r.cargados} con documento, ${r.recepcionados} con recepción.`}
    resumen={<Resumen data={data} compacto />} atajos={atajosDe({ registros, tipos })}>{contenido}</EspacioSeccion>
}
