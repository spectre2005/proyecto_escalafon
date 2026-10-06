import { useState } from 'react'
import FormSplit from '../../../../components/pdf/FormSplit'
import PdfPreviewField from '../../../../components/pdf/PdfPreviewField'
import Campo, { Formulario, Opciones } from '../seccion01/Campo'
import { useBorrador, useGuardar } from '../seccion01/useGuardar'
import { modalidadesPresentacion } from '../../config/documentos'
import { agregarDocumento10, editarDocumento10 } from '../../api/seccion10Api'

export default function Documento10Form({ usuario, item, datos, documento, modo, onSaved, onCancel }) {
  const editar = modo === 'editar'
  const tituloModulo = datos.subseccion === '10.1' ? 'SST' : 'Bienestar Social'
  const [tipoId, setTipoId] = useState(item.id)
  const tipo = datos.items.find(option => option.id === tipoId)
  const [pdf, setPdf] = useState(null)
  const [errorPdf, setErrorPdf] = useState('')
  const pendiente = !editar && !pdf
  const requierePdf = !editar && !!documento?.archivo
  const [draft, set] = useBorrador({
    nombre: documento?.nombre || '', emisor: documento?.emisor || '', fecha_emision: documento?.fecha_emision || '',
    numero_documento: documento?.numero_documento || '', descripcion: documento?.descripcion || '',
    modalidad_presentacion: documento?.modalidad_presentacion || '', numero_folios: 1,
    fecha_inicio: documento?.fecha_inicio || '', fecha_fin: documento?.fecha_fin || '',
    fecha_vencimiento: documento?.fecha_vencimiento || '', entidad: documento?.entidad || '',
  })
  const { busy, error, errors, guardar } = useGuardar(values => {
    const comunes = ['nombre', 'emisor', 'fecha_emision', 'numero_documento', 'descripcion', 'modalidad_presentacion']
    if (tipo.periodo) comunes.push('fecha_inicio', 'fecha_fin')
    if (tipo.vigencia) comunes.push('fecha_vencimiento')
    if (tipo.entidad_eps) comunes.push('entidad')
    const data = Object.fromEntries(comunes.map(key => [key, values[key]]))
    if (editar || (documento && pendiente && !documento.archivo)) return editarDocumento10(usuario, documento.id, { ...data, estado_anterior: documento.estado_original })
    return agregarDocumento10(usuario, {
      ...data, tipo_documento_id: tipo.id,
      ...(!pendiente ? { numero_folios: values.numero_folios } : {}),
      ...(documento ? { subsana_a_documento_id: documento.id } : {}),
    }, pdf)
  }, onSaved)
  const dias = draft.fecha_inicio && draft.fecha_fin ? Math.round((Date.parse(draft.fecha_fin) - Date.parse(draft.fecha_inicio)) / 86400000) + 1 : null
  const campos = <>
    <Campo label="Tipo de documento" required error={errors.tipo_documento_id} className="sm:col-span-2">
      <select required value={tipoId} disabled={!!documento} onChange={event => setTipoId(event.target.value)}>
        {datos.items.filter(option => option.activo || option.id === tipoId).map(option => <option key={option.id} value={option.id}>{option.subseccion} · {option.nombre}</option>)}
      </select>
    </Campo>
    <Campo label="Servidor" className="sm:col-span-2"><input readOnly value={datos.persona.nombre_completo} /></Campo>
    <Campo label="Nombre del documento" required error={errors.nombre} className="sm:col-span-2"><input required maxLength={200} value={draft.nombre} onChange={set('nombre')} /></Campo>
    <Campo label="Fecha del documento / emisión" required error={errors.fecha_emision}><input type="date" required max={datos.fecha_actual} value={draft.fecha_emision} onChange={set('fecha_emision')} /></Campo>
    <Campo label="Emisor" required error={errors.emisor}><input required maxLength={200} value={draft.emisor} onChange={set('emisor')} /></Campo>
    <Campo label="Número de documento" error={errors.numero_documento}><input maxLength={60} value={draft.numero_documento} onChange={set('numero_documento')} /></Campo>
    {(!pendiente || editar) && <Campo label="Modalidad de presentación" required={!editar || !!documento?.archivo} error={errors.modalidad_presentacion}><select required={!editar || !!documento?.archivo} value={draft.modalidad_presentacion} onChange={set('modalidad_presentacion')}><Opciones lista={modalidadesPresentacion} vacio="Selecciona" /></select></Campo>}
    {tipo.entidad_eps && <Campo label="Entidad EPS" required error={errors.entidad}><input required maxLength={200} value={draft.entidad} onChange={set('entidad')} /></Campo>}
    {tipo.periodo && <>
      <Campo label={tipo.entidad_eps ? 'Fecha de afiliación' : 'Fecha de inicio'} required error={errors.fecha_inicio}><input type="date" required value={draft.fecha_inicio} onChange={set('fecha_inicio')} /></Campo>
      <Campo label={tipo.entidad_eps ? 'Fecha de desafiliación (si corresponde)' : 'Fecha de fin'} required={!tipo.entidad_eps} error={errors.fecha_fin}><input type="date" required={!tipo.entidad_eps} min={draft.fecha_inicio || undefined} value={draft.fecha_fin} onChange={set('fecha_fin')} /></Campo>
      {!tipo.entidad_eps && dias > 0 && <p className="col-span-full text-sm text-slate-600">Periodo: <strong>{dias} día(s)</strong>. Se incluyen el día inicial y el final.</p>}
    </>}
    {tipo.vigencia && <Campo label="Fecha de vencimiento (si el documento tiene vigencia)" error={errors.fecha_vencimiento}><input type="date" min={(tipo.entidad_eps ? draft.fecha_inicio : draft.fecha_emision) || undefined} value={draft.fecha_vencimiento} onChange={set('fecha_vencimiento')} /></Campo>}
    {!pendiente && !editar && <>
      <Campo label="Número de folios" required error={errors.numero_folios}><input type="number" required min="1" max="2147483647" step="1" value={draft.numero_folios} onChange={set('numero_folios')} /></Campo>
      <p className="self-center text-xs text-slate-500">Siguiente folio disponible: {datos.folio_sugerido}. El rango se asigna al guardar.</p>
    </>}
    <Campo label="Observación administrativa" error={errors.descripcion} className="sm:col-span-2"><textarea maxLength={300} rows={3} value={draft.descripcion} onChange={set('descripcion')} /></Campo>
    {tipo.codigo === 'S10_EXAMEN_OCUPACIONAL' && <p className="col-span-full rounded-lg bg-amber-50 p-3 text-xs text-amber-900">Adjunta únicamente la constancia de realización del examen. El archivo y la observación deben excluir diagnósticos y resultados clínicos.</p>}
    {documento && !editar && <p className="col-span-full text-xs text-slate-600">Esta evidencia completará o subsanará «{documento.nombre}». El registro anterior se conservará en el historial.</p>}
    {errors.subsana_a_documento_id && <p role="alert" className="col-span-full text-sm text-rose-700">{errors.subsana_a_documento_id.join(' ')}</p>}
  </>
  return <Formulario titulo={editar ? 'Editar metadatos' : documento ? 'Adjuntar evidencia o subsanar documento' : `Adjuntar documento de ${tituloModulo}`}
    guardar={editar ? 'Guardar cambios' : 'Guardar documento'} busy={busy} error={error} onCancel={onCancel}
    onSubmit={() => { if (!errorPdf && (!requierePdf || pdf)) guardar(draft) }}
    nota={editar ? 'Solo se pueden editar metadatos antes de la recepción o validación. Cada cambio queda en la auditoría.' : requierePdf ? 'Adjunta la nueva evidencia para subsanar el documento anterior.' : pendiente ? 'Si guardas sin PDF, el registro quedará Pendiente y no sumará folios.' : 'El PDF se envía al guardar y los folios se recalculan automáticamente.'}>
    {editar ? campos : <FormSplit pdf={<PdfPreviewField required={requierePdf} label={`Archivo PDF (máximo ${datos.tamano_maximo_kb} KB)`} maxMB={datos.tamano_maximo_kb / 1024}
      help="Comprueba la evidencia antes de guardarla." error={errorPdf || errors.pdf} onChange={(file, message) => { setPdf(file); setErrorPdf(message) }} />}>{campos}</FormSplit>}
  </Formulario>
}
