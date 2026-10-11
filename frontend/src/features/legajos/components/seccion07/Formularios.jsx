import { saveSeccion07 } from '../../api/seccion07Api'
import { categoriasDocente, resultadosRatificacion, tiposDesplazamiento, tiposRatificacion } from '../../config/seccion07'
import Campo, { Formulario, Opciones } from '../seccion01/Campo'
import { hoy, useBorrador, useGuardar } from '../seccion01/useGuardar'

// documentoId: documento recién subido que este registro acredita (el formulario se abre al subir el PDF).
const conDocumento = (data, documentoId) => documentoId ? { ...data, documento_id: documentoId } : data

// Corrección parcial: solo se envían los campos que cambiaron (el backend valida contra lo guardado).
const cambios = (inicial, data) => Object.fromEntries(Object.entries(data).filter(([key, value]) => (value ?? '') !== (inicial[key] ?? '')))

function NotaDocumento({ documentoId }) {
  return documentoId ? <p className="rounded-lg bg-teal-50 p-3 text-xs text-teal-800 sm:col-span-2">El registro quedará enlazado al documento que acabas de subir.</p> : null
}

function OpcionesLista({ lista, vacio = 'Selecciona' }) {
  return <>
    <option value="">{vacio}</option>
    {lista.map(item => <option key={item.id} value={item.id}>{item.etiqueta || item.nombre}</option>)}
  </>
}

// Guardar: alta (con el documento, si lo hay) o corrección de los campos cambiados.
function useGuardar07(usuario, tipo, record, documentoId, inicial, onSaved) {
  return useGuardar(data => saveSeccion07(usuario, tipo, record?.id, record ? cambios(inicial, data) : conDocumento(data, documentoId)), onSaved)
}

const nota = record => record
  ? 'Se corrigen solo los campos que cambies. Un registro anulado ya no se corrige. El responsable se guarda con tu sesión.'
  : 'No se elimina: si hay un error, se corrige o se anula con motivo (administración). El responsable se guarda con tu sesión.'

// Evaluación de desempeño (evaluaciones_desempeno). Sin seccion07.ver_calificacion la calificación guardada no se muestra:
// al corregir, esos campos quedan vacíos y solo se envían si se escriben. Con la puntuación numérica desactivada en la
// configuración (puntajeActivo === false) el campo puntaje no se muestra ni se envía; el puntaje guardado se conserva.
export function EvaluacionForm({ usuario, record, documentoId, veCalificacion, puntajeActivo, onSaved, onCancel }) {
  const v = record?.values || {}
  const oculto = !!record && !veCalificacion
  const conPuntaje = puntajeActivo !== false
  const inicial = { ciclo_anio: v.ciclo_anio ? String(v.ciclo_anio) : '', fecha_evaluacion: v.fecha_evaluacion || '', calificacion: oculto ? '' : v.calificacion || '', ...(conPuntaje ? { puntaje: oculto || v.puntaje == null ? '' : String(v.puntaje) } : {}), plan_mejora: v.plan_mejora ?? false }
  const [draft, set] = useBorrador(inicial)
  const { busy, error, errors, guardar } = useGuardar07(usuario, 'evaluaciones-desempeno', record, documentoId, inicial, onSaved)
  return <Formulario titulo={record ? `Corregir evaluación del ciclo ${v.ciclo_anio}` : 'Registrar evaluación de desempeño'} guardar="Guardar evaluación" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel} nota={nota(record)}>
    <NotaDocumento documentoId={documentoId} />
    <Campo label="Ciclo (año)" required error={errors.ciclo_anio}><input type="number" required min={1900} max={new Date().getFullYear() + 1} value={draft.ciclo_anio} onChange={set('ciclo_anio')} /></Campo>
    <Campo label="Fecha de evaluación" error={errors.fecha_evaluacion}><input type="date" max={hoy()} value={draft.fecha_evaluacion} onChange={set('fecha_evaluacion')} /></Campo>
    <Campo label={oculto ? 'Calificación (reservada: vacío conserva la guardada)' : 'Calificación'} required={!record} error={errors.calificacion}><input required={!record} maxLength={40} value={draft.calificacion} onChange={set('calificacion')} /></Campo>
    {conPuntaje && <Campo label={oculto ? 'Puntaje (reservado: vacío conserva el guardado)' : 'Puntaje'} error={errors.puntaje}><input type="number" step="0.01" min={0} max={999.99} value={draft.puntaje} onChange={set('puntaje')} /></Campo>}
    <label className="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2"><input type="checkbox" className="size-4 accent-teal-700" checked={draft.plan_mejora} onChange={set('plan_mejora')} />Tiene plan de mejora (el acta se sube en 7.1 b)</label>
  </Formulario>
}

