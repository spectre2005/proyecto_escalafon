import { registrarSeccion05 } from '../../api/seccion05Api'
import { referenciaDocumentos } from '../../config/seccion02'
import { formasPermiso, movimientosLicencia, movimientosVacacion, opcionesLicencia, opcionesMovimiento, opcionesVacacion, tiposGoce, tiposLicencia } from '../../config/seccion05'
import Campo, { Formulario, Opciones } from '../seccion01/Campo'
import { useBorrador, useGuardar } from '../seccion01/useGuardar'

const fecha = value => value ? value.split('-').reverse().join('/') : 'sin fecha'
const nota = 'El registro no se corrige después: si hay un error, se anula con motivo y se registra de nuevo. El responsable se guarda con tu sesión.'

// documentoId: documento recién subido que este registro acredita (el formulario se abre al subir el PDF).
const conDocumento = (data, documentoId) => documentoId ? { ...data, documento_id: documentoId } : data

function NotaDocumento({ documentoId }) {
  return documentoId ? <p className="rounded-lg bg-teal-50 p-3 text-xs text-teal-800 sm:col-span-2">El registro quedará enlazado al documento que acabas de subir.</p> : null
}

// Fechas y días del movimiento. Los días se toman del documento (calendario u hábiles): el backend solo valida que no superen el rango.
function CamposFechas({ draft, set, errors, minimoDias, ayudaDocumento }) {
  return <>
    <Campo label="Fecha de inicio" required error={errors.fecha_inicio}><input type="date" required value={draft.fecha_inicio} onChange={set('fecha_inicio')} /></Campo>
    <Campo label="Fecha de fin" required error={errors.fecha_fin}><input type="date" required min={draft.fecha_inicio || undefined} value={draft.fecha_fin} onChange={set('fecha_fin')} /></Campo>
    <Campo label="Días (según el documento)" required error={errors.dias}><input type="number" required min={minimoDias} value={draft.dias} onChange={set('dias')} /></Campo>
    <Campo label="N.º de documento" error={errors.numero_documento}><input maxLength={60} placeholder={ayudaDocumento} value={draft.numero_documento} onChange={set('numero_documento')} /></Campo>
  </>
}

