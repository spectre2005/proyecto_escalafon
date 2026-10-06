import { saveSeccion10 } from '../../api/seccion10Api'
import { estadosTramite, tiposAfiliacionSalud, tiposRegistroSst, tramites } from '../../config/seccion10'
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

function useGuardar10(usuario, tipo, record, documentoId, inicial, onSaved) {
  return useGuardar(data => saveSeccion10(usuario, tipo, record?.id, record ? cambios(inicial, data) : conDocumento(data, documentoId)), onSaved)
}

// Registro de SST o bienestar (registros_sst_bienestar). permitidos: tipos del bloque o del documento (si es uno, queda fijo).
// salud: accidentes y enfermedades; la descripción no debe llevar diagnósticos ni detalle clínico.
export function RegistroSstForm({ usuario, record, documentoId, permitidos, salud, onSaved, onCancel }) {
  const v = record?.values || {}
  const lista = Object.fromEntries(permitidos.map(tipo => [tipo, tiposRegistroSst[tipo] || tipo]))
  const inicial = { tipo: v.tipo || (permitidos.length === 1 ? permitidos[0] : ''), fecha: v.fecha || '', descripcion: v.descripcion || '', estado_tramite: v.estado_tramite || 'no_aplica' }
  const [draft, set] = useBorrador(inicial)
  const tramite = tramites.includes(draft.tipo)
  const { busy, error, errors, guardar } = useGuardar10(usuario, 'registros', record, documentoId, inicial, onSaved)
  const fijo = permitidos.length === 1
  // Fuera de los trámites el estado no aplica (lo exige el backend).
  return <Formulario titulo={`${record ? 'Corregir' : 'Registrar'}: ${fijo ? lista[draft.tipo] : 'registro'}`} guardar="Guardar registro" busy={busy} error={error} onSubmit={() => guardar(tramite ? draft : { ...draft, estado_tramite: 'no_aplica' })} onCancel={onCancel} nota={nota(record)}>
    <NotaDocumento documentoId={documentoId} />
    {!fijo && <Campo label="Tipo" required error={errors.tipo}><select required value={draft.tipo} onChange={set('tipo')}><Opciones lista={lista} actual={draft.tipo} vacio="Selecciona" /></select></Campo>}
    {fijo && errors.tipo && <p role="alert" className="text-xs text-rose-700 sm:col-span-2">{[].concat(errors.tipo).join(' ')}</p>}
    <Campo label="Fecha" required error={errors.fecha}><input type="date" required value={draft.fecha} onChange={set('fecha')} /></Campo>
    {tramite && <Campo label="Estado del trámite" error={errors.estado_tramite}><select value={draft.estado_tramite} onChange={set('estado_tramite')}><Opciones lista={estadosTramite} actual={draft.estado_tramite} /></select></Campo>}
    <Campo label="Descripción" error={errors.descripcion} className="sm:col-span-2"><input maxLength={300} value={draft.descripcion} onChange={set('descripcion')} placeholder={salud ? 'Breve, sin diagnósticos ni detalle clínico' : ''} /></Campo>
    {salud && <p className="text-xs text-amber-800 sm:col-span-2">Dato de salud: registra solo el hecho y la fecha. El detalle médico queda en el documento, que solo ven quienes tienen acceso a datos sensibles.</p>}
  </Formulario>
}

// Afiliación a EPS o Seguro Social (afiliaciones con tipo eps o seguro_social). Sindicato es de la sección 09.
export function AfiliacionSaludForm({ usuario, record, documentoId, onSaved, onCancel }) {
  const v = record?.values || {}
  const inicial = { tipo: v.tipo || '', entidad: v.entidad || '', numero_afiliacion: v.numero_afiliacion || '', fecha_inicio: v.fecha_inicio || '', fecha_fin: v.fecha_fin || '' }
  const [draft, set] = useBorrador(inicial)
  const { busy, error, errors, guardar } = useGuardar10(usuario, 'afiliaciones', record, documentoId, inicial, onSaved)
  return <Formulario titulo={record ? 'Corregir afiliación' : 'Registrar afiliación a EPS o Seguro Social'} guardar="Guardar afiliación" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel} nota={nota(record)}>
    <NotaDocumento documentoId={documentoId} />
    <Campo label="Tipo de afiliación" required error={errors.tipo}><select required value={draft.tipo} onChange={set('tipo')}><Opciones lista={tiposAfiliacionSalud} actual={draft.tipo} vacio="Selecciona" /></select></Campo>
    <Campo label="Entidad" required error={errors.entidad}><input required maxLength={150} value={draft.entidad} onChange={set('entidad')} /></Campo>
    <Campo label="N.º de afiliación (si corresponde)" error={errors.numero_afiliacion}><input maxLength={40} value={draft.numero_afiliacion} onChange={set('numero_afiliacion')} /></Campo>
    <span className="hidden sm:block" />
    <Campo label="Fecha de inicio" required error={errors.fecha_inicio}><input type="date" required value={draft.fecha_inicio} onChange={set('fecha_inicio')} /></Campo>
    <Campo label="Fecha de fin (si corresponde)" error={errors.fecha_fin}><input type="date" min={draft.fecha_inicio || undefined} value={draft.fecha_fin} onChange={set('fecha_fin')} /></Campo>
  </Formulario>
}