// Progresión en la carrera (progresiones_carrera): solo con un vínculo administrativo de carrera.
export function ProgresionForm({ usuario, record, documentoId, vinculos, onSaved, onCancel }) {
  const v = record?.values || {}
  const inicial = { vinculo_laboral_id: v.vinculo_laboral_id || (vinculos.length === 1 ? vinculos[0].id : ''), nivel_anterior: v.nivel_anterior || '', nivel_nuevo: v.nivel_nuevo || '', fecha_efectiva: v.fecha_efectiva || '', numero_resolucion: v.numero_resolucion || '' }
  const [draft, set] = useBorrador(inicial)
  const { busy, error, errors, guardar } = useGuardar07(usuario, 'progresiones-carrera', record, documentoId, inicial, onSaved)
  return <Formulario titulo={record ? 'Corregir progresión en la carrera' : 'Registrar progresión en la carrera'} guardar="Guardar progresión" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel} nota={nota(record)}>
    <NotaDocumento documentoId={documentoId} />
    <Campo label="Vínculo administrativo de carrera" required error={errors.vinculo_laboral_id} className="sm:col-span-2"><select required value={draft.vinculo_laboral_id} onChange={set('vinculo_laboral_id')}><OpcionesLista lista={vinculos} /></select></Campo>
    <Campo label="Nivel anterior" error={errors.nivel_anterior}><input maxLength={20} value={draft.nivel_anterior} onChange={set('nivel_anterior')} /></Campo>
    <Campo label="Nivel nuevo" required error={errors.nivel_nuevo}><input required maxLength={20} value={draft.nivel_nuevo} onChange={set('nivel_nuevo')} /></Campo>
    <Campo label="Fecha efectiva" required error={errors.fecha_efectiva}><input type="date" required value={draft.fecha_efectiva} onChange={set('fecha_efectiva')} /></Campo>
    <Campo label="N.º de resolución" required error={errors.numero_resolucion}><input required maxLength={60} value={draft.numero_resolucion} onChange={set('numero_resolucion')} /></Campo>
  </Formulario>
}

// Ratificación o promoción docente (ratificaciones_docentes): solo con el perfil docente de un vínculo de la persona.
export function RatificacionForm({ usuario, record, documentoId, perfiles, onSaved, onCancel }) {
  const v = record?.values || {}
  const inicial = {
    perfil_docente_id: v.perfil_docente_id || (perfiles.length === 1 ? perfiles[0].id : ''), tipo: v.tipo || '', categoria_anterior: v.categoria_anterior || '', categoria_nueva: v.categoria_nueva || '',
    numero_resolucion: v.numero_resolucion || '', fecha_resolucion: v.fecha_resolucion || '', fecha_inicio_periodo: v.fecha_inicio_periodo || '', fecha_vencimiento_periodo: v.fecha_vencimiento_periodo || '', resultado: v.resultado || 'pendiente',
  }
  const [draft, set] = useBorrador(inicial)
  const { busy, error, errors, guardar } = useGuardar07(usuario, 'ratificaciones-docentes', record, documentoId, inicial, onSaved)
  return <Formulario titulo={record ? 'Corregir ratificación o promoción docente' : 'Registrar ratificación o promoción docente'} guardar="Guardar" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel} nota={nota(record)}>
    <NotaDocumento documentoId={documentoId} />
    <Campo label="Perfil docente (vínculo)" required error={errors.perfil_docente_id} className="sm:col-span-2"><select required value={draft.perfil_docente_id} onChange={set('perfil_docente_id')}><OpcionesLista lista={perfiles} /></select></Campo>
    <Campo label="Tipo" required error={errors.tipo}><select required value={draft.tipo} onChange={set('tipo')}><Opciones lista={tiposRatificacion} actual={draft.tipo} vacio="Selecciona" /></select></Campo>
    <Campo label="Resultado" required error={errors.resultado}><select required value={draft.resultado} onChange={set('resultado')}><Opciones lista={resultadosRatificacion} actual={draft.resultado} /></select></Campo>
    <Campo label="Categoría anterior" error={errors.categoria_anterior}><select value={draft.categoria_anterior} onChange={set('categoria_anterior')}><Opciones lista={categoriasDocente} actual={draft.categoria_anterior} vacio="Sin categoría previa" /></select></Campo>
    <Campo label="Categoría nueva" required error={errors.categoria_nueva}><select required value={draft.categoria_nueva} onChange={set('categoria_nueva')}><Opciones lista={categoriasDocente} actual={draft.categoria_nueva} vacio="Selecciona" /></select></Campo>
    <Campo label="N.º de resolución" required error={errors.numero_resolucion}><input required maxLength={60} value={draft.numero_resolucion} onChange={set('numero_resolucion')} /></Campo>
    <Campo label="Fecha de resolución" required error={errors.fecha_resolucion}><input type="date" required max={hoy()} value={draft.fecha_resolucion} onChange={set('fecha_resolucion')} /></Campo>
    <Campo label="Inicio del período" required error={errors.fecha_inicio_periodo}><input type="date" required value={draft.fecha_inicio_periodo} onChange={set('fecha_inicio_periodo')} /></Campo>
    <Campo label="Vencimiento del período" required error={errors.fecha_vencimiento_periodo}><input type="date" required min={draft.fecha_inicio_periodo || undefined} value={draft.fecha_vencimiento_periodo} onChange={set('fecha_vencimiento_periodo')} /></Campo>
  </Formulario>
}

