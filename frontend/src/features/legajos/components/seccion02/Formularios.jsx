import { leyesDelTipo } from '../../../users/utils/leyes'
import { useState } from 'react'
import { cambiarModalidad, cambiarTipoVinculo, sinProceso } from '../../config/ingresoLaboral'
import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { saveDatosPuesto, saveSeccion02 } from '../../api/seccion02Api'
import { etiquetaVinculo, regimenesLaborales, resultadosPeriodo, tiposInduccion, tiposPersonal } from '../../config/seccion02'
import { CamposDatosPuesto, CamposProcesoSeleccion, CamposVinculo, OpcionesCatalogo } from './CamposVinculo'
import { datosPuestoInicial, limpiarDatosPuesto, nuevoVinculoInicial, vinculoInicial, vinculoParaGuardar } from './borradoresVinculo'
import Campo, { Formulario, Opciones } from '../seccion01/Campo'
import { hoy, useBorrador, useGuardar } from '../seccion01/useGuardar'

// documentoId: documento recién subido que este registro acredita (el formulario se abre al subir el PDF).
const conDocumento = (data, documentoId) => documentoId ? { ...data, documento_id: documentoId } : data

function NotaDocumento({ documentoId }) {
  return documentoId ? <p className="rounded-lg bg-teal-50 p-3 text-xs text-teal-800 sm:col-span-2">El registro quedará enlazado al documento que acabas de subir.</p> : null
}

// Vínculo laboral de esta persona al que pertenece el registro (el backend valida que sea suyo).
function CampoVinculo({ vinculos, draft, set, errors }) {
  return <Campo label="Vínculo laboral" required error={errors.vinculo_laboral_id} className="sm:col-span-2"><select required value={draft.vinculo_laboral_id} onChange={set('vinculo_laboral_id')}>
    <option value="">Selecciona</option>
    {vinculos.map(vinculo => <option key={vinculo.id} value={vinculo.id}>{etiquetaVinculo(vinculo)}</option>)}
  </select></Campo>
}


// Vínculo laboral (vinculos_laborales), con los datos del proceso de selección. Se corrige solo mientras no tenga cese.
// El tipo de vínculo es obligatorio; uno anterior Sin definir trae una sugerencia (tipo_sugerido) que solo se aplica con
// el botón y se guarda al guardar el vínculo. Uno nuevo precarga la ley y el tipo de personal de la persona.
export function VinculoForm(props) {
  const opciones = useLiveQuery('/expediente-opciones')
  const persona = useLiveQuery(`/expedientes/${props.usuario}`)
  const cargado = opciones.data?.data && (props.record || persona.data?.data)
  if (!cargado && (opciones.error || (!props.record && persona.error))) return <div role="alert" className="space-y-3 text-sm text-rose-700">
    <p>No se pudieron cargar los datos de la persona o el catálogo de regímenes.</p>
    <button type="button" onClick={() => { opciones.retry(); persona.retry() }} className="font-semibold underline">Reintentar</button>
    <button type="button" onClick={props.onCancel} className="ml-4 text-slate-600">Cancelar</button>
  </div>
  if (!cargado) return <p role="status" className="text-sm text-slate-500">Cargando datos del vínculo…</p>
  return <VinculoEditor {...props} opciones={opciones.data.data} persona={persona.data?.data} />
}

