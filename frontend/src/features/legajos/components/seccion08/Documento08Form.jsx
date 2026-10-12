import { useEffect, useRef, useState } from 'react'
import FormSplit from '../../../../components/pdf/FormSplit'
import PdfPreviewField from '../../../../components/pdf/PdfPreviewField'
import Campo, { Formulario, Opciones } from '../seccion01/Campo'
import { useBorrador, useGuardar } from '../seccion01/useGuardar'
import { agregarDocumento08 } from '../../api/seccion08Api'
import { modalidadesPresentacion, folios } from '../../config/documentos'
import { resultadoRecurso08, tipoSancion08 } from '../../config/seccion08'
import { conValor, reemplazarPropuesta } from '../../config/acreditacion'

// Datos del documento que se subsana y de su registro (reconocimiento, tiempo reconocido o sanción): se proponen para
// corregir solo lo necesario. Folios y PDF son del documento nuevo.
function datosDe(doc) {
  if (!doc) return {}
  const tiempo = doc.tiempo_servicio_reconocido
  return conValor({
    nombre: doc.nombre, emisor: doc.emisor, fecha_emision: doc.fecha_emision, num_resolucion: doc.num_resolucion, modalidad_presentacion: doc.modalidad_presentacion,
    motivo: doc.motivo, tipo: doc.tipo_sancion,
    ...(tiempo ? { anios: String(tiempo.anios ?? ''), meses: String(tiempo.meses ?? ''), dias: String(tiempo.dias ?? '') } : {}),
    ...(doc.registro_sancion_id ? { dias_suspension: doc.dias_suspension === null ? '' : String(doc.dias_suspension), registrada_en_rnssc: doc.registrada_en_rnssc ? '1' : '0',
      fecha_registro_rnssc: doc.fecha_registro_rnssc, referencia_rnssc: doc.referencia_rnssc, referencia_procedimiento: doc.referencia_procedimiento } : {}),
  })
}