// Selector de una sola opción con botones (opciones: { clave: etiqueta }). bloqueado: hay una sola opción posible.
function Botones({ label, opciones, valor, onChange, error }) {
  const claves = Object.keys(opciones)
  return <fieldset className="col-span-full">
    <legend className="mb-2 text-xs font-medium text-slate-600">{label} *</legend>
    <div className="flex flex-wrap gap-2">{claves.map(clave => <button key={clave} type="button" aria-pressed={valor === clave} disabled={claves.length === 1}
      onClick={() => onChange(clave)} className={`rounded-xl border px-3 py-2 text-sm ${valor === clave ? 'border-teal-300 bg-teal-50 font-semibold text-teal-800' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}>{opciones[clave]}</button>)}</div>
    {error && <span className="mt-2 block text-xs text-rose-700">{[].concat(error).join(' ')}</span>}
  </fieldset>
}

const elegir = (set, key) => value => set(key)({ target: { value } })

// Licencia, permiso, suspensión o descanso médico (licencias_permisos). El "tipo de movimiento" es solo de interfaz:
// al backend se envía licencias_permisos.tipo (el movimiento, o la licencia del literal e).
export function LicenciaForm({ usuario, permitidos, veSalud, documentoId, onSaved, onCancel }) {
  const movimientos = opcionesMovimiento(permitidos, veSalud)
  const licencias = opcionesLicencia(permitidos)
  const [draft, set] = useBorrador({ movimiento: movimientos.length === 1 ? movimientos[0] : '', licencia: licencias.length === 1 ? licencias[0] : '', forma_permiso: '', con_goce: true, fecha_inicio: '', fecha_fin: '', dias: '', numero_documento: '' })
  const config = movimientosLicencia[draft.movimiento]
  // Suspensión: "con goce" no se muestra ni se envía hasta que la universidad valide cómo se maneja.
  // La forma del permiso (papeleta, memorándum u otro) solo se envía en los permisos.
  const paraBackend = ({ movimiento, licencia, forma_permiso: forma, con_goce: conGoce, ...resto }) => ({ ...resto, tipo: movimiento === 'licencia' ? licencia : movimiento,
    ...(movimiento === 'permiso' ? { forma_permiso: forma } : {}), ...(movimientosLicencia[movimiento].conGoce ? { con_goce: conGoce } : {}) })
  const { busy, error, errors, guardar } = useGuardar(data => registrarSeccion05(usuario, 'licencias-permisos', conDocumento(paraBackend(data), documentoId)), onSaved)
  return <Formulario titulo={config?.titulo || 'Registrar licencia, permiso o suspensión'} guardar="Guardar" busy={busy} error={error} onSubmit={() => config && guardar(draft)} onCancel={onCancel} nota={nota}>
    <NotaDocumento documentoId={documentoId} />
    <Botones label="Tipo de movimiento" opciones={Object.fromEntries(movimientos.map(m => [m, movimientosLicencia[m].etiqueta]))} valor={draft.movimiento} onChange={elegir(set, 'movimiento')} error={draft.movimiento === 'licencia' ? null : errors.tipo} />
    {!config && <p className="text-xs text-slate-500 sm:col-span-2">Selecciona el tipo de movimiento para ver sus campos.</p>}
    {draft.movimiento === 'permiso' && <Botones label="Tipo de permiso" opciones={Object.fromEntries(Object.entries(formasPermiso).map(([clave, forma]) => [clave, forma.etiqueta]))} valor={draft.forma_permiso} onChange={elegir(set, 'forma_permiso')} error={errors.forma_permiso} />}
    {draft.movimiento === 'licencia' && <Campo label="Tipo de licencia (literal e)" required error={errors.tipo}><select required value={draft.licencia} onChange={set('licencia')}><Opciones lista={Object.fromEntries(licencias.map(tipo => [tipo, tiposLicencia[tipo]]))} vacio="Selecciona" /></select></Campo>}
    {draft.movimiento === 'descanso_medico' && <p className="rounded-lg bg-amber-50 p-3 text-xs text-amber-900 sm:col-span-2">Dato de salud: solo es visible para quienes tienen acceso a datos sensibles (Directiva, num. 6.2).</p>}
    {config?.conGoce && <label className="flex items-center gap-2 self-end pb-3 text-sm text-slate-700"><input type="checkbox" className="size-4 accent-teal-700" checked={draft.con_goce} onChange={set('con_goce')} />Con goce de remuneraciones</label>}
    {config && <CamposFechas draft={draft} set={set} errors={errors} minimoDias={0} ayudaDocumento={formasPermiso[draft.forma_permiso]?.ayudaDocumento || config.ayudaDocumento} />}
    {draft.movimiento === 'permiso' && <p className="text-xs text-slate-500 sm:col-span-2">Un permiso por horas se registra con 0 días.</p>}
  </Formulario>
}

// Período vacacional de un vínculo laboral (periodos_vacacionales). 30 días es el valor por defecto del esquema.
export function PeriodoVacacionalForm({ usuario, vinculos, onSaved, onCancel }) {
  const [draft, set] = useBorrador({ vinculo_laboral_id: vinculos.length === 1 ? vinculos[0].id : '', periodo_anio: String(new Date().getFullYear()), dias_derecho: '30' })
  const { busy, error, errors, guardar } = useGuardar(data => registrarSeccion05(usuario, 'periodos-vacacionales', data), onSaved)
  return <Formulario titulo="Registrar período vacacional" guardar="Guardar período" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel} nota={nota}>
    <Campo label="Vínculo laboral" required error={errors.vinculo_laboral_id} className="sm:col-span-2"><select required value={draft.vinculo_laboral_id} onChange={set('vinculo_laboral_id')}>
      <option value="">Selecciona</option>
      {vinculos.map(vinculo => <option key={vinculo.id} value={vinculo.id}>{vinculo.nombre_puesto} · {referenciaDocumentos(vinculo)} · desde {fecha(vinculo.fecha_inicio)}{vinculo.fecha_cese ? ` (cesado ${fecha(vinculo.fecha_cese)})` : ''}</option>)}
    </select></Campo>
    <Campo label="Año del período" required error={errors.periodo_anio}><input type="number" required min={1950} value={draft.periodo_anio} onChange={set('periodo_anio')} /></Campo>
    <Campo label="Días de derecho" required error={errors.dias_derecho}><input type="number" required min={1} max={366} value={draft.dias_derecho} onChange={set('dias_derecho')} /></Campo>
  </Formulario>
}

// Movimiento vacacional: b) goce, c) adelanto o postergación (goces_vacacionales) o d) rol de vacaciones, que por ahora es
// solo documento (onRol abre su carga en la lista de documentos). Con un documento recién subido solo se ofrece lo que acredita.
export function GoceForm({ usuario, periodos, permitidos, documentoId, onRol, onSaved, onCancel }) {
  const opciones = opcionesVacacion(permitidos).filter(opcion => opcion !== 'rol' || onRol)
  const [draft, set] = useBorrador({ movimiento: opciones.length === 1 ? opciones[0] : '', tipo: '', periodo_vacacional_id: periodos.length === 1 ? periodos[0].id : '', fecha_inicio: '', fecha_fin: '', dias: '', numero_documento: '' })
  const paraBackend = ({ movimiento, tipo, ...resto }) => ({ ...resto, tipo: movimiento === 'goce' ? 'goce' : tipo })
  const { busy, error, errors, guardar } = useGuardar(data => registrarSeccion05(usuario, 'goces-vacacionales', conDocumento(paraBackend(data), documentoId)), onSaved)
  const config = movimientosVacacion[draft.movimiento]
  const rol = draft.movimiento === 'rol'
  const sinPeriodo = config && !rol && !periodos.length
  const datos = config && !rol && !sinPeriodo
  const selector = <Botones label="¿Qué vas a registrar?" opciones={Object.fromEntries(opciones.map(o => [o, `${movimientosVacacion[o].literal}) ${movimientosVacacion[o].etiqueta}`]))} valor={draft.movimiento} onChange={elegir(set, 'movimiento')} error={draft.movimiento === 'goce' ? errors.tipo : null} />

  // Rol de vacaciones y "sin período": no hay datos que guardar; el botón principal lleva a la carga del rol o cierra.
  if (rol || sinPeriodo) {
    return <Formulario titulo={config.titulo} guardar={rol ? 'Subir rol de vacaciones' : 'Cerrar'} onSubmit={rol ? onRol : onCancel} onCancel={onCancel}
      nota={rol ? 'El rol de vacaciones se registra solo como documento (literal d). Está pendiente de confirmar con la universidad si además requiere datos estructurados.' : 'Primero registra el período vacacional en «Períodos vacacionales».'}>
      {selector}
      {sinPeriodo && <p className="rounded-lg bg-amber-50 p-3 text-xs text-amber-900 sm:col-span-2">La persona no tiene un período vacacional registrado: el goce, el adelanto y la postergación pertenecen a un período.</p>}
    </Formulario>
  }
  return <Formulario titulo={config?.titulo || 'Registrar movimiento vacacional'} guardar="Guardar" busy={busy} error={error} onSubmit={() => datos && guardar(draft)} onCancel={onCancel} nota={nota}>
    <NotaDocumento documentoId={documentoId} />
    {selector}
    {!config && <p className="text-xs text-slate-500 sm:col-span-2">Selecciona qué vas a registrar para ver sus campos.</p>}
    {draft.movimiento === 'adelanto_postergacion' && <>
      <Botones label="Adelanto o postergación" opciones={{ adelanto: tiposGoce.adelanto, postergacion: tiposGoce.postergacion }} valor={draft.tipo} onChange={elegir(set, 'tipo')} error={errors.tipo} />
      <p className="text-xs text-slate-500 sm:col-span-2">Registra las fechas que figuran en el documento que lo sustenta.</p>
    </>}
    {datos && <>
      <Campo label="Período vacacional" required error={errors.periodo_vacacional_id} className="sm:col-span-2"><select required value={draft.periodo_vacacional_id} onChange={set('periodo_vacacional_id')}>
        <option value="">Selecciona</option>
        {periodos.map(periodo => <option key={periodo.id} value={periodo.id}>Período {periodo.values.periodo_anio} · {periodo.values.dias_derecho} días</option>)}
      </select></Campo>
      <CamposFechas draft={draft} set={set} errors={errors} minimoDias={1} ayudaDocumento="Resolución o memorándum" />
    </>}
  </Formulario>
}