function VinculoEditor({ usuario, record, documentoId, onSaved, onCancel, opciones, persona, casAdicional = false }) {
  const v = record?.values || {}
  const [historico, setHistorico] = useState(false)
  const sugerencia = record?.tipo_sugerido
  // Con datos del puesto registrados el tipo de personal no cambia (el backend lo rechaza; el perfil no se borra).
  const tipoFijo = (record?.datos_puesto?.length || 0) > 0
  const [draft, setBase, ponerBase] = useBorrador(record ? {
    ...vinculoInicial(v),
    tipo_personal: v.tipo_personal || '',
    regimen_normativo_id: v.regimen_normativo_id || '',
    fecha_cese: v.fecha_cese || '',
  } : nuevoVinculoInicial(persona, opciones.regimenes, { casAdicional }))
  const codigoLey = opciones?.regimenes?.find(ley => ley.id === draft.regimen_normativo_id)?.codigo || ''
  const aplicar = siguiente => Object.entries(siguiente).forEach(([key, valor]) => { if (draft[key] !== valor) ponerBase(key)(valor) })
  const poner = key => valor => {
    if (key === 'modalidad_ingreso') aplicar(cambiarModalidad(draft, valor))
    else if (key === 'regimen_laboral') aplicar(cambiarTipoVinculo(draft, valor))
    else if (key === 'regimen_normativo_id' && valor !== draft[key]) aplicar({ ...sinProceso(draft), [key]: valor })
    else ponerBase(key)(valor)
  }
  const set = key => event => ['modalidad_ingreso', 'regimen_laboral', 'regimen_normativo_id'].includes(key) ? poner(key)(event.target.value) : setBase(key)(event)
  const { busy, error, errors, guardar } = useGuardar(data => saveSeccion02(usuario, 'vinculos-laborales', record?.id,
    conDocumento(vinculoParaGuardar(data, { historico, correccion: !!record }), documentoId)), onSaved)
  // El tipo de personal precargado se puede cambiar; la ley se conserva solo si también corresponde al nuevo.
  const cambiarTipoPersonal = event => {
    const tipo = event.target.value
    aplicar({ ...sinProceso(draft), tipo_personal: tipo, modalidad_ingreso: '', sustento_ingreso: '', regimen_laboral: '' })
    if (tipo === 'docente' && draft.regimen_laboral === 'designado') poner('regimen_laboral')('')
    if (!(opciones?.regimenes || []).some(ley => ley.id === draft.regimen_normativo_id && ley.aplica_a === tipo)) poner('regimen_normativo_id')('')
  }
  return <Formulario titulo={record ? 'Corregir vínculo laboral' : casAdicional ? 'Registrar vínculo CAS adicional' : 'Registrar vínculo laboral'} guardar={historico ? 'Guardar vínculo histórico' : 'Guardar vínculo'} busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel}
    nota={record || historico ? 'Con fecha de cese el vínculo queda cerrado y ya no se corrige ni recibe adendas.' : 'Para un contrato con duración definida usa «Fecha de fin prevista». El cese se registra cuando la relación laboral ya terminó.'}>
    <NotaDocumento documentoId={documentoId} />
    {casAdicional && <div role="note" className="space-y-2 rounded-xl border border-teal-200 bg-teal-50 p-4 text-xs leading-relaxed text-teal-950 sm:col-span-2">
      <p className="font-semibold">Un nuevo vínculo para la misma persona y el mismo DNI</p>
      <p>Se precarga Administrativo y el régimen CAS para este puesto. El vínculo docente conserva su régimen, resolución y datos del puesto. Confirma la duración, la modalidad de ingreso y los documentos oficiales del nuevo contrato.</p>
      <p>Antes de formalizar, Recursos Humanos debe verificar la docencia efectiva, los horarios y las demás incompatibilidades. La vigencia por fechas que muestra el sistema no constituye autorización de doble percepción.</p>
      {!['1057', 'CAS'].includes(codigoLey) && <p className="font-semibold">El catálogo no tiene seleccionado un régimen CAS activo. Revisa la configuración o selecciona el régimen que realmente corresponde al puesto.</p>}
    </div>}
    {!record && <div className="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm sm:col-span-2">
      <label className="flex items-start gap-3 font-medium text-slate-700"><input type="checkbox" checked={historico} onChange={event => setHistorico(event.target.checked)} className="mt-1" />Registrar un vínculo histórico que ya terminó</label>
      <p className="text-xs leading-relaxed text-slate-600">{historico ? 'Se guardará como cesado. Indica la fecha real de cese; permanecerá en el historial.' : 'Alta normal: se guardará sin fecha de cese. Será vigente si ya inició y su fecha de fin prevista no venció.'}</p>
    </div>}
    <Campo label="Tipo de personal de este vínculo" required error={errors.tipo_personal}><select required value={draft.tipo_personal} onChange={cambiarTipoPersonal} disabled={tipoFijo}><Opciones lista={tiposPersonal} actual={draft.tipo_personal} vacio="Selecciona" /></select></Campo>
    {!record && !casAdicional && <p className="self-end text-xs leading-relaxed text-slate-500">Elige según el puesto de este vínculo. Una persona docente puede registrar un puesto administrativo CAS por separado, con evaluación de compatibilidad por Recursos Humanos.</p>}
    {tipoFijo && <p className="self-end text-[11px] text-slate-500">El tipo de personal no se cambia: el vínculo ya tiene datos del puesto. Si es otro, anula el vínculo y registra el correcto.</p>}
    <CamposVinculo draft={draft} set={set} poner={poner} errors={errors} opciones={opciones} tipoPersonal={draft.tipo_personal} codigoLey={codigoLey} condicionDocente={record?.datos_puesto?.[0]?.values.condicion_docente} requiereModalidad={!record || !!v.modalidad_ingreso} conCese={!!record || historico} ceseObligatorio={!record && historico}>
      <Campo label="Ley / régimen normativo" required error={errors.regimen_normativo_id}><select required value={draft.regimen_normativo_id} onChange={set('regimen_normativo_id')} disabled={!opciones}><OpcionesCatalogo lista={(opciones?.regimenes || []).filter(ley => leyesDelTipo(opciones?.regimenes, draft.tipo_personal).includes(ley) || ley.id === draft.regimen_normativo_id)} actual={draft.regimen_normativo_id} /></select></Campo>
      {sugerencia && <div role="note" className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900 sm:col-span-2">
        <span>Tipo de vínculo sin definir. {sugerencia.valor ? <>Sugerencia: <strong>{regimenesLaborales[sugerencia.valor]}</strong> ({sugerencia.motivo}) Revísala antes de guardar.</> : sugerencia.motivo}</span>
        {sugerencia.valor && draft.regimen_laboral !== sugerencia.valor && <button type="button" onClick={() => poner('regimen_laboral')(sugerencia.valor)} className="font-semibold text-amber-900 underline">Aplicar sugerencia</button>}
      </div>}
    </CamposVinculo>
  </Formulario>
}

