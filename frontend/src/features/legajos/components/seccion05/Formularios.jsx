import { registrarSeccion05 } from '../../api/seccion05Api'
import { tiposGoce, tiposLicencia, tiposLicenciaSalud } from '../../config/seccion05'
import Campo, { Formulario, Opciones } from '../seccion01/Campo'
import { useBorrador, useGuardar } from '../seccion01/useGuardar'

const fecha = value => value ? value.split('-').reverse().join('/') : 'sin fecha'
const nota = 'El registro no se corrige después: si hay un error, se anula con motivo y se registra de nuevo. El responsable se guarda con tu sesión.'

// documentoId: documento recién subido que este registro acredita (el formulario se abre al subir el PDF).
const conDocumento = (data, documentoId) => documentoId ? { ...data, documento_id: documentoId } : data

// Solo las opciones que admite el tipo de documento (permitidos) y, sin acceso a datos de salud, sin descanso médico.
const filtrar = (lista, permitidos, veSalud = true) => Object.fromEntries(Object.entries(lista)
  .filter(([key]) => (!permitidos || permitidos.includes(key)) && (veSalud || !tiposLicenciaSalud.includes(key))))

function NotaDocumento({ documentoId }) {
  return documentoId ? <p className="rounded-lg bg-teal-50 p-3 text-xs text-teal-800 sm:col-span-2">El registro quedará enlazado al documento que acabas de subir.</p> : null
}

// Fechas y días del movimiento. Los días se toman del documento (calendario u hábiles): el backend solo valida que no superen el rango.
function CamposFechas({ draft, set, errors, minimoDias }) {
  return <>
    <Campo label="Fecha de inicio" required error={errors.fecha_inicio}><input type="date" required value={draft.fecha_inicio} onChange={set('fecha_inicio')} /></Campo>
    <Campo label="Fecha de fin" required error={errors.fecha_fin}><input type="date" required min={draft.fecha_inicio || undefined} value={draft.fecha_fin} onChange={set('fecha_fin')} /></Campo>
    <Campo label="Días (según el documento)" required error={errors.dias}><input type="number" required min={minimoDias} value={draft.dias} onChange={set('dias')} /></Campo>
    <Campo label="N.º de documento" error={errors.numero_documento}><input maxLength={60} placeholder="Papeleta, memorándum o resolución" value={draft.numero_documento} onChange={set('numero_documento')} /></Campo>
  </>
}

// Licencia, permiso, suspensión o descanso médico (licencias_permisos).
export function LicenciaForm({ usuario, permitidos, veSalud, documentoId, onSaved, onCancel }) {
  const opciones = filtrar(tiposLicencia, permitidos, veSalud)
  const [draft, set] = useBorrador({ tipo: Object.keys(opciones).length === 1 ? Object.keys(opciones)[0] : '', con_goce: true, fecha_inicio: '', fecha_fin: '', dias: '', numero_documento: '' })
  const { busy, error, errors, guardar } = useGuardar(data => registrarSeccion05(usuario, 'licencias-permisos', conDocumento(data, documentoId)), onSaved)
  return <Formulario titulo="Registrar licencia, permiso o suspensión" guardar="Guardar" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel} nota={nota}>
    <NotaDocumento documentoId={documentoId} />
    <Campo label="Tipo" required error={errors.tipo}><select required value={draft.tipo} onChange={set('tipo')}><Opciones lista={opciones} vacio="Selecciona" /></select></Campo>
    <label className="flex items-center gap-2 self-end pb-3 text-sm text-slate-700"><input type="checkbox" className="size-4 accent-teal-700" checked={draft.con_goce} onChange={set('con_goce')} />Con goce de remuneraciones</label>
    <CamposFechas draft={draft} set={set} errors={errors} minimoDias={0} />
    <p className="text-xs text-slate-500 sm:col-span-2">Un permiso por horas se registra con 0 días.</p>
  </Formulario>
}

// Período vacacional de un vínculo laboral (periodos_vacacionales). 30 días es el valor por defecto del esquema.
export function PeriodoVacacionalForm({ usuario, vinculos, onSaved, onCancel }) {
  const [draft, set] = useBorrador({ vinculo_laboral_id: vinculos.length === 1 ? vinculos[0].id : '', periodo_anio: String(new Date().getFullYear()), dias_derecho: '30' })
  const { busy, error, errors, guardar } = useGuardar(data => registrarSeccion05(usuario, 'periodos-vacacionales', data), onSaved)
  return <Formulario titulo="Registrar período vacacional" guardar="Guardar período" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel} nota={nota}>
    <Campo label="Vínculo laboral" required error={errors.vinculo_laboral_id} className="sm:col-span-2"><select required value={draft.vinculo_laboral_id} onChange={set('vinculo_laboral_id')}>
      <option value="">Selecciona</option>
      {vinculos.map(vinculo => <option key={vinculo.id} value={vinculo.id}>{vinculo.nombre_puesto} · {vinculo.numero_documento_vinculo || 'sin N.º de documento'} · desde {fecha(vinculo.fecha_inicio)}{vinculo.fecha_cese ? ` (cesado ${fecha(vinculo.fecha_cese)})` : ''}</option>)}
    </select></Campo>
    <Campo label="Año del período" required error={errors.periodo_anio}><input type="number" required min={1950} value={draft.periodo_anio} onChange={set('periodo_anio')} /></Campo>
    <Campo label="Días de derecho" required error={errors.dias_derecho}><input type="number" required min={1} max={366} value={draft.dias_derecho} onChange={set('dias_derecho')} /></Campo>
  </Formulario>
}

// Goce, adelanto o postergación de vacaciones de un período (goces_vacacionales).
export function GoceForm({ usuario, periodos, permitidos, documentoId, onSaved, onCancel }) {
  const opciones = filtrar(tiposGoce, permitidos)
  const [draft, set] = useBorrador({ periodo_vacacional_id: periodos.length === 1 ? periodos[0].id : '', tipo: Object.keys(opciones).length === 1 ? Object.keys(opciones)[0] : '', fecha_inicio: '', fecha_fin: '', dias: '', numero_documento: '' })
  const { busy, error, errors, guardar } = useGuardar(data => registrarSeccion05(usuario, 'goces-vacacionales', conDocumento(data, documentoId)), onSaved)
  return <Formulario titulo="Registrar goce, adelanto o postergación" guardar="Guardar" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel} nota={nota}>
    <NotaDocumento documentoId={documentoId} />
    <Campo label="Período vacacional" required error={errors.periodo_vacacional_id}><select required value={draft.periodo_vacacional_id} onChange={set('periodo_vacacional_id')}>
      <option value="">Selecciona</option>
      {periodos.map(periodo => <option key={periodo.id} value={periodo.id}>Período {periodo.values.periodo_anio} · {periodo.values.dias_derecho} días</option>)}
    </select></Campo>
    <Campo label="Tipo" required error={errors.tipo}><select required value={draft.tipo} onChange={set('tipo')}><Opciones lista={opciones} vacio="Selecciona" /></select></Campo>
    <CamposFechas draft={draft} set={set} errors={errors} minimoDias={1} />
  </Formulario>
}
