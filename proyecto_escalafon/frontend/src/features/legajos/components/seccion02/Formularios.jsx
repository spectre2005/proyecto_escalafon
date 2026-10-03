import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { saveSeccion02 } from '../../api/seccion02Api'
import { etiquetaVinculo, regimenesLaborales, resultadosPeriodo, tiposInduccion, tiposPersonal } from '../../config/seccion02'
import Campo, { Formulario, Opciones } from '../seccion01/Campo'
import { hoy, useBorrador, useGuardar } from '../seccion01/useGuardar'


// Vínculo laboral de esta persona al que pertenece el registro (el backend valida que sea suyo).
function CampoVinculo({ vinculos, draft, set, errors }) {
  return <Campo label="Vínculo laboral" required error={errors.vinculo_laboral_id} className="sm:col-span-2"><select required value={draft.vinculo_laboral_id} onChange={set('vinculo_laboral_id')}>
    <option value="">Selecciona</option>
    {vinculos.map(vinculo => <option key={vinculo.id} value={vinculo.id}>{etiquetaVinculo(vinculo)}</option>)}
  </select></Campo>
}

// Opciones con estado activo: los inactivos solo se conservan si ya están guardados.
function OpcionesCatalogo({ lista, actual }) {
  return <>
    <option value="">Selecciona</option>
    {(lista || []).filter(item => item.activo !== false || item.id === actual).map(item => <option key={item.id} value={item.id}>{item.nombre}</option>)}
  </>
}

// Vínculo laboral (vinculos_laborales), con los datos del proceso de selección. Se corrige solo mientras no tenga cese.
export function VinculoForm({ usuario, record, onSaved, onCancel }) {
  const v = record?.values || {}
  const opciones = useLiveQuery('/expediente-opciones').data?.data
  const [draft, set] = useBorrador({
    tipo_personal: v.tipo_personal || '', regimen_laboral: v.regimen_laboral || '', regimen_normativo_id: v.regimen_normativo_id || '',
    unidad_organica_id: v.unidad_organica_id || '', nombre_puesto: v.nombre_puesto || '', numero_documento_vinculo: v.numero_documento_vinculo || '',
    fecha_inicio: v.fecha_inicio || '', fecha_fin_prevista: v.fecha_fin_prevista || '', fecha_cese: v.fecha_cese || '',
    proceso_seleccion: v.proceso_seleccion || '', fecha_resultado_seleccion: v.fecha_resultado_seleccion || '',
    obligado_djbr: v.obligado_djbr ?? false, exige_colegiatura: v.exige_colegiatura ?? false, tiene_periodo_prueba: v.tiene_periodo_prueba ?? false,
  })
  const { busy, error, errors, guardar } = useGuardar(data => saveSeccion02(usuario, 'vinculos-laborales', record?.id, data), onSaved)
  return <Formulario titulo={record ? 'Corregir vínculo laboral' : 'Registrar vínculo laboral'} guardar="Guardar vínculo" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel}
    nota="Datos de la resolución o contrato. Con fecha de cese el vínculo queda cerrado y ya no se corrige.">
    <Campo label="Tipo de personal" required error={errors.tipo_personal}><select required value={draft.tipo_personal} onChange={set('tipo_personal')}><Opciones lista={tiposPersonal} actual={draft.tipo_personal} vacio="Selecciona" /></select></Campo>
    <Campo label="Régimen laboral" required error={errors.regimen_laboral}><select required value={draft.regimen_laboral} onChange={set('regimen_laboral')}><Opciones lista={regimenesLaborales} actual={draft.regimen_laboral} vacio="Selecciona" /></select></Campo>
    <Campo label="Régimen normativo" required error={errors.regimen_normativo_id}><select required value={draft.regimen_normativo_id} onChange={set('regimen_normativo_id')} disabled={!opciones}><OpcionesCatalogo lista={opciones?.regimenes} actual={draft.regimen_normativo_id} /></select></Campo>
    <Campo label="Unidad orgánica" error={errors.unidad_organica_id}><select value={draft.unidad_organica_id} onChange={set('unidad_organica_id')} disabled={!opciones}><OpcionesCatalogo lista={opciones?.unidades} actual={draft.unidad_organica_id} /></select></Campo>
    <Campo label="Puesto" required error={errors.nombre_puesto}><input required maxLength={150} value={draft.nombre_puesto} onChange={set('nombre_puesto')} /></Campo>
    <Campo label="N.º de resolución o contrato" error={errors.numero_documento_vinculo}><input maxLength={60} value={draft.numero_documento_vinculo} onChange={set('numero_documento_vinculo')} /></Campo>
    <Campo label="Fecha de inicio" required error={errors.fecha_inicio}><input type="date" required value={draft.fecha_inicio} onChange={set('fecha_inicio')} /></Campo>
    <Campo label="Fecha de fin prevista" error={errors.fecha_fin_prevista}><input type="date" min={draft.fecha_inicio || undefined} value={draft.fecha_fin_prevista} onChange={set('fecha_fin_prevista')} /></Campo>
    <Campo label="Proceso de selección" error={errors.proceso_seleccion}><input maxLength={100} placeholder="Ej.: concurso o proceso CAS" value={draft.proceso_seleccion} onChange={set('proceso_seleccion')} /></Campo>
    <Campo label="Fecha del resultado de selección" error={errors.fecha_resultado_seleccion}><input type="date" max={hoy()} value={draft.fecha_resultado_seleccion} onChange={set('fecha_resultado_seleccion')} /></Campo>
    <Campo label="Fecha de cese (solo si el vínculo ya terminó)" error={errors.fecha_cese}><input type="date" min={draft.fecha_inicio || undefined} max={hoy()} value={draft.fecha_cese} onChange={set('fecha_cese')} /></Campo>
    <div className="col-span-full grid gap-2 text-sm text-slate-700">
      {[['obligado_djbr', 'Obligado a presentar declaración jurada de bienes y rentas'], ['exige_colegiatura', 'El puesto exige colegiatura'], ['tiene_periodo_prueba', 'Tiene período de prueba']].map(([key, text]) => <label key={key} className="flex items-center gap-2"><input type="checkbox" className="size-4 accent-teal-700" checked={draft[key]} onChange={set(key)} />{text}</label>)}
    </div>
  </Formulario>
}

