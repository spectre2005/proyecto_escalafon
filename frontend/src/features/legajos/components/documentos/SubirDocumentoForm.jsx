import FormSplit from '../../../../components/pdf/FormSplit'
import PdfPreviewField from '../../../../components/pdf/PdfPreviewField'
import { useRef, useState } from 'react'
import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { subirDocumento } from '../../api/documentosApi'
import { folios, modalidadesPresentacion, relacionesTitular } from '../../config/documentos'
import Campo, { Formulario, Opciones } from '../seccion01/Campo'
import { hoy, useBorrador, useGuardar } from '../seccion01/useGuardar'

/**
 * Subir un documento (PDF + metadatos obligatorios) a un tipo de documento. Común a todas las secciones.
 * El folio inicial es siempre el siguiente de la sección (sin huecos ni solapes); lo calcula el backend.
 * tipo: { id, nombre, literal, requiere_num_resolucion, permite_multiples, formulario_datos, lista }
 * enlaces: registros existentes que el documento puede acreditar ({ id, etiqueta }), o null si el tipo no tiene formulario.
 * onSaved(documento): recibe la respuesta del backend (formulario_datos, enlazado) para abrir el formulario de datos.
 */
export default function SubirDocumentoForm({ usuario, seccion, tipo, titular, enlaces, onSaved, onCancel }) {
  const folio = useLiveQuery(`/expedientes/${usuario}/documentos/folio-sugerido?seccion=${seccion}`)
  const sugerido = folio.data?.data.folio_sugerido
  const maximoKb = folio.data?.data.tamano_maximo_kb
  const vigentes = (tipo.lista || []).filter(documento => !documento.subsanado)
  const debeSubsanar = !tipo.permite_multiples && vigentes.length > 0
  const [pdf, setPdf] = useState(null)
  const [errorPdf, setErrorPdf] = useState('')
  const respuesta = useRef(null)
  const [draft, set] = useBorrador({
    folio_fin: '', fecha_incorporacion: hoy(), emisor: '', fecha_emision: '', num_resolucion: '',
    titular_nombre: titular?.nombre || '', titular_documento: titular?.documento || '', relacion_titular: 'servidor',
    modalidad_presentacion: '', motivo_incorporacion: '', subsana_a_documento_id: debeSubsanar && vigentes.length === 1 ? vigentes[0].id : '', enlace_id: '',
  })
  const { busy, error, errors, guardar } = useGuardar(async data => {
    respuesta.current = (await subirDocumento(usuario, { ...data, seccion_legajo_id: seccion, tipo_documento_id: tipo.id, folio_inicio: sugerido }, pdf)).data.data
  }, () => onSaved(respuesta.current))

  return <Formulario titulo={`Subir documento: ${tipo.literal ? `${tipo.literal}) ` : ''}${tipo.nombre}`} guardar="Subir documento" busy={busy} error={error}
    onSubmit={() => sugerido && pdf && !errorPdf && guardar(draft)} onCancel={onCancel}
    nota="El documento no se puede eliminar ni reemplazar después: si hay un error, se sube uno nuevo que lo subsana. Quien sube queda registrado con tu sesión.">
    <FormSplit pdf={<PdfPreviewField label={`Archivo PDF${maximoKb ? ` (máx. ${maximoKb} KB)` : ''}`} required maxMB={maximoKb ? maximoKb / 1024 : 2} help="Selecciona el PDF para ver su contenido antes de guardarlo." error={errorPdf || errors.pdf} onChange={(file, message) => { setPdf(file); setErrorPdf(message) }} />}>
    <Campo label="Folio inicial (siguiente de la sección)" error={errors.folio_inicio}><input readOnly value={sugerido ?? 'Calculando…'} /></Campo>
    <Campo label="Folio final" required error={errors.folio_fin}><input type="number" required min={sugerido || 1} value={draft.folio_fin} onChange={set('folio_fin')} placeholder={sugerido ? String(sugerido) : ''} /></Campo>
    {sugerido && draft.folio_fin && <p className="text-xs text-slate-500 sm:col-span-2">Folios que ocupará: {folios(sugerido, Number(draft.folio_fin))}</p>}
    <Campo label="Fecha de incorporación" required error={errors.fecha_incorporacion}><input type="date" required max={hoy()} value={draft.fecha_incorporacion} onChange={set('fecha_incorporacion')} /></Campo>
    <Campo label="Fecha de emisión" required error={errors.fecha_emision}><input type="date" required max={hoy()} value={draft.fecha_emision} onChange={set('fecha_emision')} /></Campo>
    <Campo label="Emisor" required error={errors.emisor}><input required maxLength={200} value={draft.emisor} onChange={set('emisor')} placeholder="Entidad u oficina que emite" /></Campo>
    <Campo label="N.º de resolución" required={tipo.requiere_num_resolucion} error={errors.num_resolucion}><input required={tipo.requiere_num_resolucion} maxLength={60} value={draft.num_resolucion} onChange={set('num_resolucion')} /></Campo>
    <Campo label="Titular (nombre)" required error={errors.titular_nombre}><input required maxLength={200} value={draft.titular_nombre} onChange={set('titular_nombre')} /></Campo>
    <Campo label="Titular (DNI u otro documento)" required error={errors.titular_documento}><input required maxLength={20} value={draft.titular_documento} onChange={set('titular_documento')} /></Campo>
    <Campo label="Relación con el titular" required error={errors.relacion_titular}><select required value={draft.relacion_titular} onChange={set('relacion_titular')}><Opciones lista={relacionesTitular} /></select></Campo>
    <Campo label="Modalidad de presentación" required error={errors.modalidad_presentacion}><select required value={draft.modalidad_presentacion} onChange={set('modalidad_presentacion')}><Opciones lista={modalidadesPresentacion} vacio="Selecciona" /></select></Campo>
    {vigentes.length > 0 && <Campo label={debeSubsanar ? 'Documento que subsana (este tipo admite uno solo)' : 'Subsana a otro documento'} required={debeSubsanar} error={errors.subsana_a_documento_id} className="sm:col-span-2">
      <select required={debeSubsanar} value={draft.subsana_a_documento_id} onChange={set('subsana_a_documento_id')}>
        <option value="">{debeSubsanar ? 'Selecciona' : 'No, es un documento adicional'}</option>
        {vigentes.map(documento => <option key={documento.id} value={documento.id}>Folios {folios(documento.values.folio_inicio, documento.values.folio_fin)} · emitido {documento.values.fecha_emision || 's/f'} · {documento.values.emisor || 'sin emisor'}</option>)}
      </select></Campo>}
    {enlaces && <Campo label="Registro que acredita" error={errors.enlace_id} className="sm:col-span-2">
      <select value={draft.enlace_id} onChange={set('enlace_id')}>
        <option value="">Registrar uno nuevo después de subir</option>
        {enlaces.map(enlace => <option key={enlace.id} value={enlace.id}>{enlace.etiqueta}</option>)}
      </select></Campo>}
    <Campo label="Motivo de incorporación" error={errors.motivo_incorporacion} className="sm:col-span-2"><input maxLength={300} value={draft.motivo_incorporacion} onChange={set('motivo_incorporacion')} /></Campo>
    </FormSplit>
  </Formulario>
}