// 2.1: datos del proceso de selección, que se guardan en el vínculo laboral.
export function SeleccionForm({ usuario, record, onSaved, onCancel }) {
  const [draft, set, poner] = useBorrador({ proceso_seleccion_id: record.values.proceso_seleccion_id || '', proceso_seleccion: record.values.proceso_seleccion || '', anio_proceso: record.values.anio_proceso ?? '', fecha_resultado_seleccion: record.values.fecha_resultado_seleccion || '' })
  const { busy, error, errors, guardar } = useGuardar(data => saveSeccion02(usuario, 'vinculos-laborales', record.id, data), onSaved)
  return <Formulario titulo={`Proceso de selección: ${record.values.nombre_puesto}`} guardar="Guardar" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel}
    nota="Se guarda en el vínculo laboral. Solo se corrige mientras el vínculo no tenga fecha de cese.">
    <CamposProcesoSeleccion draft={draft} set={set} poner={poner} errors={errors} regimenId={record.values.regimen_normativo_id} tipoPersonal={record.values.tipo_personal} codigoLey={record.codigo_ley} required={record.values.modalidad_ingreso === 'concurso_publico'} />
  </Formulario>
}

// Datos del puesto del vínculo (perfil_docente o perfil_administrativo según su tipo de personal). Se corrigen
// en el mismo registro mientras el vínculo no tenga fecha de cese ni esté anulado.
export function DatosPuestoForm({ usuario, vinculo, onSaved, onCancel }) {
  const docente = vinculo.values.tipo_personal === 'docente'
  const v = vinculo.datos_puesto[0]?.values || {}
  const [draft, set] = useBorrador(datosPuestoInicial(docente, v))
  const { busy, error, errors, guardar } = useGuardar(data => saveDatosPuesto(usuario, vinculo.id, limpiarDatosPuesto(docente, data)), onSaved)
  return <Formulario titulo={`Datos del puesto: ${vinculo.values.nombre_puesto}`} guardar="Guardar datos del puesto" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel}
    nota="Se corrigen mientras el vínculo no tenga fecha de cese. El responsable se guarda con tu sesión.">
    <CamposDatosPuesto docente={docente} draft={draft} set={set} errors={errors} confianzaFija={vinculo.values.modalidad_ingreso ? vinculo.values.modalidad_ingreso === 'designacion_confianza' : undefined} />
  </Formulario>
}