// Desplazamiento (desplazamientos). Si viene de un documento, el tipo lo fija el documento (permitidos).
export function DesplazamientoForm({ usuario, record, documentoId, permitidos, unidades, onSaved, onCancel }) {
  const v = record?.values || {}
  const lista = permitidos ? Object.fromEntries(permitidos.map(tipo => [tipo, tiposDesplazamiento[tipo] || tipo])) : tiposDesplazamiento
  const inicial = { tipo: v.tipo || (permitidos?.length === 1 ? permitidos[0] : ''), unidad_origen_id: v.unidad_origen_id || '', unidad_destino_id: v.unidad_destino_id || '', puesto_destino: v.puesto_destino || '', fecha_inicio: v.fecha_inicio || '', fecha_fin: v.fecha_fin || '', numero_documento: v.numero_documento || '' }
  const [draft, set] = useBorrador(inicial)
  const { busy, error, errors, guardar } = useGuardar07(usuario, 'desplazamientos', record, documentoId, inicial, onSaved)
  const fijo = permitidos?.length === 1
  return <Formulario titulo={record ? 'Corregir desplazamiento' : 'Registrar desplazamiento'} guardar="Guardar desplazamiento" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel} nota={nota(record)}>
    <NotaDocumento documentoId={documentoId} />
    <Campo label={fijo ? 'Tipo (lo fija el documento)' : 'Tipo'} required error={errors.tipo}><select required disabled={fijo} value={draft.tipo} onChange={set('tipo')}><Opciones lista={lista} actual={draft.tipo} vacio="Selecciona" /></select></Campo>
    <Campo label="N.º de documento (resolución, memorándum…)" error={errors.numero_documento}><input maxLength={60} value={draft.numero_documento} onChange={set('numero_documento')} /></Campo>
    <Campo label="Unidad de origen" error={errors.unidad_origen_id}><select value={draft.unidad_origen_id} onChange={set('unidad_origen_id')}><OpcionesLista lista={unidades} vacio="Sin registrar" /></select></Campo>
    <Campo label="Unidad de destino" error={errors.unidad_destino_id}><select value={draft.unidad_destino_id} onChange={set('unidad_destino_id')}><OpcionesLista lista={unidades} vacio="Sin registrar" /></select></Campo>
    <Campo label="Puesto de destino" error={errors.puesto_destino} className="sm:col-span-2"><input maxLength={150} value={draft.puesto_destino} onChange={set('puesto_destino')} /></Campo>
    <Campo label="Fecha de inicio" required error={errors.fecha_inicio}><input type="date" required value={draft.fecha_inicio} onChange={set('fecha_inicio')} /></Campo>
    <Campo label="Fecha de fin" error={errors.fecha_fin}><input type="date" min={draft.fecha_inicio || undefined} value={draft.fecha_fin} onChange={set('fecha_fin')} /></Campo>
  </Formulario>
}
