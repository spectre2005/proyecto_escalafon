import { saveDesvinculacion } from '../../api/seccion11Api'
import { etiquetaVinculo11, motivosCese } from '../../config/seccion11'
import Campo, { Formulario, Opciones } from '../seccion01/Campo'
import { useBorrador, useGuardar, hoy } from '../seccion01/useGuardar'

const fecha = value => value ? value.split('-').reverse().join('/') : 'sin fecha'

// Corrección parcial: solo se envían los campos que cambiaron (el backend valida contra lo guardado).
const cambios = (inicial, data) => Object.fromEntries(Object.entries(data).filter(([key, value]) => (value ?? '') !== (inicial[key] ?? '')))

/**
 * Cese y desvinculación en una sola operación. Los vínculos ya cesados conservan su fecha. vinculos: los que se pueden elegir. motivos: los que admite el documento recién subido (null = todos).
 */
export default function DesvinculacionForm({ usuario, record, vinculos, motivos, documentoId, onSaved, onCancel }) {
  const v = record?.values || {}
  const inicial = {
    vinculo_laboral_id: v.vinculo_laboral_id || (vinculos.length === 1 ? vinculos[0].id : ''), motivo_cese: v.motivo_cese || (motivos?.length === 1 ? motivos[0] : ''),
    numero_resolucion: v.numero_resolucion || '', fecha_entrega_cargo: v.fecha_entrega_cargo || '', informe_gestion_presentado: v.informe_gestion_presentado ?? false,
    fecha_liquidacion: v.fecha_liquidacion || '', observaciones: v.observaciones || '',
    fecha_cese: v.fecha_cese || '', confirmar_cierre_vinculo: false,
  }
  const [draft, set] = useBorrador(inicial)
  const vinculo = vinculos.find(item => item.id === draft.vinculo_laboral_id)
  const fechaCese = vinculo?.values.fecha_cese || draft.fecha_cese || ''
  const lista = motivos ? Object.fromEntries(motivos.map(m => [m, motivosCese[m] || m])) : motivosCese
  const enviar = data => {
    const { confirmar_cierre_vinculo, ...campos } = data
    const datos = { ...campos, fecha_cese: fechaCese || null }
    if (!record) return saveDesvinculacion(usuario, null, { ...datos, registrar_cese: true, confirmar_cierre_vinculo, ...(documentoId ? { documento_id: documentoId } : {}) })
    return saveDesvinculacion(usuario, record.id, cambios({ ...inicial, fecha_cese: v.fecha_cese }, datos))
  }
  const { busy, error, errors, guardar } = useGuardar(enviar, onSaved)
  return <Formulario titulo={record ? 'Corregir desvinculación' : 'Registrar cese y desvinculación'} guardar={record ? 'Guardar corrección' : 'Confirmar cese y desvinculación'} busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel}
    nota={record ? 'Se corrigen solo los campos que cambies. Un registro anulado ya no se corrige.' : 'No se elimina: si hay un error, se corrige o se anula con motivo (administración). El responsable se guarda con tu sesión.'}>
    {documentoId && <p className="rounded-lg bg-teal-50 p-3 text-xs text-teal-800 sm:col-span-2">La desvinculación quedará acreditada por el documento que acabas de subir.</p>}
    <Campo label="Vínculo laboral que termina" required error={errors.vinculo_laboral_id} className="sm:col-span-2"><select required value={draft.vinculo_laboral_id} onChange={set('vinculo_laboral_id')}>
      <option value="">Selecciona</option>
      {vinculos.map(item => <option key={item.id} value={item.id}>{etiquetaVinculo11(item)}</option>)}
    </select></Campo>
    <Campo label="Fecha efectiva de cese" required error={errors.fecha_cese}>{vinculo?.values.fecha_cese
      ? <input readOnly value={fecha(fechaCese)} />
      : <input required type="date" min={vinculo?.values.fecha_inicio || undefined} max={hoy()} value={draft.fecha_cese} onChange={set('fecha_cese')} />}</Campo>
    <Campo label="Motivo de cese" required error={errors.motivo_cese}><select required value={draft.motivo_cese} onChange={set('motivo_cese')}><Opciones lista={lista} actual={draft.motivo_cese} vacio="Selecciona" /></select></Campo>
    <Campo label="N.º de resolución (si corresponde)" error={errors.numero_resolucion}><input maxLength={60} value={draft.numero_resolucion} onChange={set('numero_resolucion')} /></Campo>
    <Campo label="Fecha de entrega de cargo" error={errors.fecha_entrega_cargo}><input type="date" value={draft.fecha_entrega_cargo} onChange={set('fecha_entrega_cargo')} /></Campo>
    <Campo label="Fecha de liquidación" error={errors.fecha_liquidacion}><input type="date" value={draft.fecha_liquidacion} onChange={set('fecha_liquidacion')} /></Campo>
    <label className="flex items-center gap-2 self-end pb-2 text-sm text-slate-700"><input type="checkbox" className="size-4 accent-teal-700" checked={draft.informe_gestion_presentado} onChange={set('informe_gestion_presentado')} />Presentó informe de gestión</label>
    {!record && <label className="col-span-full flex items-start gap-3 rounded-xl bg-amber-50 p-4 text-sm text-amber-950"><input required type="checkbox" checked={draft.confirmar_cierre_vinculo} onChange={set('confirmar_cierre_vinculo')} />Confirmo la fecha y el motivo. El vínculo quedará cerrado y no admitirá nuevas adendas. El legajo se conserva activo hasta que se cierre expresamente.</label>}
    <Campo label="Observaciones" error={errors.observaciones} className="sm:col-span-2"><textarea rows={2} maxLength={2000} value={draft.observaciones} onChange={set('observaciones')} /></Campo>
  </Formulario>
}
