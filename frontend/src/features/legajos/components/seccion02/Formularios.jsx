import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import { saveDatosPuesto, saveSeccion02 } from '../../api/seccion02Api'
import { categoriasDocente, condicionesDocente, dedicacionesDocente, etiquetaVinculo, funcionesPrevias, gruposOcupacionales, regimenesLaborales, resultadosPeriodo, subtiposExtraordinario, tiposInduccion, tiposPersonal } from '../../config/seccion02'
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

// Opciones con estado activo: los inactivos solo se conservan si ya están guardados.
function OpcionesCatalogo({ lista, actual }) {
  return <>
    <option value="">Selecciona</option>
    {(lista || []).filter(item => item.activo !== false || item.id === actual).map(item => <option key={item.id} value={item.id}>{item.nombre}</option>)}
  </>
}

// Vínculo laboral (vinculos_laborales), con los datos del proceso de selección. Se corrige solo mientras no tenga cese.
export function VinculoForm({ usuario, record, documentoId, onSaved, onCancel }) {
  const v = record?.values || {}
  const opciones = useLiveQuery('/expediente-opciones').data?.data
  const [draft, set] = useBorrador({
    tipo_personal: v.tipo_personal || '', regimen_laboral: v.regimen_laboral || '', regimen_normativo_id: v.regimen_normativo_id || '',
    unidad_organica_id: v.unidad_organica_id || '', nombre_puesto: v.nombre_puesto || '', numero_documento_vinculo: v.numero_documento_vinculo || '',
    fecha_inicio: v.fecha_inicio || '', fecha_fin_prevista: v.fecha_fin_prevista || '', fecha_cese: v.fecha_cese || '',
    proceso_seleccion: v.proceso_seleccion || '', fecha_resultado_seleccion: v.fecha_resultado_seleccion || '',
    obligado_djbr: v.obligado_djbr ?? false, exige_colegiatura: v.exige_colegiatura ?? false, tiene_periodo_prueba: v.tiene_periodo_prueba ?? false,
  })
  const { busy, error, errors, guardar } = useGuardar(data => saveSeccion02(usuario, 'vinculos-laborales', record?.id, conDocumento(data, documentoId)), onSaved)
  return <Formulario titulo={record ? 'Corregir vínculo laboral' : 'Registrar vínculo laboral'} guardar="Guardar vínculo" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel}
    nota="Datos de la resolución o contrato. Con fecha de cese el vínculo queda cerrado y ya no se corrige.">
    <NotaDocumento documentoId={documentoId} />
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

// Datos del puesto del vínculo (perfil_docente o perfil_administrativo según su tipo de personal). Se corrigen
// en el mismo registro mientras el vínculo no tenga fecha de cese ni esté anulado.
export function DatosPuestoForm({ usuario, vinculo, onSaved, onCancel }) {
  const docente = vinculo.values.tipo_personal === 'docente'
  const v = vinculo.datos_puesto[0]?.values || {}
  const [draft, set] = useBorrador(docente
    ? { condicion_docente: v.condicion_docente || '', categoria: v.categoria || '', dedicacion: v.dedicacion || '', subtipo_extraordinario: v.subtipo_extraordinario || '', funcion_previa: v.funcion_previa || '', codigo_renacyt: v.codigo_renacyt || '' }
    : { grupo_ocupacional: v.grupo_ocupacional || '', nivel_carrera: v.nivel_carrera || '', cargo_estructural: v.cargo_estructural || '', es_cargo_confianza: v.es_cargo_confianza ?? false })
  // La categoría es solo de ordinarios y el subtipo, de extraordinarios: si no corresponde, se envía vacío.
  const limpiar = data => docente ? { ...data, categoria: data.condicion_docente === 'ordinario' ? data.categoria : null, subtipo_extraordinario: data.condicion_docente === 'extraordinario' ? data.subtipo_extraordinario : null } : data
  const { busy, error, errors, guardar } = useGuardar(data => saveDatosPuesto(usuario, vinculo.id, limpiar(data)), onSaved)
  return <Formulario titulo={`Datos del puesto: ${vinculo.values.nombre_puesto}`} guardar="Guardar datos del puesto" busy={busy} error={error} onSubmit={() => guardar(draft)} onCancel={onCancel}
    nota="Se corrigen mientras el vínculo no tenga fecha de cese. El responsable se guarda con tu sesión.">
    {docente ? <>
      <Campo label="Condición docente" required error={errors.condicion_docente}><select required value={draft.condicion_docente} onChange={set('condicion_docente')}><Opciones lista={condicionesDocente} actual={draft.condicion_docente} vacio="Selecciona" /></select></Campo>
      <Campo label="Categoría (solo ordinarios)" error={errors.categoria}><select disabled={draft.condicion_docente !== 'ordinario'} value={draft.condicion_docente === 'ordinario' ? draft.categoria : ''} onChange={set('categoria')}><Opciones lista={categoriasDocente} actual={draft.categoria} vacio="Sin categoría" /></select></Campo>
      <Campo label="Dedicación" error={errors.dedicacion}><select value={draft.dedicacion} onChange={set('dedicacion')}><Opciones lista={dedicacionesDocente} actual={draft.dedicacion} vacio="Sin registrar" /></select></Campo>
      <Campo label="Subtipo (solo extraordinarios)" error={errors.subtipo_extraordinario}><select disabled={draft.condicion_docente !== 'extraordinario'} value={draft.condicion_docente === 'extraordinario' ? draft.subtipo_extraordinario : ''} onChange={set('subtipo_extraordinario')}><Opciones lista={subtiposExtraordinario} actual={draft.subtipo_extraordinario} vacio="Sin subtipo" /></select></Campo>
      <Campo label="Función previa" error={errors.funcion_previa}><select value={draft.funcion_previa} onChange={set('funcion_previa')}><Opciones lista={funcionesPrevias} actual={draft.funcion_previa} vacio="Sin registrar" /></select></Campo>
      <Campo label="Código RENACYT" error={errors.codigo_renacyt}><input maxLength={30} value={draft.codigo_renacyt} onChange={set('codigo_renacyt')} /></Campo>
    </> : <>
      <Campo label="Grupo ocupacional" error={errors.grupo_ocupacional}><select value={draft.grupo_ocupacional} onChange={set('grupo_ocupacional')}><Opciones lista={gruposOcupacionales} actual={draft.grupo_ocupacional} vacio="Sin registrar" /></select></Campo>
      <Campo label="Nivel de carrera" error={errors.nivel_carrera}><input maxLength={20} value={draft.nivel_carrera} onChange={set('nivel_carrera')} /></Campo>
      <Campo label="Cargo estructural" error={errors.cargo_estructural} className="sm:col-span-2"><input maxLength={150} value={draft.cargo_estructural} onChange={set('cargo_estructural')} /></Campo>
      <label className="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2"><input type="checkbox" className="size-4 accent-teal-700" checked={draft.es_cargo_confianza} onChange={set('es_cargo_confianza')} />Es cargo de confianza</label>
    </>}
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
