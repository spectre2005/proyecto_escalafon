import FormSplit from '../../../../components/pdf/FormSplit'
import PdfPreviewField from '../../../../components/pdf/PdfPreviewField'
import { useRef, useState } from 'react'
import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { subirDocumento } from '../../api/documentosApi'
import { aplicarPrecarga, CAMPOS_PRECARGA, precargaDesdeDocumento } from '../../config/acreditacion'
import { folios, modalidadesPresentacion, relacionesTitular } from '../../config/documentos'
import Campo, { Formulario, Opciones } from '../seccion01/Campo'
import { hoy, useBorrador, useGuardar } from '../seccion01/useGuardar'

/**
 * Subir un documento (PDF + metadatos obligatorios) a un tipo de documento. Común a todas las secciones.
 * El folio inicial es siempre el siguiente de la sección (sin huecos ni solapes); lo calcula el backend.
 * tipo: { id, nombre, literal, requiere_num_resolucion, permite_multiples, formulario_datos, lista }
 * enlaces: registros existentes que el documento puede acreditar ({ id, etiqueta }), o null si el tipo no tiene formulario.
 * relacion: relación con el titular que se propone (por defecto, el propio servidor).
 * onSaved(documento): recibe la respuesta del backend (formulario_datos, enlazado) para abrir el formulario de datos.
 * registroFijo ({ id, etiqueta }): «Adjuntar documento» desde un registro; el enlace queda fijo (enlace_id).
 * enlaceInicial: registro propuesto en la lista (p. ej. el vínculo vigente); el usuario puede cambiarlo.
 * precarga(enlaceId): datos del registro elegido que corresponden al documento (número, fecha, titular de un familiar).
 *   Se proponen sin bloquearlos y sin pisar lo que el usuario ya escribió.
 * subsanar (documento de tipo.lista): subsanación de ese documento. Va con subsana_a_documento_id fijo (el backend la
 *   admite aunque el tipo ya no se ofrezca para cargas nuevas) y propone sus metadatos para corregir solo lo necesario.
 */
