import { useEffect, useRef, useState } from 'react'
import FormSplit from '../../../../components/pdf/FormSplit'
import PdfPreviewField from '../../../../components/pdf/PdfPreviewField'
import Campo, { Formulario, Opciones } from '../seccion01/Campo'
import { hoy, useBorrador, useGuardar } from '../seccion01/useGuardar'
import { agregarDocumento04 } from '../../api/seccion04Api'
import { modalidadesPresentacion, folios, modalidadRecordada, recordarModalidad } from '../../config/documentos'
import { SugerenciaFolio } from '../documentos/AyudasCarga'
import { conValor, reemplazarPropuesta } from '../../config/acreditacion'

// Datos del documento que se subsana y de su experiencia: se proponen para corregir solo lo necesario. Folios y PDF son
// del documento nuevo. La experiencia subsanada deja de contarse (TiempoExperiencia excluye los documentos subsanados).
function datosDe(doc) {
  if (!doc) return {}
  const experiencia = doc.experiencias?.[0]
  return conValor({
    nombre: doc.nombre, emisor: doc.emisor, fecha_emision: doc.fecha_emision, num_resolucion: doc.num_resolucion, funcion_asignada: doc.funcion_asignada,
    modalidad_presentacion: doc.modalidad_presentacion,
    ...(experiencia ? { sector: experiencia.sector, cargo: experiencia.cargo, fecha_inicio: experiencia.fecha_inicio, fecha_fin: experiencia.fecha_fin, hasta_la_fecha: !experiencia.fecha_fin,
      tipo_vinculo: experiencia.tipo_vinculo, es_ejercicio_profesional: !!experiencia.es_ejercicio_profesional, es_docencia_universitaria: !!experiencia.es_docencia_universitaria } : {}),
  })
}

