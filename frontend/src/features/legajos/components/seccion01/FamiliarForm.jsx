import { saveFamiliar } from '../../api/seccion01Api'
import { parentescos, tiposDocIdentidad } from '../../config/seccion01'
import Campo, { Formulario, Opciones } from './Campo'
import { conDocumento, solo } from './enlace'
import { NotaDocumento } from './Formularios'
import { hoy, useBorrador, useGuardar } from './useGuardar'

// parentescosPermitidos: los que corresponden al documento que el familiar acredita (i: cónyuge o concubino; j: hijo).
export default function FamiliarForm({ usuario, record, documentoId, parentescosPermitidos, onSaved, onCancel }) {
  const opciones = solo(parentescos, parentescosPermitidos)
  const values = record?.values || {}
  const [draft, set] = useBorrador({
    apellidos_nombres: values.apellidos_nombres || '',
    parentesco: values.parentesco || (Object.keys(opciones).length === 1 ? Object.keys(opciones)[0] : ''),
    tipo_doc_identidad: values.tipo_doc_identidad || '',
    numero_doc_identidad: values.numero_doc_identidad || '',
    fecha_nacimiento: values.fecha_nacimiento || '',
    es_derechohabiente: values.es_derechohabiente ?? false,
  })
  const { busy, error, errors, guardar } = useGuardar(data => saveFamiliar(usuario, record?.id, conDocumento({ ...data, numero_doc_identidad: data.tipo_doc_identidad ? data.numero_doc_identidad : null }, documentoId)), onSaved)

  return <Formulario titulo={record ? 'Corregir familiar' : 'Agregar familiar'} guardar="Guardar familiar" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel}>
    <NotaDocumento documentoId={documentoId} />
    <Campo label="Apellidos y nombres" required error={errors.apellidos_nombres}><input required maxLength={200} value={draft.apellidos_nombres} onChange={set('apellidos_nombres')} /></Campo>
    <Campo label="Parentesco" required error={errors.parentesco}><select required value={draft.parentesco} onChange={set('parentesco')}><Opciones lista={opciones} actual={draft.parentesco} vacio="Selecciona" /></select></Campo>
    <Campo label="Tipo de documento" error={errors.tipo_doc_identidad}><select value={draft.tipo_doc_identidad} onChange={set('tipo_doc_identidad')}><Opciones lista={tiposDocIdentidad} actual={draft.tipo_doc_identidad} vacio="Sin documento" /></select></Campo>
    <Campo label="Número de documento" required={!!draft.tipo_doc_identidad} error={errors.numero_doc_identidad}><input required={!!draft.tipo_doc_identidad} disabled={!draft.tipo_doc_identidad} maxLength={draft.tipo_doc_identidad === 'dni' ? 8 : 20} inputMode={draft.tipo_doc_identidad === 'dni' ? 'numeric' : undefined} value={draft.numero_doc_identidad} onChange={set('numero_doc_identidad')} /></Campo>
    <Campo label="Fecha de nacimiento" error={errors.fecha_nacimiento}><input type="date" max={hoy()} value={draft.fecha_nacimiento} onChange={set('fecha_nacimiento')} /></Campo>
    <label className="flex items-center gap-2 self-end pb-2 text-sm text-slate-700"><input type="checkbox" className="size-4 accent-teal-700" checked={draft.es_derechohabiente} onChange={set('es_derechohabiente')} />Es derechohabiente</label>
  </Formulario>
}