export default function SubirDocumentoForm({ usuario, seccion, tipo, titular, enlaces, relacion = 'servidor', onSaved, onCancel, modal = Number(seccion) !== 10,
  registroFijo = null, enlaceInicial = '', precarga = null, subsanar = null }) {
  const identidadBloqueada = tipo.codigo === 'S01_B_DOCUMENTO_IDENTIDAD'
  const folio = useLiveQuery(`/expedientes/${usuario}/documentos/folio-sugerido?seccion=${seccion}`)
  const sugerido = folio.data?.data.folio_sugerido
  const maximoKb = folio.data?.data.tamano_maximo_kb
  const vigentes = (tipo.lista || []).filter(documento => !documento.subsanado)
  const debeSubsanar = !subsanar && !tipo.permite_multiples && vigentes.length > 0
  const [pdf, setPdf] = useState(null)
  const [errorPdf, setErrorPdf] = useState('')
  const respuesta = useRef(null)
  // Valores de partida de los campos que puede proponer un registro (titular del expediente, sin número ni fecha).
  const base = { num_resolucion: '', fecha_emision: '', titular_nombre: titular?.nombre || '', titular_documento: titular?.documento || '', relacion_titular: relacion }
  const propuesta = enlaceId => ({ ...base, ...(enlaceId && precarga ? precarga(enlaceId) : {}) })
  const enlaceDePartida = registroFijo?.id || enlaceInicial || ''
  const [ultimaPropuesta, setUltimaPropuesta] = useState(() => propuesta(enlaceDePartida))
  const [draft, set, poner] = useBorrador({
    folio_fin: '', fecha_incorporacion: hoy(), emisor: '', fecha_caducidad: '', modalidad_presentacion: '', motivo_incorporacion: '',
    ...ultimaPropuesta,
    ...(subsanar ? precargaDesdeDocumento(subsanar.values) : {}),
    subsana_a_documento_id: subsanar ? subsanar.id : debeSubsanar && vigentes.length === 1 ? vigentes[0].id : '', enlace_id: enlaceDePartida,
  })
  // Al elegir otro registro, se proponen sus datos sin pisar lo que el usuario escribió.
  function elegirEnlace(event) {
    const id = event.target.value
    const nueva = propuesta(id)
    const siguiente = aplicarPrecarga(draft, ultimaPropuesta, nueva, CAMPOS_PRECARGA)
    setUltimaPropuesta(nueva)
    poner('enlace_id')(id)
    for (const campo of CAMPOS_PRECARGA) if (siguiente[campo] !== draft[campo]) poner(campo)(siguiente[campo])
  }
  const { busy, error, errors, guardar } = useGuardar(async data => {
    const payload = identidadBloqueada
      ? { fecha_incorporacion: data.fecha_incorporacion, fecha_emision: data.fecha_emision, fecha_caducidad: data.fecha_caducidad }
      : { ...data, folio_inicio: sugerido }
    respuesta.current = (await subirDocumento(usuario, { ...payload, seccion_legajo_id: seccion, tipo_documento_id: tipo.id }, pdf)).data.data
  }, () => onSaved(respuesta.current))

  if (identidadBloqueada) return <Formulario modal={modal} titulo="Subir copia de DNI" guardar="Guardar DNI" busy={busy} error={error}
    onSubmit={() => pdf && !errorPdf && guardar(draft)} onCancel={onCancel}
    nota="Al actualizar el DNI, el nuevo PDF queda vigente y la copia anterior se conserva en el historial.">
    <FormSplit pdf={<PdfPreviewField label={`Archivo PDF${maximoKb ? ` (máx. ${maximoKb} KB)` : ''}`} required maxMB={maximoKb ? maximoKb / 1024 : 2} help="Selecciona la copia del DNI para revisarla antes de guardar." error={errorPdf || errors.pdf} onChange={(file, message) => { setPdf(file); setErrorPdf(message) }} />}>
      <Campo label="Nombre" error={errors.titular_nombre}><input readOnly value={draft.titular_nombre} /></Campo>
      <Campo label="DNI" error={errors.titular_documento}><input readOnly value={draft.titular_documento} /></Campo>
      <Campo label="Fecha de incorporación" required error={errors.fecha_incorporacion}><input type="date" required max={hoy()} value={draft.fecha_incorporacion} onChange={set('fecha_incorporacion')} /></Campo>
      <Campo label="Fecha de emisión" required error={errors.fecha_emision}><input type="date" required max={hoy()} value={draft.fecha_emision} onChange={set('fecha_emision')} /></Campo>
      <Campo label="Fecha de caducidad" required error={errors.fecha_caducidad}><input type="date" required min={draft.fecha_emision || undefined} value={draft.fecha_caducidad} onChange={set('fecha_caducidad')} /></Campo>
    </FormSplit>
  </Formulario>

  const subsanado = subsanar || vigentes.find(documento => documento.id === draft.subsana_a_documento_id)
  const resumen = documento => `Folios ${folios(documento.values.folio_inicio, documento.values.folio_fin)} · emitido ${documento.values.fecha_emision || 's/f'} · ${documento.values.emisor || 'sin emisor'}`
  // Opciones adicionales: plegadas salvo que tengan un error. La subsanación obligatoria (tipo de un solo documento) va arriba.
  const subsanaOpcional = !subsanar && !debeSubsanar && vigentes.length > 0
  const nombreTipo = `${tipo.literal ? `${tipo.literal}) ` : ''}${tipo.nombre}`
  return <Formulario modal={modal} titulo={subsanar ? `Subsanar documento: ${nombreTipo}` : `Subir documento: ${nombreTipo}`} guardar={subsanar ? 'Subir documento que subsana' : 'Subir documento'} busy={busy} error={error}
    onSubmit={() => sugerido && pdf && !errorPdf && guardar(draft)} onCancel={onCancel}
    nota="El documento no se puede eliminar ni reemplazar después: si hay un error, se sube uno nuevo que lo subsana. Quien sube queda registrado con tu sesión.">
    <FormSplit pdf={<PdfPreviewField label={`Archivo PDF${maximoKb ? ` (máx. ${maximoKb} KB)` : ''}`} required maxMB={maximoKb ? maximoKb / 1024 : 2} help="Selecciona el PDF para ver su contenido antes de guardarlo." error={errorPdf || errors.pdf} onChange={(file, message) => { setPdf(file); setErrorPdf(message) }} />}>
    {subsanar && <p role="note" className="rounded-lg bg-amber-50 p-3 text-xs text-amber-900 sm:col-span-2">Subsana a: {resumen(subsanar)}. Se proponen sus datos: cambia solo lo que corrige. El documento anterior se conserva en el historial.{errors.subsana_a_documento_id ? ` ${[].concat(errors.subsana_a_documento_id).join(' ')}` : ''}</p>}
    {registroFijo && <p role="note" className="rounded-lg bg-teal-50 p-3 text-xs text-teal-900 sm:col-span-2">Acredita: <strong>{registroFijo.etiqueta}</strong>. Los datos del registro ya guardados no se vuelven a pedir.{errors.enlace_id ? ` ${[].concat(errors.enlace_id).join(' ')}` : ''}</p>}
    <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500 sm:col-span-2">Metadatos del documento</p>
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
    {debeSubsanar && <Campo label="Documento que subsana (este tipo admite uno solo)" required error={errors.subsana_a_documento_id} className="sm:col-span-2">
      <select required value={draft.subsana_a_documento_id} onChange={set('subsana_a_documento_id')}>
        <option value="">Selecciona</option>
        {vigentes.map(documento => <option key={documento.id} value={documento.id}>{resumen(documento)}</option>)}
      </select></Campo>}
    {enlaces && !registroFijo && <Campo label="Registro que acredita" error={errors.enlace_id} className="sm:col-span-2">
      <select value={draft.enlace_id} onChange={elegirEnlace}>
        <option value="">Registrar uno nuevo después de subir</option>
        {enlaces.map(enlace => <option key={enlace.id} value={enlace.id}>{enlace.etiqueta}</option>)}
      </select></Campo>}
    <details open={!!(errors.motivo_incorporacion || (subsanaOpcional && errors.subsana_a_documento_id))} className="group rounded-xl border border-slate-200 sm:col-span-2">
      <summary className="cursor-pointer list-none px-4 py-3 text-xs font-semibold text-slate-600">Opciones adicionales <span className="font-normal text-slate-400">{subsanaOpcional ? '(motivo y subsanación)' : '(motivo)'}{subsanado && !subsanar && !debeSubsanar ? ' · subsana a un documento' : ''}</span></summary>
      <div className="grid gap-4 border-t border-slate-100 p-4 sm:grid-cols-2">
        {subsanaOpcional && <Campo label="Subsana a otro documento" error={errors.subsana_a_documento_id} className="sm:col-span-2">
          <select value={draft.subsana_a_documento_id} onChange={set('subsana_a_documento_id')}>
            <option value="">No, es un documento adicional</option>
            {vigentes.map(documento => <option key={documento.id} value={documento.id}>{resumen(documento)}</option>)}
          </select></Campo>}
        <Campo label="Motivo de incorporación" error={errors.motivo_incorporacion} className="sm:col-span-2"><input maxLength={300} value={draft.motivo_incorporacion} onChange={set('motivo_incorporacion')} /></Campo>
      </div>
    </details>
    </FormSplit>
  </Formulario>
}
