import { useEffect, useRef, useState } from 'react'
import FormSplit from '../../../../components/pdf/FormSplit'
import PdfPreviewField from '../../../../components/pdf/PdfPreviewField'
import Campo, { Formulario, Opciones } from '../seccion01/Campo'
import { useBorrador, useGuardar } from '../seccion01/useGuardar'
import { agregarDocumento06 } from '../../api/seccion06Api'
import { modalidadesPresentacion, folios, modalidadRecordada, recordarModalidad } from '../../config/documentos'
import { SugerenciaFolio } from '../documentos/AyudasCarga'
import { conValor, reemplazarPropuesta } from '../../config/acreditacion'

// Datos del documento que se subsana y de su registro (tiempo reconocido o compensación): se proponen para corregir
// solo lo necesario. Folios y PDF son del documento nuevo.
function datosDe(doc) {
  if (!doc) return {}
  const tiempo = doc.tiempos_reconocidos?.find(t => !t.anulado)
  const compensacion = doc.compensaciones?.find(c => !c.anulado)
  return conValor({
    nombre: doc.nombre, emisor: doc.emisor, fecha_emision: doc.fecha_emision, num_resolucion: doc.num_resolucion, modalidad_presentacion: doc.modalidad_presentacion,
    ...(tiempo ? { anios: String(tiempo.anios ?? ''), meses: String(tiempo.meses ?? ''), dias: String(tiempo.dias ?? '') } : {}),
    ...(compensacion ? { tipo: compensacion.tipo, concepto: compensacion.concepto, fecha: compensacion.fecha, numero_documento: compensacion.numero_documento } : {}),
  })
}

