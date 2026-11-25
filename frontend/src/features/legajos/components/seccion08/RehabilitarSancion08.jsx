import Campo, { Formulario } from '../seccion01/Campo'
import { useBorrador, useGuardar } from '../seccion01/useGuardar'
import { rehabilitarSancion08 } from '../../api/seccion08Api'

// Rehabilitación de una sanción: se anota una sola vez con la resolución que la dispone. La sanción no se borra ni se
// edita; desde entonces no sale en el informe escalafonario.
export default function RehabilitarSancion08({ usuario, documento, fechaActual, onSaved, onCancel }) {
  const [draft, set] = useBorrador({ fecha: '', numero_resolucion: '' })
  const { busy, error, errors, guardar } = useGuardar(values => rehabilitarSancion08(usuario, documento.registro_sancion_id, values), onSaved)
  return <Formulario modal titulo="Registrar rehabilitación" guardar="Registrar rehabilitación" busy={busy} error={error} onCancel={onCancel} onSubmit={() => guardar(draft)}
    nota="La sanción y su documento se conservan. Al registrar la rehabilitación deja de mostrarse en el informe escalafonario; no se puede deshacer.">
    <p className="col-span-full text-sm text-slate-600">{documento.nombre}{documento.num_resolucion && ` · Resolución ${documento.num_resolucion}`}</p>
    <Campo label="Fecha de la rehabilitación" required error={errors.fecha}><input type="date" required min={documento.fecha_emision || undefined} max={fechaActual} value={draft.fecha} onChange={set('fecha')} /></Campo>
    <Campo label="N.º de resolución que la dispone" required error={errors.numero_resolucion}><input required maxLength={60} value={draft.numero_resolucion} onChange={set('numero_resolucion')} /></Campo>
  </Formulario>
}