// Adenda (vinculo_adendas). No se corrige: si hay un error, se anula con motivo y se registra de nuevo.
export function AdendaForm({ usuario, vinculos, documentoId, onSaved, onCancel }) {
  const [draft, set] = useBorrador({ vinculo_laboral_id: vinculos.length === 1 ? vinculos[0].id : '', numero: '', motivo: '', fecha_inicio: '', fecha_fin: '' })
  const { busy, error, errors, guardar } = useGuardar(data => saveSeccion02(usuario, 'vinculo-adendas', null, conDocumento(data, documentoId)), onSaved)
  return <Formulario titulo="Registrar adenda" guardar="Guardar adenda" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel}
    nota="No se corrige después: si hay un error, se anula con motivo y se registra de nuevo. El responsable se guarda con tu sesión.">
    <NotaDocumento documentoId={documentoId} />
    <CampoVinculo vinculos={vinculos} draft={draft} set={set} errors={errors} />
    {vinculos.find(v => v.id === draft.vinculo_laboral_id)?.values.regimen_laboral === 'plazo_indeterminado' && <p className="col-span-full rounded-lg bg-teal-50 p-3 text-xs text-teal-900">Las fechas corresponden a la vigencia de las condiciones de la adenda. El vínculo de plazo indeterminado conserva su duración sin fecha de fin.</p>}
    <Campo label="N.º de adenda" error={errors.numero}><input maxLength={40} value={draft.numero} onChange={set('numero')} /></Campo>
    <Campo label="Motivo" error={errors.motivo}><input maxLength={200} value={draft.motivo} onChange={set('motivo')} /></Campo>
    <Campo label="Fecha de inicio" required error={errors.fecha_inicio}><input type="date" required value={draft.fecha_inicio} onChange={set('fecha_inicio')} /></Campo>
    <Campo label="Fecha de fin" required error={errors.fecha_fin}><input type="date" required min={draft.fecha_inicio || undefined} value={draft.fecha_fin} onChange={set('fecha_fin')} /></Campo>
  </Formulario>
}

// Inducción (inducciones): general o específica. No se edita; se anula con motivo.
export function InduccionForm({ usuario, vinculos, documentoId, onSaved, onCancel }) {
  const [draft, set] = useBorrador({ vinculo_laboral_id: vinculos.length === 1 ? vinculos[0].id : '', tipo: '', fecha: '', responsable: '' })
  const { busy, error, errors, guardar } = useGuardar(data => saveSeccion02(usuario, 'inducciones', null, conDocumento(data, documentoId)), onSaved)
  return <Formulario titulo="Registrar inducción" guardar="Guardar inducción" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel}
    nota="No se puede editar después: si hay un error, se anula y se registra de nuevo. El responsable del registro se guarda con tu sesión.">
    <NotaDocumento documentoId={documentoId} />
    <CampoVinculo vinculos={vinculos} draft={draft} set={set} errors={errors} />
    <Campo label="Tipo de inducción" required error={errors.tipo}><select required value={draft.tipo} onChange={set('tipo')}><Opciones lista={tiposInduccion} vacio="Selecciona" /></select></Campo>
    <Campo label="Fecha" required error={errors.fecha}><input type="date" required max={hoy()} value={draft.fecha} onChange={set('fecha')} /></Campo>
    <Campo label="Responsable de la inducción" error={errors.responsable} className="sm:col-span-2"><input maxLength={150} value={draft.responsable} onChange={set('responsable')} /></Campo>
  </Formulario>
}