// subsana: documento que se subsana (desde «Subsanar»). soloSubsanar: el tipo ya no se ofrece a la persona; solo se
// admite subsanar un documento existente (el backend rechaza una carga nueva).
export default function Documento06Form({ usuario, item, datos, onSaved, onCancel, subsana = '', soloSubsanar = false }) {
  const primero = useRef(null)
  useEffect(() => { primero.current?.focus() }, [])
  const [pdf, setPdf] = useState(null)
  const [errorPdf, setErrorPdf] = useState('')
  const [paginas, setPaginas] = useState(null)
  const vacios = { nombre: '', emisor: '', fecha_emision: '', num_resolucion: '', anios: '', meses: '', dias: '', tipo: '', concepto: '', fecha: '', numero_documento: '', modalidad_presentacion: modalidadRecordada() }
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
  const { busy, error, errors, guardar } = useGuardar(values => {
    const comunes = ['nombre', 'emisor', 'fecha_emision', 'folio_inicio', 'folio_fin', 'modalidad_presentacion', 'subsana_a_documento_id']
    const propios = item.letra === 'a' ? ['anios', 'meses', 'dias'] : item.letra === 'b' ? ['tipo', 'concepto', 'fecha', 'numero_documento'] : []
    if (item.requiere_num_resolucion || item.letra === 'a') propios.push('num_resolucion')
    return agregarDocumento06(usuario, { ...Object.fromEntries([...comunes, ...propios].map(key => [key, values[key]])), tipo_documento_id: item.id }, pdf)
  }, () => { recordarModalidad(draft.modalidad_presentacion); onSaved() })
  const vigentes = item.documentos.filter(doc => !doc.subsanado && doc.estado !== 'anulado')
  return <Formulario modal titulo={draft.subsana_a_documento_id ? 'Subsanar documento de compensaciones' : 'Agregar documento de compensaciones'} guardar="Guardar registro" busy={busy} error={error} onCancel={onCancel}
    onSubmit={() => { if (pdf && !errorPdf) guardar(draft) }} nota="Lo registrado no se edita ni se borra. Si hay un error, agrega un documento nuevo que subsane al anterior.">
    <FormSplit pdf={<PdfPreviewField onPages={setPaginas} label={`Adjuntar PDF (máximo ${datos.tamano_maximo_kb} KB)`} required maxMB={datos.tamano_maximo_kb / 1024} help="La vista previa se genera en tu navegador. El PDF se envía al guardar el registro." error={errorPdf || errors.pdf} onChange={(file, message) => { setPdf(file); setErrorPdf(message) }} />}>
      <Campo label="Nombre del documento" required error={errors.nombre} className="sm:col-span-2"><input ref={primero} required maxLength={200} value={draft.nombre} onChange={set('nombre')} /></Campo>
      <Campo label="Entidad emisora" required error={errors.emisor}><input required maxLength={200} value={draft.emisor} onChange={set('emisor')} /></Campo>
      <Campo label={item.letra === 'a' ? 'Fecha de resolución / emisión' : 'Fecha de emisión'} required error={errors.fecha_emision}><input type="date" required max={datos.fecha_actual} value={draft.fecha_emision} onChange={set('fecha_emision')} /></Campo>
      {(item.requiere_num_resolucion || item.letra === 'a') && <Campo label="N.º de resolución" required error={errors.num_resolucion}><input required maxLength={60} value={draft.num_resolucion} onChange={set('num_resolucion')} /></Campo>}
      {item.letra === 'a' && <>
        {['anios', 'meses', 'dias'].map((key, index) => <Campo key={key} label={['Años reconocidos', 'Meses reconocidos', 'Días reconocidos'][index]} required error={errors[key]}><input type="number" required min="0" max="2147483647" step="1" value={draft[key]} onChange={set(key)} /></Campo>)}
        <p className="col-span-full text-xs text-slate-500">Transcribe el tiempo reconocido por la resolución. No se recalcula ni se suma a la experiencia laboral.</p>
      </>}
      {item.letra === 'b' && <>
        <Campo label="Tipo de compensación" required error={errors.tipo}><select required value={draft.tipo} onChange={set('tipo')}><Opciones lista={{ economica: 'Económica', no_economica: 'No económica' }} vacio="Selecciona" /></select></Campo>
        <Campo label="Concepto" required error={errors.concepto}><input required maxLength={200} value={draft.concepto} onChange={set('concepto')} /></Campo>
        <Campo label="Fecha del pago o acto documentado" required error={errors.fecha}><input type="date" required max={datos.fecha_actual} value={draft.fecha} onChange={set('fecha')} /></Campo>
        <Campo label="N.º del documento de pago" error={errors.numero_documento}><input maxLength={60} value={draft.numero_documento} onChange={set('numero_documento')} /></Campo>
      </>}
      {item.letra === 'c' && <p className="col-span-full text-xs text-slate-500">Identifica en el nombre si corresponde a cuarta, quinta categoría u otro certificado de retención.</p>}
      <Campo label="Fecha de incorporación"><input type="date" readOnly value={datos.fecha_actual} /></Campo>
      <Campo label="Titular" className="sm:col-span-2"><input readOnly value={[datos.persona.nombres, datos.persona.apellido_paterno, datos.persona.apellido_materno].filter(Boolean).join(' ') + (datos.persona.numero_doc_identidad ? ` · ${datos.persona.numero_doc_identidad}` : '')} /></Campo>
      <Campo label="Folio inicial sugerido" required error={errors.folio_inicio}><input type="number" required min="1" value={draft.folio_inicio} onChange={set('folio_inicio')} /></Campo>
      <Campo label="Folio final" required error={errors.folio_fin}><input type="number" required min={draft.folio_inicio || 1} value={draft.folio_fin} onChange={set('folio_fin')} /></Campo>
      <SugerenciaFolio inicio={draft.folio_inicio} paginas={paginas} valor={draft.folio_fin} onUsar={poner('folio_fin')} />
      <Campo label="Modalidad de presentación" required error={errors.modalidad_presentacion}><select required value={draft.modalidad_presentacion} onChange={set('modalidad_presentacion')}><Opciones lista={modalidadesPresentacion} vacio="Selecciona" /></select></Campo>
      {vigentes.length > 0 && <Campo label="Documento que subsana" required={soloSubsanar} error={errors.subsana_a_documento_id} className="sm:col-span-2"><select required={soloSubsanar} value={draft.subsana_a_documento_id} onChange={elegirSubsana}><option value="">{soloSubsanar ? 'Selecciona el documento que subsana' : 'Es un documento adicional'}</option>{vigentes.map(doc => <option key={doc.id} value={doc.id}>{doc.nombre} · folios {folios(doc.folio_inicio, doc.folio_fin)}</option>)}</select></Campo>}
      {['tipo_documento_id'].map(key => errors[key] && <p key={key} role="alert" className="col-span-full text-sm text-rose-700">{errors[key].join(' ')}</p>)}
    </FormSplit>
  </Formulario>
}
