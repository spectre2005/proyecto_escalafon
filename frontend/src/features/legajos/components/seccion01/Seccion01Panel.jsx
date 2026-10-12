import Icon from '../../../../components/ui/Icon'
import { useState } from 'react'
import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { usePuede } from '../../../auth/session'
import { anularRegistro } from '../../api/seccion01Api'
import { useNoAplicables } from '../../api/useNoAplicables'
import { mostrarTipo, ofrecerTipo } from '../../config/aplicacion'
import { folios } from '../../config/documentos'
import { etiqueta, formulariosDatos01, gruposSeccion01, parentescos, personasCertificado, resultadosDeclaracion, tiposDeclaracion, tiposDocIdentidad } from '../../config/seccion01'
import { coloresEstadoDocumento, estadosDocumento } from '../../config/seccion02'
import AbrirLegajo from '../AbrirLegajo'
import ListaDocumentos from '../documentos/ListaDocumentos'
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
  null: ['Por determinar', 'bg-amber-50 text-amber-800'],
}

function Aviso({ children, accion }) {
  return <div role="note" className="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><div>{children}</div>{accion}</div>
}

function Etiqueta({ children, clase }) {
  return <span className={`rounded-full px-2.5 py-1 text-[11px] font-medium ${clase}`}>{children}</span>
}

function Anexo05() {
  return <Etiqueta clase="bg-amber-50 text-amber-800">Pendiente de confirmar (Anexo 05)</Etiqueta>
}

// Resumen de la sección: exigencias (una por tipo; en i y j, una por familiar). Pendientes, cargados y recepcionados
// van por separado: la fórmula de completitud la define el cliente. Lo que no se exige no cuenta como pendiente.
function Resumen({ data }) {
  const r = data.resumen
  return <section aria-label="Resumen de la sección 01" className="mb-8 rounded-2xl border border-teal-100 bg-white p-5">
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
  if (tipo.formulario_datos === 'familiares') return <FamiliarForm parentescosPermitidos={tipo.parentescos} {...props} />
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
  const [aviso, setAviso] = useState('')
  const catalogo = codigos.map(codigo => data.tipos.find(tipo => tipo.codigo === codigo)).filter(Boolean)
  // Solo los tipos que corresponden a la persona (o que ya tienen documentos).
  const tipos = catalogo.filter(tipo => mostrarTipo(noAplica, tipo))
  if (!catalogo.length) return <Aviso>El catálogo de documentos de la sección 01 no está cargado en la base de datos (Seccion01CatalogoSeeder).</Aviso>
  const sinLegajo = !data.legajo
  const ocupado = !!subiendo || !!enlazando
  function subido(tipo, documento) {
    setSubiendo(null)
    const abre = documento.formulario_datos && !documento.enlazado
    setAviso(`Documento cargado (folios ${folios(documento.folio_inicio, documento.folio_fin)}).${abre ? ` Registra los datos de ${formulariosDatos01[documento.formulario_datos].toLowerCase()} que acredita.` : ''}`)
    if (abre) setEnlazando({ tipoId: tipo.id, documentoId: documento.id })
  }
  function enlazado(guardado) {
    setEnlazando(null)
    setAviso(guardado ? 'Datos guardados y enlazados al documento.' : 'El documento quedó cargado sin registro de datos enlazado.')
  }
  return <section aria-label="Documentos" className="mt-8">
    <h3 className="mb-1 text-base font-semibold text-slate-800">Documentos</h3>
    <p className="mb-4 text-xs text-slate-500">Cada documento se sube en PDF con sus metadatos. Los folios son correlativos en toda la sección 01.{sinLegajo && puedeSubir ? ' La persona no tiene un legajo abierto: la carga está deshabilitada.' : ''}</p>
    {aviso && <p role="status" className="mb-4 rounded-lg bg-teal-50 p-3 text-sm text-teal-800">{aviso}</p>}
    <ul className="divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200 bg-white">{tipos.map(tipo => {
      const [exigeTexto, exigeClase] = tipo.aplica ? exigencia[String(tipo.exigido)] : ['No aplica', 'bg-slate-100 text-slate-500']
      return <li key={tipo.id} className={`p-4 ${tipo.aplica ? '' : 'opacity-60'}`}>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0 flex-1">
            <p className="text-sm font-medium text-slate-800">{tipo.literal && <span className="mr-1 text-slate-500">{tipo.literal})</span>}{tipo.nombre}</p>
            <p className={`mt-1 text-xs ${tipo.aplica && tipo.exigido === null ? 'text-amber-800' : 'text-slate-500'}`}>{tipo.motivo}{tipo.permite_multiples ? ' Admite varios documentos.' : ''}{tipo.formulario_datos ? ` Datos: ${formulariosDatos01[tipo.formulario_datos]}.` : ''}</p>
            <ListaDocumentos usuario={usuario} tipo={tipo} enlazado={documento => enlazadoA(tipo, documento, data)} />
          </div>
          <div className="flex shrink-0 flex-wrap items-center gap-2">
            {tipo.solo_anexo_05 && <Anexo05 />}
            <Etiqueta clase={exigeClase}>{exigeTexto}</Etiqueta>
            <Etiqueta clase={coloresEstadoDocumento[tipo.estado] || 'bg-slate-100 text-slate-700'}>{etiqueta(estadosDocumento, tipo.estado)}{tipo.documentos > 1 ? ` · ${tipo.documentos} documentos` : ''}</Etiqueta>
            {puedeSubir && ofrecerTipo(noAplica, tipo) && <button disabled={ocupado || sinLegajo} onClick={() => { setAviso(''); setSubiendo(tipo.id) }} className={`${enlace} inline-flex items-center gap-2`}><Icon name="paperclip" size={18} />{tipo.documento && !tipo.permite_multiples ? 'Subsanar documento' : 'Subir documento'}</button>}
          </div>
        </div>
        {subiendo === tipo.id && <div className="mt-4"><SubirDocumentoForm usuario={usuario} seccion={1} tipo={tipo} titular={data.titular} relacion={relacionPorTipo[tipo.codigo]} enlaces={enlacesDe(tipo, data)} onSaved={documento => subido(tipo, documento)} onCancel={() => setSubiendo(null)} /></div>}
        {enlazando?.tipoId === tipo.id && <div className="mt-4"><FormularioEnlazado usuario={usuario} data={data} tipo={tipo} documentoId={enlazando.documentoId} onDone={enlazado} /></div>}
      </li>
    })}</ul>
  </section>
}

