import { useEffect, useRef, useState } from 'react'
import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { apiErrorMessage } from '../../../../axios'
import Icon from '../../../../components/ui/Icon'
import { usePuede } from '../../../auth/session'
import AbrirLegajo from '../AbrirLegajo'
import EspacioSeccion from '../documentos/EspacioSeccion'
import VistasDocumentales from '../documentos/VistasDocumentales'
import { tituloSeccion } from '../../config/espacioSeccion'
import { vistaDeItem } from '../../config/vistasDocumentales'
import VisorPdf from '../documentos/VisorPdf'
import Revision03Form from '../seccion03/Revision03Form'
import Documento10Form from './Documento10Form'
import { anularDocumento10, revisarDocumento10 } from '../../api/seccion10Api'
import { useNoAplicables } from '../../api/useNoAplicables'
import { ofrecerTipo } from '../../config/aplicacion'

const boton = 'rounded-xl border border-teal-200 bg-teal-50 px-3 py-2 text-xs font-semibold text-teal-800 hover:bg-teal-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 disabled:opacity-50'
const botonIcono = 'inline-flex size-8 shrink-0 items-center justify-center rounded-lg border border-teal-200 bg-teal-50 text-teal-800 hover:bg-teal-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700'
const fecha = valor => valor ? valor.split('-').reverse().join('/') : '—'
const momento = valor => valor ? new Date(valor).toLocaleString('es-PE', { timeZone: 'America/Lima' }) : '—'
const estados = {
  si: ['Sí', 'bg-teal-50 text-teal-800'], no: ['No', 'bg-slate-100 text-slate-600'],
  pendiente: ['Pendiente', 'bg-amber-50 text-amber-900'], vencido: ['Vencido', 'bg-rose-50 text-rose-800'],
}
const acciones = {
  CREAR_DOCUMENTO: 'Registro creado', ADJUNTAR_ARCHIVO: 'Archivo adjuntado', ACTUALIZAR_DOCUMENTO: 'Metadatos actualizados',
  REEMPLAZAR_ARCHIVO: 'Nueva versión del archivo', COMPLETAR_TRAMITE: 'Trámite completado', DOCUMENTO_SUBSANADO: 'Sustituido por una nueva evidencia',
  ANULAR_DOCUMENTO: 'Documento anulado', DESCARGAR_DOCUMENTO: 'Documento descargado',
  'sst.documento.aceptado': 'Documento aceptado', 'sst.documento.observado': 'Documento observado',
}

function Modal({ titulo, onClose, children }) {
  const dialogo = useRef(null)
  useEffect(() => {
    const element = dialogo.current
    element.showModal()
    return () => element.close()
  }, [])
  function cerrar() {
    if (!dialogo.current.querySelector('form[aria-busy="true"]')) onClose()
  }
  return <dialog ref={dialogo} aria-label={titulo} onCancel={event => { event.preventDefault(); cerrar() }}
    className="m-auto max-h-[92vh] w-[min(96vw,1280px)] overflow-y-auto rounded-2xl border border-slate-200 bg-slate-50 p-4 shadow-xl backdrop:bg-slate-900/50 sm:p-6">
    <div className="mb-4 flex items-center justify-between gap-4"><h3 className="font-semibold text-slate-800">{titulo}</h3><button type="button" className={boton} onClick={cerrar}>Cerrar</button></div>
    {children}
  </dialog>
}

function Historial({ usuario, documento }) {
  const consulta = useLiveQuery(`/expedientes/${usuario}/seccion-10/documentos/${documento.id}/historial`)
  if (consulta.error) return <p role="alert" className="text-sm text-rose-700">{apiErrorMessage(consulta.error)} <button onClick={consulta.retry} className="underline">Reintentar</button></p>
  const data = consulta.data?.data
  if (!data) return <p role="status" className="text-sm text-slate-500">Consultando historial…</p>
  return <div className="space-y-4">
    <div><h4 className="mb-2 text-sm font-semibold text-slate-800">Operaciones</h4><ul className="space-y-2">{data.eventos.map(evento => <li key={evento.id} className="rounded-lg bg-slate-50 p-3 text-xs text-slate-600"><strong>{acciones[evento.accion] || evento.accion}</strong><p className="mt-1">{evento.autor} · {momento(evento.fecha)}</p></li>)}</ul></div>
    <div><h4 className="mb-2 text-sm font-semibold text-slate-800">Cambios de estado</h4><ul className="space-y-2">{data.estados.map(estado => <li key={estado.id} className="rounded-lg bg-slate-50 p-3 text-xs text-slate-600"><strong>{estado.estado_anterior || 'Sin registro'} → {estado.estado_nuevo}</strong><p className="mt-1">{estado.autor} · {momento(estado.fecha)}</p>{estado.motivo && <p className="mt-2 whitespace-pre-wrap">{estado.motivo}</p>}</li>)}</ul></div>
    <div><h4 className="mb-2 text-sm font-semibold text-slate-800">Accesos al archivo</h4>{data.accesos.length ? <ul className="space-y-2">{data.accesos.map(acceso => <li key={acceso.id} className="rounded-lg bg-slate-50 p-3 text-xs text-slate-600">{acceso.accion === 'descargar' ? 'Descarga' : 'Visualización'} · {acceso.autor} · {momento(acceso.fecha)}</li>)}</ul> : <p className="text-xs text-slate-500">Sin accesos registrados.</p>}</div>
  </div>
}