// subsana: documento que se subsana (desde «Subsanar»). soloSubsanar: el tipo ya no se ofrece a la persona; solo se
// admite subsanar un documento existente (el backend rechaza una carga nueva).
export default function Documento04Form({ usuario, item, datos, onSaved, onCancel, subsana = '', soloSubsanar = false }) {
  const primero = useRef(null)
  useEffect(() => { primero.current?.focus() }, [])
  const [pdf, setPdf] = useState(null)
  const [errorPdf, setErrorPdf] = useState('')
  const [paginas, setPaginas] = useState(null)
  const vacios = { nombre: '', emisor: '', fecha_emision: '', sector: '', cargo: '', fecha_inicio: '', fecha_fin: '', hasta_la_fecha: false,
    tipo_vinculo: '', es_ejercicio_profesional: false, es_docencia_universitaria: false, funcion_asignada: '', num_resolucion: '', modalidad_presentacion: modalidadRecordada() }
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
    const propios = item.letra === 'a' ? ['sector', 'cargo', 'fecha_inicio', 'fecha_fin', 'tipo_vinculo', 'es_ejercicio_profesional', 'es_docencia_universitaria'] : item.letra === 'b' ? ['funcion_asignada'] : []
    if (item.requiere_num_resolucion) propios.push('num_resolucion')
    return agregarDocumento04(usuario, { ...Object.fromEntries([...comunes, ...propios].map(key => [key, key === 'fecha_fin' && values.hasta_la_fecha ? null : values[key]])), tipo_documento_id: item.id }, pdf)
  }, () => { recordarModalidad(draft.modalidad_presentacion); onSaved() })
  const vigentes = item.documentos.filter(doc => !doc.subsanado && doc.estado !== 'anulado')
  const periodoFin = draft.hasta_la_fecha ? datos.tiempo.fecha_corte : draft.fecha_fin
  const solapa = item.letra === 'a' && draft.fecha_inicio && periodoFin && datos.tiempo.periodos.some(p => p.documento_id !== draft.subsana_a_documento_id && p.fecha_inicio <= periodoFin && p.hasta >= draft.fecha_inicio)
  const huecoLargo = item.letra === 'a' && draft.fecha_inicio && datos.tiempo.periodos.length > 0 && (() => {
    const anteriores = datos.tiempo.periodos.filter(p => p.hasta < draft.fecha_inicio && p.documento_id !== draft.subsana_a_documento_id)
    if (!anteriores.length) return false
    const fin = anteriores.reduce((max, p) => p.hasta > max ? p.hasta : max, anteriores[0].hasta)
    return (Date.parse(`${draft.fecha_inicio}T00:00:00Z`) - Date.parse(`${fin}T00:00:00Z`)) / 86400000 - 1 > datos.tiempo.configuracion.hueco_largo_dias
  })()
  return <Formulario modal titulo={draft.subsana_a_documento_id ? 'Subsanar documento de experiencia laboral' : 'Agregar documento de experiencia laboral'} guardar="Guardar registro" busy={busy} error={error} onCancel={onCancel}
    onSubmit={() => { if (pdf && !errorPdf) guardar(draft) }} nota="Lo registrado no se edita ni se borra. Si hay un error, agrega un documento nuevo que subsane al anterior. Quedará en revisión de RR. HH.">
    <FormSplit pdf={<PdfPreviewField onPages={setPaginas} label={`Adjuntar PDF (máximo ${datos.tamano_maximo_kb} KB)`} required maxMB={datos.tamano_maximo_kb / 1024} help="La vista previa se genera en tu navegador. El PDF se envía al guardar el registro." error={errorPdf || errors.pdf} onChange={(file, message) => { setPdf(file); setErrorPdf(message) }} />}>
      <Campo label="Nombre del documento" required error={errors.nombre} className="sm:col-span-2"><input ref={primero} required maxLength={200} value={draft.nombre} onChange={set('nombre')} /></Campo>
      <Campo label={item.letra === 'a' ? 'Entidad o empresa' : 'Entidad emisora'} required error={errors.emisor}><input required maxLength={200} value={draft.emisor} onChange={set('emisor')} /></Campo>
      <Campo label="Fecha de emisión" required error={errors.fecha_emision}><input type="date" required max={hoy()} value={draft.fecha_emision} onChange={set('fecha_emision')} /></Campo>
      {item.letra === 'a' && <>
        <Campo label="Sector" required error={errors.sector}><select required value={draft.sector} onChange={set('sector')}><Opciones lista={{ publico: 'Público', privado: 'Privado' }} vacio="Selecciona" /></select></Campo>
        <Campo label="Cargo o función" required error={errors.cargo}><input required maxLength={150} value={draft.cargo} onChange={set('cargo')} /></Campo>
        <Campo label="Fecha de inicio" required error={errors.fecha_inicio}><input type="date" required max={hoy()} value={draft.fecha_inicio} onChange={set('fecha_inicio')} /></Campo>
        <Campo label="Fecha de fin" required={!draft.hasta_la_fecha} error={errors.fecha_fin}><input type="date" disabled={draft.hasta_la_fecha} required={!draft.hasta_la_fecha} min={draft.fecha_inicio || undefined} max={hoy()} value={draft.hasta_la_fecha ? '' : draft.fecha_fin} onChange={set('fecha_fin')} /></Campo>
        <label className="col-span-full flex items-start gap-3 text-sm text-slate-700"><input type="checkbox" checked={draft.hasta_la_fecha} onChange={set('hasta_la_fecha')} className="mt-1 size-4 accent-teal-700" />Hasta la fecha</label>
        <Campo label="Tipo de vínculo" required error={errors.tipo_vinculo}><select required value={draft.tipo_vinculo} onChange={set('tipo_vinculo')}><Opciones lista={{ planilla: 'Planilla', contrato_civil: 'Contrato civil / locación', otro: 'Otro' }} vacio="Selecciona" /></select></Campo>
        <label className="col-span-full flex items-start gap-3 text-sm text-slate-700"><input type="checkbox" checked={draft.es_ejercicio_profesional} onChange={set('es_ejercicio_profesional')} className="mt-1 size-4 accent-teal-700" />Este periodo corresponde a ejercicio profesional.</label>
        <label className="col-span-full flex items-start gap-3 text-sm text-slate-700"><input type="checkbox" checked={draft.es_docencia_universitaria} onChange={set('es_docencia_universitaria')} className="mt-1 size-4 accent-teal-700" />Este periodo corresponde a docencia universitaria.</label>
        {(solapa || huecoLargo) && <p role="status" className="col-span-full rounded-xl bg-amber-50 p-3 text-xs text-amber-900">{solapa ? 'Este periodo se superpone con otra experiencia declarada. El cálculo contará cada día una sola vez.' : `Hay un intervalo mayor de ${datos.tiempo.configuracion.hueco_largo_dias} días sin experiencia declarada antes de este periodo.`} Puedes guardar; RR. HH. podrá pedir una aclaración.</p>}
      </>}
      {item.requiere_num_resolucion && <Campo label="N.º de resolución o memorándum" required error={errors.num_resolucion}><input required maxLength={60} value={draft.num_resolucion} onChange={set('num_resolucion')} /></Campo>}
      {item.letra === 'b' && <Campo label="Función asignada" required error={errors.funcion_asignada} className="sm:col-span-2"><textarea required maxLength={3000} rows={3} value={draft.funcion_asignada} onChange={set('funcion_asignada')} /></Campo>}
      <Campo label="Folio inicial sugerido" required error={errors.folio_inicio}><input type="number" required min="1" value={draft.folio_inicio} onChange={set('folio_inicio')} /></Campo>
      <Campo label="Folio final" required error={errors.folio_fin}><input type="number" required min={draft.folio_inicio || 1} value={draft.folio_fin} onChange={set('folio_fin')} /></Campo>
      <SugerenciaFolio inicio={draft.folio_inicio} paginas={paginas} valor={draft.folio_fin} onUsar={poner('folio_fin')} />
      <Campo label="Modalidad de presentación" required error={errors.modalidad_presentacion}><select required value={draft.modalidad_presentacion} onChange={set('modalidad_presentacion')}><Opciones lista={modalidadesPresentacion} vacio="Selecciona" /></select></Campo>
      {vigentes.length > 0 && <Campo label="Documento que subsana" required={soloSubsanar} error={errors.subsana_a_documento_id} className="sm:col-span-2"><select required={soloSubsanar} value={draft.subsana_a_documento_id} onChange={elegirSubsana}><option value="">{soloSubsanar ? 'Selecciona el documento que subsana' : 'Es un documento adicional'}</option>{vigentes.map(doc => <option key={doc.id} value={doc.id}>{doc.nombre} · folios {folios(doc.folio_inicio, doc.folio_fin)}</option>)}</select></Campo>}
      {['tipo_documento_id', 'es_ejercicio_profesional', 'es_docencia_universitaria'].map(key => errors[key] && <p key={key} role="alert" className="col-span-full text-sm text-rose-700">{errors[key].join(' ')}</p>)}
    </FormSplit>
  </Formulario>
}