// PDF guardado antes del sistema de folios (pdf_path): solo lectura, sin folios ni metadatos inventados.
function PdfAnterior({ usuario, tipo, record }) {
  if (!record.pdf_anterior) return null
  return <div className="mt-4 rounded-xl bg-slate-50 p-3">
    <Etiqueta clase="bg-amber-50 text-amber-800">Registrado antes del sistema de folios: metadatos pendientes</Etiqueta>
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
  return <>
    <Registros titulo={titulo} agregar="Registrar" vacio="No hay registros." registros={registros}
      tituloRegistro={record => <>{tituloRegistro(record)}{declaracionesAnexo05.includes(record.values.tipo) && <span className="ml-2 align-middle"><Anexo05 /></span>}</>}
      subtitulo={record => `${fecha(record.values.fecha)}${record.values.anio ? ` · Año ${record.values.anio}` : ''}`}
      campos={[['Resultado', v => etiqueta(resultadosDeclaracion, v.resultado)], ['Vigente hasta', v => v.vigente_hasta ? fecha(v.vigente_hasta) : 'Sin vencimiento registrado'], ['Observación', v => v.observacion || 'Sin observación'], ['Documento', v => v.documento_id ? 'Enlazado a un documento del legajo' : 'Sin documento enlazado']]}
      corregir={{ etiqueta: 'Corregir', puede: () => true }}
      onAnular={(record, motivo) => anularRegistro(usuario, 'declaraciones-verificaciones', record.id, motivo)}
      detalle={record => <PdfAnterior usuario={usuario} tipo="declaraciones-verificaciones" record={record} />}
      renderForm={props => <DeclaracionForm key={props.record?.id || 'nuevo'} usuario={usuario} tipos={tipos} {...props} />} />
    <Anulados registros={data.anulados?.declaraciones.filter(r => tipos.includes(r.values.tipo))} titulo={tituloRegistro} />
  </>
}

function Familia({ usuario, data }) {
  const titulo = record => record.values.apellidos_nombres
  return <>
    <Registros titulo="Familiares y derechohabientes" agregar="Agregar familiar" vacio="No hay familiares registrados." registros={data.familiares}
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
      renderForm={props => <FamiliarForm key={props.record?.id || 'nuevo'} usuario={usuario} {...props} />} />
    <Anulados registros={data.anulados?.familiares} titulo={titulo} />
  </>
}

function Discapacidad({ usuario, data, aplica }) {
  const titulo = record => `Certificado CONADIS ${record.values.numero_conadis}`
  return <>
    {!aplica && <p className="mb-5 rounded-xl border border-slate-200 bg-slate-50 p-4 text-xs text-slate-500">No aplica: la persona no figura con discapacidad. Se puede registrar el certificado de un familiar si corresponde.</p>}
    <Registros titulo="Certificados de discapacidad" agregar="Registrar certificado" vacio="No hay certificados de discapacidad registrados." registros={data.certificados}
      tituloRegistro={titulo} subtitulo={record => record.values.persona === 'familiar' ? `De un familiar: ${record.familiar || 'no identificado'}` : etiqueta(personasCertificado, 'servidor')}
      campos={[['Fecha de emisión', v => fecha(v.fecha_emision)], ['Vigente hasta', v => v.vigente_hasta ? fecha(v.vigente_hasta) : 'Sin vencimiento registrado'], ['Documento', v => v.documento_id ? 'Enlazado a un documento del legajo' : 'Sin documento enlazado']]}
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

  return <section aria-label={grupo.titulo} className={categoria === 'general' ? 'mt-6' : ''}>
    {error}
    <Resumen data={data} />
    {/* El aviso de las condiciones del puesto ya dice dónde corregirlas (vínculo laboral, sección 02). */}
    {data.avisos.map(aviso => <Aviso key={aviso}>{aviso}{aviso.startsWith('No tiene un vínculo laboral vigente') ? '' : ' Revisa la bandera en Información personal o los registros.'}</Aviso>)}
    {!data.legajo && <Aviso accion={<AbrirLegajo usuario={usuario} />}>La persona no tiene un legajo abierto. La carga de documentos de la sección 01 está deshabilitada hasta abrirlo.</Aviso>}
    {categoria !== 'general' && <h2 className="mb-5 text-xl font-semibold text-slate-800">{grupo.titulo}</h2>}
    {categoria === 's01-antecedentes' && <Declaraciones usuario={usuario} data={data} codigos={grupo.codigos} titulo="Antecedentes e impedimentos registrados" />}
    {categoria === 's01-declaraciones' && <Declaraciones usuario={usuario} data={data} codigos={grupo.codigos} titulo="Declaraciones juradas registradas" />}
    {categoria === 's01-familia' && <Familia usuario={usuario} data={data} />}
    {categoria === 's01-discapacidad' && <Discapacidad usuario={usuario} data={data} aplica={discapacidad?.aplica !== false} />}
    <Documentos usuario={usuario} data={data} codigos={grupo.codigos} />
  </section>
}