// subsana: documento que se subsana (desde «Subsanar»). soloSubsanar: el tipo ya no se ofrece a la persona; solo se
// admite subsanar un documento existente (el backend rechaza una carga nueva).
export default function Documento08Form({ usuario, item, datos, onSaved, onCancel, subsana = '', soloSubsanar = false }) {
  const primero = useRef(null)
  useEffect(() => { primero.current?.focus() }, [])
  const [pdf, setPdf] = useState(null)
  const [errorPdf, setErrorPdf] = useState('')
  const vacios = { nombre: '', emisor: '', fecha_emision: '', num_resolucion: '', motivo: '', anios: '', meses: '', dias: '', tipo: '', dias_suspension: '',
    registrada_en_rnssc: '0', fecha_registro_rnssc: '', referencia_rnssc: '', referencia_procedimiento: '', sancion_id: '', resultado: '', modalidad_presentacion: '' }
  const documento = id => item.documentos.find(doc => doc.id === id)
  const [propuesta, setPropuesta] = useState(() => datosDe(documento(subsana)))
  const [draft, set, poner] = useBorrador({ ...vacios, ...propuesta, folio_inicio: datos.folio_sugerido, folio_fin: datos.folio_sugerido, subsana_a_documento_id: subsana })
  function elegirSubsana(event) {
    const nueva = datosDe(documento(event.target.value))
    const siguiente = reemplazarPropuesta(draft, propuesta, nueva, vacios)
    setPropuesta(nueva)
    poner('subsana_a_documento_id')(event.target.value)
    for (const campo of Object.keys(vacios)) if (siguiente[campo] !== draft[campo]) poner(campo)(siguiente[campo])
  }
  const { busy, error, errors, guardar } = useGuardar(values => agregarDocumento08(usuario, {
    tipo_documento_id: item.id, nombre: values.nombre, emisor: values.emisor, fecha_emision: values.fecha_emision,
    num_resolucion: values.num_resolucion, folio_inicio: values.folio_inicio, folio_fin: values.folio_fin,
    modalidad_presentacion: values.modalidad_presentacion, subsana_a_documento_id: values.subsana_a_documento_id,
    ...(item.codigo.startsWith('REC_') ? { tipo: item.codigo === 'REC_CARTA_FELICITACION' ? 'carta' : item.codigo === 'REC_LABOR_DOCENTE' ? 'labor_docente' : 'tiempo_servicios', motivo: values.motivo } : {}),
    ...(item.codigo === 'REC_TIEMPO_SERVICIOS' ? { anios: values.anios, meses: values.meses, dias: values.dias } : {}),
    ...(item.codigo === 'SAN_RESOLUCION_SANCION' ? { tipo: values.tipo, dias_suspension: values.dias_suspension, registrada_en_rnssc: values.registrada_en_rnssc,
      fecha_registro_rnssc: values.fecha_registro_rnssc, referencia_rnssc: values.referencia_rnssc, referencia_procedimiento: values.referencia_procedimiento } : {}),
    ...(item.codigo === 'SAN_RESOLUCION_RECURSO' ? { sancion_id: values.sancion_id, resultado: values.resultado } : {}),
  }, pdf), onSaved)
  const vigentes = item.documentos.filter(doc => !doc.subsanado && doc.estado !== 'anulado')
  const sanciones = (datos.grupos.sanciones || []).find(group => group.codigo === 'SAN_RESOLUCION_SANCION')?.documentos || []
  const esReconocimiento = item.codigo.startsWith('REC_')
  return <Formulario modal titulo={draft.subsana_a_documento_id ? 'Subsanar documento' : 'Agregar documento'} guardar="Guardar registro" busy={busy} error={error} onCancel={onCancel}
    onSubmit={() => { if (pdf && !errorPdf) guardar(draft) }} nota="Los documentos no se editan ni se borran. Si hay un error, agrega un documento nuevo que lo subsane. Quedará en revisión de RR. HH.">
    <FormSplit pdf={<PdfPreviewField label={`Adjuntar PDF (máximo ${datos.tamano_maximo_kb} KB)`} required maxMB={datos.tamano_maximo_kb / 1024} help="La vista previa se genera en tu navegador. El PDF se envía al guardar." error={errorPdf || errors.pdf} onChange={(file, message) => { setPdf(file); setErrorPdf(message) }} />}>
      <Campo label="Nombre del documento" required error={errors.nombre} className="sm:col-span-2"><input ref={primero} required maxLength={200} value={draft.nombre} onChange={set('nombre')} /></Campo>
      <Campo label="Entidad emisora" required error={errors.emisor}><input required maxLength={200} value={draft.emisor} onChange={set('emisor')} /></Campo>
      <Campo label={esReconocimiento ? 'Fecha de emisión' : 'Fecha del documento'} required error={errors.fecha_emision}><input type="date" required max={datos.fecha_actual} value={draft.fecha_emision} onChange={set('fecha_emision')} /></Campo>
      {(esReconocimiento || item.requiere_num_resolucion) && <Campo label={esReconocimiento ? 'N.º de documento o resolución' : 'N.º de resolución'} required={item.requiere_num_resolucion} error={errors.num_resolucion}><input required={item.requiere_num_resolucion} maxLength={60} value={draft.num_resolucion} onChange={set('num_resolucion')} /></Campo>}
      {esReconocimiento && <Campo label="Motivo del reconocimiento" required error={errors.motivo} className="sm:col-span-2"><textarea required maxLength={300} value={draft.motivo} onChange={set('motivo')} /></Campo>}
      {item.codigo === 'REC_TIEMPO_SERVICIOS' && <>
        <Campo label="Años reconocidos" error={errors.anios}><input type="number" min="0" step="1" value={draft.anios} onChange={set('anios')} /></Campo>
        <Campo label="Meses reconocidos" error={errors.meses}><input type="number" min="0" step="1" value={draft.meses} onChange={set('meses')} /></Campo>
        <Campo label="Días reconocidos" error={errors.dias}><input type="number" min="0" step="1" value={draft.dias} onChange={set('dias')} /></Campo>
        <p className="col-span-full text-xs text-slate-500">El tiempo reconocido es opcional y se compara como referencia con la experiencia calculada. No modifica ni reemplaza ese cálculo.</p>
        {datos.tiempo_experiencia_calculada && <p className="col-span-full rounded-lg bg-slate-50 p-3 text-xs text-slate-600">Experiencia calculada: {datos.tiempo_experiencia_calculada.anios} años, {datos.tiempo_experiencia_calculada.meses} meses y {datos.tiempo_experiencia_calculada.dias} días.</p>}
      </>}
      {item.codigo === 'SAN_RESOLUCION_SANCION' && <>
        <Campo label="Tipo de sanción" required error={errors.tipo}><select required value={draft.tipo} onChange={set('tipo')}><Opciones lista={tipoSancion08} vacio="Selecciona" /></select></Campo>
        {draft.tipo === 'SUSPENSION' && <Campo label="Días de suspensión" required error={errors.dias_suspension}><input type="number" required min="1" step="1" value={draft.dias_suspension} onChange={set('dias_suspension')} /></Campo>}
        <Campo label="Consta en el RNSSC" required error={errors.registrada_en_rnssc}><select required value={draft.registrada_en_rnssc} onChange={set('registrada_en_rnssc')}><option value="0">No</option><option value="1">Sí</option></select></Campo>
        {draft.registrada_en_rnssc === '1' && <>
          <Campo label="Fecha de registro en el RNSSC" error={errors.fecha_registro_rnssc}><input type="date" max={datos.fecha_actual} value={draft.fecha_registro_rnssc} onChange={set('fecha_registro_rnssc')} /></Campo>
          <Campo label="Referencia RNSSC" error={errors.referencia_rnssc}><input value={draft.referencia_rnssc} onChange={set('referencia_rnssc')} /></Campo>
        </>}
        <Campo label="Referencia del expediente o procedimiento disciplinario" error={errors.referencia_procedimiento} className="sm:col-span-2"><textarea value={draft.referencia_procedimiento} onChange={set('referencia_procedimiento')} /></Campo>
      </>}
      {item.codigo === 'SAN_RESOLUCION_RECURSO' && <>
        <Campo label="Sanción relacionada" required error={errors.sancion_id} className="sm:col-span-2"><select required value={draft.sancion_id} onChange={set('sancion_id')}><option value="">Selecciona una sanción</option>{sanciones.map(doc => <option key={doc.registro_sancion_id || doc.id} value={doc.registro_sancion_id || doc.id}>{doc.nombre} · folios {folios(doc.folio_inicio, doc.folio_fin)}</option>)}</select></Campo>
        <Campo label="Resultado del recurso" required error={errors.resultado}><select required value={draft.resultado} onChange={set('resultado')}><Opciones lista={resultadoRecurso08} vacio="Selecciona" /></select></Campo>
      </>}
      <Campo label="Fecha de incorporación"><input type="date" readOnly value={datos.fecha_actual} /></Campo>
      <Campo label="Folio inicial sugerido"><input readOnly value={datos.folio_sugerido ?? 'No disponible'} /></Campo>
      <Campo label="Folio final" required error={errors.folio_fin}><input type="number" required min={datos.folio_sugerido || 1} value={draft.folio_fin} onChange={set('folio_fin')} /></Campo>
      <Campo label="Modalidad de presentación" required error={errors.modalidad_presentacion}><select required value={draft.modalidad_presentacion} onChange={set('modalidad_presentacion')}><Opciones lista={modalidadesPresentacion} vacio="Selecciona" /></select></Campo>
      {vigentes.length > 0 && <Campo label="Documento que subsana" required={soloSubsanar} error={errors.subsana_a_documento_id} className="sm:col-span-2"><select required={soloSubsanar} value={draft.subsana_a_documento_id} onChange={elegirSubsana}><option value="">{soloSubsanar ? 'Selecciona el documento que subsana' : 'Es un documento adicional'}</option>{vigentes.map(doc => <option key={doc.id} value={doc.id}>{doc.nombre} · folios {folios(doc.folio_inicio, doc.folio_fin)}</option>)}</select></Campo>}
    </FormSplit>
  </Formulario>
}
