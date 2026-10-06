import { saveSeccion09 } from '../../api/seccion09Api'
import { estadosControversia, instancias, tiposControversia } from '../../config/seccion09'
import Campo, { Formulario, Opciones } from '../seccion01/Campo'
import { useBorrador, useGuardar } from '../seccion01/useGuardar'

// documentoId: documento recién subido que este registro acredita (el formulario se abre al subir el PDF).
const conDocumento = (data, documentoId) => documentoId ? { ...data, documento_id: documentoId } : data

// Corrección parcial: solo se envían los campos que cambiaron (el backend valida contra lo guardado).
const cambios = (inicial, data) => Object.fromEntries(Object.entries(data).filter(([key, value]) => (value ?? '') !== (inicial[key] ?? '')))

const nota = record => record
  ? 'Se corrigen solo los campos que cambies. Un registro anulado ya no se corrige. El responsable se guarda con tu sesión.'
  : 'No se elimina: si hay un error, se corrige o se anula con motivo (administración). El responsable se guarda con tu sesión.'

function NotaDocumento({ documentoId }) {
  return documentoId ? <p className="rounded-lg bg-teal-50 p-3 text-xs text-teal-800 sm:col-span-2">El registro quedará enlazado al documento que acabas de subir.</p> : null
}

function useGuardar09(usuario, tipo, record, documentoId, inicial, onSaved) {
  return useGuardar(data => saveSeccion09(usuario, tipo, record?.id, record ? cambios(inicial, data) : conDocumento(data, documentoId)), onSaved)
}

// Controversia (controversias). El tipo (individual o colectiva) lo fija el bloque o el documento: no se elige aquí.
export function ControversiaForm({ usuario, record, documentoId, tipo, onSaved, onCancel }) {
  const v = record?.values || {}
  const inicial = { tipo: v.tipo || tipo, instancia: v.instancia || '', materia: v.materia || '', fecha_inicio: v.fecha_inicio || '', estado: v.estado || 'en_tramite', fecha_resolucion: v.fecha_resolucion || '', resultado: v.resultado || '' }
  const [draft, set] = useBorrador(inicial)
  const { busy, error, errors, guardar } = useGuardar09(usuario, 'controversias', record, documentoId, inicial, onSaved)
  const nombre = (tiposControversia[draft.tipo] || '').toLowerCase()
  return <Formulario titulo={record ? `Corregir controversia ${nombre}` : `Registrar controversia ${nombre}`} guardar="Guardar controversia" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel} nota={nota(record)}>
    <NotaDocumento documentoId={documentoId} />
    {errors.tipo && <p role="alert" className="text-xs text-rose-700 sm:col-span-2">{[].concat(errors.tipo).join(' ')}</p>}
    <Campo label="Instancia" required error={errors.instancia}><select required value={draft.instancia} onChange={set('instancia')}><Opciones lista={instancias} actual={draft.instancia} vacio="Selecciona" /></select></Campo>
    <Campo label="Fecha de inicio" required error={errors.fecha_inicio}><input type="date" required value={draft.fecha_inicio} onChange={set('fecha_inicio')} /></Campo>
    <Campo label="Materia" required error={errors.materia} className="sm:col-span-2"><input required maxLength={300} value={draft.materia} onChange={set('materia')} /></Campo>
    <Campo label="Estado" error={errors.estado}><select value={draft.estado} onChange={set('estado')}><Opciones lista={estadosControversia} actual={draft.estado} /></select></Campo>
    <Campo label="Fecha de resolución (si corresponde)" error={errors.fecha_resolucion}><input type="date" min={draft.fecha_inicio || undefined} value={draft.fecha_resolucion} onChange={set('fecha_resolucion')} /></Campo>
    <Campo label="Resultado (si corresponde)" error={errors.resultado} className="sm:col-span-2"><input maxLength={300} value={draft.resultado} onChange={set('resultado')} /></Campo>
  </Formulario>
}

// Afiliación al sindicato (afiliaciones con tipo sindicato, que fija el backend).
export function AfiliacionForm({ usuario, record, documentoId, onSaved, onCancel }) {
  const v = record?.values || {}
  const inicial = { entidad: v.entidad || '', numero_afiliacion: v.numero_afiliacion || '', fecha_inicio: v.fecha_inicio || '', fecha_fin: v.fecha_fin || '' }
  const [draft, set] = useBorrador(inicial)
  const { busy, error, errors, guardar } = useGuardar09(usuario, 'afiliaciones', record, documentoId, inicial, onSaved)
  return <Formulario titulo={record ? 'Corregir afiliación al sindicato' : 'Registrar afiliación al sindicato'} guardar="Guardar afiliación" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel} nota={nota(record)}>
    <NotaDocumento documentoId={documentoId} />
    <Campo label="Sindicato" required error={errors.entidad} className="sm:col-span-2"><input required maxLength={150} value={draft.entidad} onChange={set('entidad')} /></Campo>
    <Campo label="N.º de afiliación (si corresponde)" error={errors.numero_afiliacion}><input maxLength={40} value={draft.numero_afiliacion} onChange={set('numero_afiliacion')} /></Campo>
    <span className="hidden sm:block" />
    <Campo label="Fecha de inicio" required error={errors.fecha_inicio}><input type="date" required value={draft.fecha_inicio} onChange={set('fecha_inicio')} /></Campo>
    <Campo label="Fecha de fin (si corresponde)" error={errors.fecha_fin}><input type="date" min={draft.fecha_inicio || undefined} value={draft.fecha_fin} onChange={set('fecha_fin')} /></Campo>
  </Formulario>
}