function Documentos({ item, permisos, abrir, ver, mostrarTitulo = true }) {
  const [incluirHistorial, setIncluirHistorial] = useState(false)
  const documentos = item.documentos.filter(doc => incluirHistorial || (!doc.anulado && !doc.subsanado))
  return <section aria-label={`Documentos de ${item.nombre}`} className="rounded-2xl border border-slate-200 bg-white p-5">
    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">{mostrarTitulo && <h3 className="font-semibold text-slate-800">{item.nombre}</h3>}<label className="flex items-center gap-2 text-xs text-slate-600"><input type="checkbox" className="accent-teal-700" checked={incluirHistorial} onChange={event => setIncluirHistorial(event.target.checked)} />Incluir anulados y versiones anteriores</label></div>
    {!documentos.length && <p className="text-sm text-slate-500">No hay documentos para esta consulta.</p>}
    <div className="space-y-4">{documentos.map(doc => <article key={doc.id} className="rounded-xl border border-slate-200 p-4">
      <div className="flex flex-wrap items-start justify-between gap-3"><h4 className="break-words font-semibold text-slate-800">{doc.nombre || doc.archivo?.nombre_original || 'Trámite sin nombre'}</h4><span className="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600">{doc.anulado ? 'Anulado' : doc.subsanado ? 'Versión anterior' : !doc.archivo ? 'Pendiente de archivo' : doc.estado_original}</span></div>
      <dl className="mt-4 grid gap-3 text-xs sm:grid-cols-2 xl:grid-cols-3">
        {[
          ['Fecha del documento', fecha(doc.fecha_emision)], ['Emisor', doc.emisor || '—'], ['Número de documento', doc.numero_documento || '—'],
          ['Número de folios', doc.numero_folios ? `${doc.numero_folios} (del ${doc.folio_inicio} al ${doc.folio_fin})` : 'Sin folios'],
          ['Archivo', doc.archivo ? `${doc.archivo.nombre_original} · PDF · ${Math.ceil(doc.archivo.tamano_bytes / 1024)} KB` : 'Falta adjuntar evidencia'],
          ['Registrado por', `${doc.creado_por} · ${momento(doc.creado_en)}`],
          ...(item.periodo ? [[item.entidad_eps ? 'Afiliación' : 'Inicio', fecha(doc.fecha_inicio)], [item.entidad_eps ? 'Desafiliación' : 'Fin', fecha(doc.fecha_fin)]] : []),
          ...(!item.entidad_eps && doc.numero_dias ? [['Número de días', doc.numero_dias]] : []),
          ...(item.vigencia ? [['Vencimiento', fecha(doc.vence_en)]] : []),
          ...(item.entidad_eps ? [['Entidad EPS', doc.entidad || '—'], ['Estado EPS', doc.estado_eps]] : []),
        ].map(([titulo, valor]) => <div key={titulo}><dt className="text-slate-500">{titulo}</dt><dd className="mt-1 break-words text-slate-800">{valor}</dd></div>)}
      </dl>
      {doc.descripcion && <p className="mt-3 whitespace-pre-wrap text-xs text-slate-600">Observación administrativa: {doc.descripcion}</p>}
      {doc.motivo_anulacion && <p className="mt-3 whitespace-pre-wrap text-xs text-rose-700">Motivo de anulación: {doc.motivo_anulacion}</p>}
      {!doc.archivo_disponible && doc.archivo && <p className="mt-3 text-xs text-amber-800">El archivo no está disponible o está bloqueado. Registra una nueva evidencia.</p>}
      <div className="mt-4 flex flex-wrap gap-2">
        {doc.archivo_disponible && <button className={boton} onClick={() => ver(doc)}>Ver / Descargar</button>}
        {permisos.editar && doc.puede_editar && <button className={boton} onClick={() => abrir('editar', item, doc)}>Editar metadatos</button>}
        {permisos.subir && item.activo && doc.puede_subsanar && <button className={`${boton} inline-flex items-center gap-2`} onClick={() => abrir('adjuntar', item, doc)}><Icon name="paperclip" size={18} />{doc.archivo ? 'Nueva evidencia / subsanar' : 'Adjuntar evidencia'}</button>}
        {permisos.revisar && doc.puede_revisar && <>
          {!['aceptado', 'verificado'].includes(doc.estado_original) && <button className={boton} onClick={() => abrir('aceptado', item, doc)}>Aceptar</button>}
          {doc.estado_original !== 'observado' && <button className={boton} onClick={() => abrir('observado', item, doc)}>Observar</button>}
        </>}
        {permisos.anular && doc.puede_anular && <button className={boton} onClick={() => abrir('anulado', item, doc)}>Anular</button>}
        <button className={boton} onClick={() => abrir('historial', item, doc)}>Historial</button>
      </div>
    </article>)}</div>
  </section>
}