// Período de prueba (periodos_prueba). Mientras el resultado sea pendiente solo se registra el resultado;
// corregir fechas o un resultado ya registrado = anular y registrar de nuevo.
export function PeriodoForm({ usuario, vinculos, record, documentoId, onSaved, onCancel }) {
  if (record) return <ResultadoForm usuario={usuario} vinculos={vinculos} record={record} onSaved={onSaved} onCancel={onCancel} />
  return <NuevoPeriodoForm usuario={usuario} vinculos={vinculos} documentoId={documentoId} onSaved={onSaved} onCancel={onCancel} />
}

function NuevoPeriodoForm({ usuario, vinculos, documentoId, onSaved, onCancel }) {
  const [draft, set] = useBorrador({ vinculo_laboral_id: vinculos.length === 1 ? vinculos[0].id : '', fecha_inicio: '', fecha_fin: '', resultado: 'pendiente', fecha_resultado: '' })
  const pendiente = draft.resultado === 'pendiente'
  const { busy, error, errors, guardar } = useGuardar(data => saveSeccion02(usuario, 'periodos-prueba', null, conDocumento({ ...data, fecha_resultado: pendiente ? null : data.fecha_resultado }, documentoId)), onSaved)
  return <Formulario titulo="Registrar período de prueba" guardar="Guardar período" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel}
    nota="Las fechas no se corrigen después: si hay un error, se anula y se registra de nuevo. Con resultado aprobado o no aprobado el registro queda cerrado.">
    <NotaDocumento documentoId={documentoId} />
    <CampoVinculo vinculos={vinculos} draft={draft} set={set} errors={errors} />
    <Campo label="Fecha de inicio" required error={errors.fecha_inicio}><input type="date" required value={draft.fecha_inicio} onChange={set('fecha_inicio')} /></Campo>
    <Campo label="Fecha de fin" required error={errors.fecha_fin}><input type="date" required min={draft.fecha_inicio || undefined} value={draft.fecha_fin} onChange={set('fecha_fin')} /></Campo>
    <Campo label="Resultado" required error={errors.resultado}><select required value={draft.resultado} onChange={set('resultado')}><Opciones lista={resultadosPeriodo} /></select></Campo>
    <Campo label="Fecha del resultado" required={!pendiente} error={errors.fecha_resultado}><input type="date" required={!pendiente} disabled={pendiente} min={draft.fecha_inicio || undefined} max={hoy()} value={pendiente ? '' : draft.fecha_resultado} onChange={set('fecha_resultado')} /></Campo>
  </Formulario>
}

function ResultadoForm({ usuario, vinculos, record, onSaved, onCancel }) {
  const v = record.values
  const [draft, set] = useBorrador({ resultado: '', fecha_resultado: '' })
  const { aprobado, no_aprobado: noAprobado } = resultadosPeriodo
  const { busy, error, errors, guardar } = useGuardar(data => saveSeccion02(usuario, 'periodos-prueba', record.id, data), onSaved)
  const vinculo = vinculos.find(item => item.id === v.vinculo_laboral_id)
  return <Formulario titulo="Registrar resultado del período de prueba" guardar="Guardar resultado" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel}
    nota="Con el resultado registrado el período queda cerrado: ya no se corrige, solo se anula.">
    <p className="text-sm text-slate-600 sm:col-span-2">{vinculo ? etiquetaVinculo(vinculo) : 'Vínculo laboral'} · del {v.fecha_inicio.split('-').reverse().join('/')} al {v.fecha_fin.split('-').reverse().join('/')}</p>
    <Campo label="Resultado" required error={errors.resultado}><select required value={draft.resultado} onChange={set('resultado')}><Opciones lista={{ aprobado, no_aprobado: noAprobado }} vacio="Selecciona" /></select></Campo>
    <Campo label="Fecha del resultado" required error={errors.fecha_resultado}><input type="date" required min={v.fecha_inicio} max={hoy()} value={draft.fecha_resultado} onChange={set('fecha_resultado')} /></Campo>
  </Formulario>
}
