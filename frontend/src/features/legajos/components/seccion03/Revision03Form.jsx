import { useEffect, useRef } from 'react'
import Campo, { Formulario } from '../seccion01/Campo'
import { hoy, useBorrador, useGuardar } from '../seccion01/useGuardar'
import { revisarDocumento03 } from '../../api/seccion03Api'

export default function Revision03Form({ usuario, documento, item, estado, onSaved, onCancel, revisar = revisarDocumento03, fechaActual = hoy(), nota = 'La revisión queda en el historial con tu nombre. La aceptación no acredita una fiscalización posterior con SUNEDU o MINEDU.' }) {
  const primero = useRef(null)
  const fedateoRegistrado = Boolean(documento.fedateado && documento.fecha_fedateo && documento.fedatario_id && documento.fedateo_sustento_documento_id)
  useEffect(() => { primero.current?.focus() }, [])
  const [draft, set] = useBorrador({ descripcion: '', plazo_subsanacion: '', recepcion_confirmada: false })
  const { busy, error, errors, guardar } = useGuardar(values => revisar(usuario, documento.id, {
    estado, estado_anterior: documento.estado_original,
    ...(estado === 'observado' ? { descripcion: values.descripcion, plazo_subsanacion: values.plazo_subsanacion } : estado === 'anulado' ? { descripcion: values.descripcion } : { recepcion_confirmada: values.recepcion_confirmada }),
  }), onSaved)
  return <Formulario titulo={estado === 'aceptado' ? 'Aceptar documento' : estado === 'anulado' ? 'Anular documento' : 'Observar documento'} guardar={estado === 'aceptado' ? 'Confirmar aceptación' : estado === 'anulado' ? 'Confirmar anulación' : 'Guardar observación'} busy={busy} error={error} onCancel={onCancel} onSubmit={() => guardar(draft)}
    nota={nota}>
    {estado !== 'aceptado' ? <>
      <Campo label={estado === 'anulado' ? 'Motivo de la anulación' : 'Qué debe corregirse'} required error={errors.descripcion} className="sm:col-span-2"><textarea ref={primero} required maxLength={3000} rows={3} value={draft.descripcion} onChange={set('descripcion')} /></Campo>
      {estado === 'observado' && <Campo label="Fecha límite para subsanar" required error={errors.plazo_subsanacion}><input type="date" required min={fechaActual} value={draft.plazo_subsanacion} onChange={set('plazo_subsanacion')} /></Campo>}
    </> : <>
      <label className="col-span-full flex items-start gap-3 text-sm text-slate-700"><input ref={primero} type="checkbox" required checked={draft.recepcion_confirmada} onChange={set('recepcion_confirmada')} className="mt-1 size-4 accent-teal-700" />Confirmo la recepción y la revisión del sello, los folios y el contenido.</label>
      {(item.requiere_fedateo || documento.fedateado) && <p className={`col-span-full rounded-xl p-3 text-sm ${fedateoRegistrado ? 'bg-teal-50 text-teal-900' : 'bg-amber-50 text-amber-900'}`}>{fedateoRegistrado ? 'Fedateo registrado con sustento. El servidor comprobará que el PDF siga disponible antes de aceptar.' : 'Este documento necesita un fedateo registrado con PDF de sustento. Usa Revisión documental → Registrar fedateo con sustento y vuelve a abrir la aceptación. Aceptar no registra el fedateo.'}</p>}
      {['recepcion_confirmada', 'fedateado', 'estado'].map(key => errors[key] && <p key={key} role="alert" className="col-span-full text-sm text-rose-700">{errors[key].join(' ')}</p>)}
    </>}
  </Formulario>
}