export default function Seccion10Panel({ usuario, subseccion = '10.1' }) {
  const titulo = subseccion === '10.1' ? 'Seguridad y Salud en el Trabajo (SST)' : 'Bienestar Social'
  const consulta = useLiveQuery(`/expedientes/${usuario}/seccion-10?subseccion=${subseccion}`)
  const data = consulta.data?.data
  const subir = usePuede('documento.subir')
  const noAplica = useNoAplicables(usuario)
  const editar = usePuede('legajo.editar')
  const revisar = usePuede('documento.revisar')
  const anular = usePuede('sistema.administrar')
  const [seleccion, setSeleccion] = useState(null)
  const [modal, setModal] = useState(null)
  const [visor, setVisor] = useState(null)
  const [aviso, setAviso] = useState('')
  const abrir = (modo, item, documento = null) => setModal({ modo, item, documento })
  function guardado() {
    setModal(null)
    setAviso('Operación guardada. La ficha y los folios se han actualizado.')
    consulta.retry()
  }
  if (!data) return consulta.error ? <p role="alert" className="rounded-xl bg-rose-50 p-5 text-sm text-rose-800">{apiErrorMessage(consulta.error)} <button className="underline" onClick={consulta.retry}>Reintentar</button></p> : <p role="status" className="p-6 text-sm text-slate-500">Cargando {titulo}…</p>
  const itemSeleccionado = data.items.find(item => item.id === seleccion)
  const itemsSeccion = data.items.filter(item => item.subseccion === subseccion)
  return <EspacioSeccion seccion={tituloSeccion('10')} subseccion={titulo} etiquetaResumen="Ver folios"
    cifras={`Folios ${subseccion === '10.1' ? 'SST' : 'Bienestar Social'}: ${subseccion === '10.1' ? data.folios.sst : data.folios.bienestar} · total de la sección 10: ${data.folios.total}.`}
    resumen={<header aria-label="Resumen de folios" className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 sm:grid-cols-2">{[[`Número de folios ${subseccion === '10.1' ? 'SST' : 'Bienestar Social'}`, subseccion === '10.1' ? data.folios.sst : data.folios.bienestar], ['Total de folios de la Sección 10', data.folios.total]].map(([etiqueta, cantidad]) => <div key={etiqueta}><p className="text-xs text-slate-500">{etiqueta}</p><p className="mt-2 text-2xl font-bold text-slate-800">{cantidad}</p></div>)}</header>}>
    <div className="space-y-5">
    {!data.legajo && <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><p className="mb-3">Abre el legajo para registrar documentos.</p><AbrirLegajo usuario={usuario} /></div>}
    {consulta.error && <p role="alert" className="rounded-xl bg-rose-50 p-4 text-sm text-rose-700">No se pudo actualizar la ficha. <button className="underline" onClick={consulta.retry}>Reintentar</button></p>}
    {aviso && <p role="status" className="rounded-xl bg-teal-50 p-4 text-sm text-teal-800">{aviso}</p>}
    {/* Documentos por vista: Pendientes · Con documentos · Opcionales · No corresponde; cada tipo se sube desde su fila. */}
    {!itemsSeccion.length ? <p role="status" className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">El catálogo de esta sección está incompleto. Solicita a administración actualizar los tipos documentales para poder registrar y consultar documentos.</p>
      : <section aria-label={`Documentos de ${titulo}`} className="rounded-2xl border border-slate-200 bg-white p-5">
      <VistasDocumentales elementos={itemsSeccion} clasificar={item => vistaDeItem(item, ofrecerTipo(noAplica, item))}>{lista => <div className="overflow-x-auto rounded-xl border border-slate-100">
<table className="w-full text-left text-sm"><thead className="bg-slate-50 text-xs text-slate-500"><tr>{['Tipo de documento', 'Estado', 'Cantidad', 'Última fecha', 'Número de folios', 'Acciones'].map(tituloColumna => <th key={tituloColumna} scope="col" className="px-4 py-3 font-medium">{tituloColumna}</th>)}</tr></thead><tbody>
        {lista.map(item => <tr key={item.id} className="border-t border-slate-100"><th scope="row" className="min-w-64 px-4 py-4 font-medium text-slate-800">{item.nombre}{!item.activo && <span className="ml-2 text-xs text-slate-400">Inactivo</span>}</th><td className="px-4 py-4"><span className={`whitespace-nowrap rounded-full px-2.5 py-1 text-xs ${estados[item.estado][1]}`}>{estados[item.estado][0]}</span></td><td className="px-4 py-4">{item.cantidad}</td><td className="whitespace-nowrap px-4 py-4 text-slate-600">{fecha(item.ultima_fecha)}</td><td className="px-4 py-4">{item.numero_folios}</td><td className="px-4 py-4"><div className="flex items-center gap-2">{subir && data.legajo && item.activo && ofrecerTipo(noAplica, item) && <button type="button" className={`${boton} inline-flex items-center gap-1.5 whitespace-nowrap`} aria-label={`Subir documento: ${item.nombre}`} onClick={() => abrir('nuevo', item)}><Icon name="paperclip" size={16} />Subir</button>}<button type="button" className={botonIcono} title="Ver documentos" aria-label={`Ver documentos: ${item.nombre}`} onClick={() => setSeleccion(item.id)}><Icon name="eye" size={18} /></button></div></td></tr>)}
      </tbody></table></div>}</VistasDocumentales>
    </section>}
    {data.folios.sin_clasificar > 0 && <p className="rounded-xl bg-amber-50 p-4 text-xs text-amber-900">El total incluye {data.folios.sin_clasificar} folio(s) de documentos anteriores sin clasificación. Se conservan en el historial.</p>}
    {data.documentos_anteriores?.length > 0 && <details className="rounded-2xl border border-slate-200 bg-white p-4"><summary className="cursor-pointer text-sm font-medium text-slate-700">Documentos archivados de tipos anteriores</summary><div className="mt-4"><Documentos item={{ nombre: 'Documentos archivados', activo: false, documentos: data.documentos_anteriores }} permisos={{ anular }} abrir={abrir} ver={setVisor} /></div></details>}
    {itemSeleccionado && <Modal titulo={`Documentos de ${itemSeleccionado.nombre}`} onClose={() => setSeleccion(null)}>
      <Documentos key={itemSeleccionado.id} item={itemSeleccionado} permisos={{ subir, editar, revisar, anular }} abrir={abrir} ver={setVisor} mostrarTitulo={false} />
    </Modal>}
    {visor && <Modal titulo={`Documento de ${titulo}`} onClose={() => setVisor(null)}><VisorPdf usuario={usuario} documento={visor} titulo={visor.nombre} onClose={() => setVisor(null)} /></Modal>}
    {modal && <Modal titulo={modal.modo === 'historial' ? `Historial: ${modal.documento.nombre}` : modal.item.nombre} onClose={() => setModal(null)}>
      {modal.modo === 'historial' ? <Historial usuario={usuario} documento={modal.documento} /> : ['aceptado', 'observado', 'anulado'].includes(modal.modo)
        ? <Revision03Form usuario={usuario} documento={modal.documento} item={modal.item} estado={modal.modo} fechaActual={data.fecha_actual} revisar={modal.modo === 'anulado' ? anularDocumento10 : revisarDocumento10} onSaved={guardado} onCancel={() => setModal(null)} nota="La operación conserva el archivo y queda registrada con tu usuario, fecha y hora. La revisión requiere una persona distinta de quien realizó la carga." />
        : <Documento10Form usuario={usuario} datos={data} item={modal.item} documento={modal.documento} modo={modal.modo} onSaved={guardado} onCancel={() => setModal(null)} />}
    </Modal>}
    </div>
  </EspacioSeccion>
}