// 2.1: datos del proceso de selección, que se guardan en el vínculo laboral.
export function SeleccionForm({ usuario, record, onSaved, onCancel }) {
  const [draft, set] = useBorrador({ proceso_seleccion: record.values.proceso_seleccion || '', fecha_resultado_seleccion: record.values.fecha_resultado_seleccion || '' })
  const { busy, error, errors, guardar } = useGuardar(data => saveSeccion02(usuario, 'vinculos-laborales', record.id, data), onSaved)
  return <Formulario titulo={`Proceso de selección: ${record.values.nombre_puesto}`} guardar="Guardar" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel}
    nota="Se guarda en el vínculo laboral. Solo se corrige mientras el vínculo no tenga fecha de cese.">
    <Campo label="Proceso de selección" error={errors.proceso_seleccion}><input maxLength={100} value={draft.proceso_seleccion} onChange={set('proceso_seleccion')} /></Campo>
    <Campo label="Fecha del resultado" error={errors.fecha_resultado_seleccion}><input type="date" max={hoy()} value={draft.fecha_resultado_seleccion} onChange={set('fecha_resultado_seleccion')} /></Campo>
  </Formulario>
}

// Adenda (vinculo_adendas). El esquema no tiene anulación para adendas: no se corrige ni se anula.
export function AdendaForm({ usuario, vinculos, onSaved, onCancel }) {
  const [draft, set] = useBorrador({ vinculo_laboral_id: vinculos.length === 1 ? vinculos[0].id : '', numero: '', motivo: '', fecha_inicio: '', fecha_fin: '' })
  const { busy, error, errors, guardar } = useGuardar(data => saveSeccion02(usuario, 'vinculo-adendas', null, data), onSaved)
  return <Formulario titulo="Registrar adenda" guardar="Guardar adenda" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel}
    nota="No se puede corregir ni anular después: revisa los datos antes de guardar. El responsable se guarda con tu sesión.">
    <CampoVinculo vinculos={vinculos} draft={draft} set={set} errors={errors} />
    <Campo label="N.º de adenda" error={errors.numero}><input maxLength={40} value={draft.numero} onChange={set('numero')} /></Campo>
    <Campo label="Motivo" error={errors.motivo}><input maxLength={200} value={draft.motivo} onChange={set('motivo')} /></Campo>
    <Campo label="Fecha de inicio" required error={errors.fecha_inicio}><input type="date" required value={draft.fecha_inicio} onChange={set('fecha_inicio')} /></Campo>
    <Campo label="Fecha de fin" required error={errors.fecha_fin}><input type="date" required min={draft.fecha_inicio || undefined} value={draft.fecha_fin} onChange={set('fecha_fin')} /></Campo>
  </Formulario>
}

// Inducción (inducciones): general o específica. No se edita; se anula con motivo.
export function InduccionForm({ usuario, vinculos, onSaved, onCancel }) {
  const [draft, set] = useBorrador({ vinculo_laboral_id: vinculos.length === 1 ? vinculos[0].id : '', tipo: '', fecha: '', responsable: '' })
  const { busy, error, errors, guardar } = useGuardar(data => saveSeccion02(usuario, 'inducciones', null, data), onSaved)
  return <Formulario titulo="Registrar inducción" guardar="Guardar inducción" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel}
    nota="No se puede editar después: si hay un error, se anula y se registra de nuevo. El responsable del registro se guarda con tu sesión.">
    <CampoVinculo vinculos={vinculos} draft={draft} set={set} errors={errors} />
    <Campo label="Tipo de inducción" required error={errors.tipo}><select required value={draft.tipo} onChange={set('tipo')}><Opciones lista={tiposInduccion} vacio="Selecciona" /></select></Campo>
    <Campo label="Fecha" required error={errors.fecha}><input type="date" required max={hoy()} value={draft.fecha} onChange={set('fecha')} /></Campo>
    <Campo label="Responsable de la inducción" error={errors.responsable} className="sm:col-span-2"><input maxLength={150} value={draft.responsable} onChange={set('responsable')} /></Campo>
  </Formulario>
}

// Período de prueba (periodos_prueba). Mientras el resultado sea pendiente solo se registra el resultado;
// corregir fechas o un resultado ya registrado = anular y registrar de nuevo.
export function PeriodoForm({ usuario, vinculos, record, onSaved, onCancel }) {
  if (record) return <ResultadoForm usuario={usuario} vinculos={vinculos} record={record} onSaved={onSaved} onCancel={onCancel} />
  return <NuevoPeriodoForm usuario={usuario} vinculos={vinculos} onSaved={onSaved} onCancel={onCancel} />
}

function NuevoPeriodoForm({ usuario, vinculos, onSaved, onCancel }) {
  const [draft, set] = useBorrador({ vinculo_laboral_id: vinculos.length === 1 ? vinculos[0].id : '', fecha_inicio: '', fecha_fin: '', resultado: 'pendiente', fecha_resultado: '' })
  const pendiente = draft.resultado === 'pendiente'
  const { busy, error, errors, guardar } = useGuardar(data => saveSeccion02(usuario, 'periodos-prueba', null, { ...data, fecha_resultado: pendiente ? null : data.fecha_resultado }), onSaved)
  return <Formulario titulo="Registrar período de prueba" guardar="Guardar período" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel}
    nota="Las fechas no se corrigen después: si hay un error, se anula y se registra de nuevo. Con resultado aprobado o no aprobado el registro queda cerrado.">
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
