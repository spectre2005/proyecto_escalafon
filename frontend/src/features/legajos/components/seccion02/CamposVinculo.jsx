import { categoriasDocente, condicionesDocente, dedicacionesDocente, funcionesPrevias, gruposOcupacionales, regimenesLaborales, subtiposExtraordinario } from '../../config/seccion02'
import Campo, { Opciones } from '../seccion01/Campo'
import { hoy } from '../seccion01/useGuardar'
import TresEstados from '../../../../components/ui/TresEstados'
import { CampoModalidad, CampoPuestoCatalogo, CampoUnidadOrganica, CamposIngreso } from './CamposCatalogoVinculo'
import { elegirTipoVinculo, modalidadElegida } from '../../config/catalogosVinculo'

// Campos del vínculo laboral y de sus datos del puesto, compartidos por la sección 02 (VinculoForm, DatosPuestoForm) y
// el registro manual de personal (RegistroManual). Las reglas las valida el backend con una sola fuente (ReglasVinculo).

// Opciones con estado activo: los inactivos solo se conservan si ya están guardados. Sin valor, nada elegido: se
// muestra el texto orientativo (vacio).
export function OpcionesCatalogo({ lista, actual, vacio = 'Selecciona' }) {
  return <>
    <option value="">{vacio}</option>
    {(lista || []).filter(item => item.activo !== false || item.id === actual).map(item => <option key={item.id} value={item.id}>{item.nombre}</option>)}
  </>
}

const condicionesPuesto = [['obligado_djbr', 'Obligado a la declaración jurada de bienes y rentas'], ['exige_colegiatura', 'El puesto exige colegiatura'], ['exige_estudios_basicos', 'El puesto exige estudios básicos'], ['tiene_periodo_prueba', 'Tiene período de prueba']]

const descripcionTipo = (opciones, regimen) => (opciones?.tipos_vinculo || []).find(tipo => tipo.codigo === regimen)?.descripcion || ''

// Tipo de vínculo con la sugerencia que se deduce de los datos ({ valor, motivo }, opcional). La sugerencia solo se aplica
// con su botón y el usuario puede elegir otro tipo: no es una validación.
// Debajo, la modalidad de ese tipo (catálogo).
export function CampoTipoVinculo({ draft, poner, errors, sugerencia = null, nombre = 'vinculo', opciones = null, tipoPersonal = '' }) {
  return <>
    <div className="grid min-w-0 content-start gap-1">
      <Campo label="Tipo de vínculo" required error={errors.regimen_laboral}><select name={`${nombre}-tipo`} required value={draft.regimen_laboral} onChange={event => elegirTipoVinculo(draft, poner, opciones, event.target.value)}><Opciones lista={regimenesLaborales} actual={draft.regimen_laboral} vacio="Seleccionar tipo de vínculo" /></select></Campo>
      {descripcionTipo(opciones, draft.regimen_laboral) && <small className="text-[11px] text-slate-500">{descripcionTipo(opciones, draft.regimen_laboral)}</small>}
    </div>
    <CampoModalidad draft={draft} poner={poner} errors={errors} opciones={opciones} tipoPersonal={tipoPersonal} nombre={nombre} />
    {sugerencia && <div role="note" className="flex flex-wrap items-center justify-between gap-2 self-end rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-900 sm:col-span-1 lg:col-span-2">
      <span>{sugerencia.valor ? <>Sugerencia: <strong>{regimenesLaborales[sugerencia.valor]}</strong> ({sugerencia.motivo}) Revísala: puedes elegir otro tipo.</> : sugerencia.motivo}</span>
      {sugerencia.valor && draft.regimen_laboral !== sugerencia.valor && <button type="button" onClick={() => elegirTipoVinculo(draft, poner, opciones, sugerencia.valor)} className="font-semibold underline">Usar sugerencia</button>}
    </div>}
  </>
}

// Fechas del vínculo. La fecha de fin es obligatoria si la modalidad lo exige; su ayuda cambia con el tipo de vínculo.
export function CamposVigencia({ draft, poner, errors, opciones, conCese = false, ceseObligatorio = false, nombre = 'vinculo' }) {
  const modalidad = modalidadElegida(opciones, draft)
  const finObligatorio = !!modalidad?.exige_fin_previsto && !draft.fecha_cese
  const ayudaFin = { designado: 'Opcional: un designado sigue hasta que se da por concluida su designación.', carrera: 'Normalmente vacía en un vínculo de carrera.' }[draft.regimen_laboral]
  const valor = key => event => poner(key)(event.target.value)
  return <>
    <div className="grid min-w-0 content-start gap-1">
      <Campo label="Fecha de inicio" required error={errors.fecha_inicio}><input name={`${nombre}-inicio`} type="date" required value={draft.fecha_inicio} onChange={valor('fecha_inicio')} /></Campo>
      {draft.fecha_inicio > hoy() && <small className="text-[11px] text-sky-800">Empieza el {draft.fecha_inicio.split('-').reverse().join('/')}: hasta entonces figurará como «por iniciar».</small>}
    </div>
    <div className="grid min-w-0 content-start gap-1">
      <Campo label="Fecha de fin prevista" required={finObligatorio} error={errors.fecha_fin_prevista}><input name={`${nombre}-fin`} type="date" required={finObligatorio} min={draft.fecha_inicio || undefined} value={draft.fecha_fin_prevista} onChange={valor('fecha_fin_prevista')} /></Campo>
      {(finObligatorio || ayudaFin) && <small className="text-[11px] text-slate-500">{finObligatorio ? `La modalidad «${modalidad.nombre}» la exige.` : ayudaFin}</small>}
    </div>
    {conCese && <Campo label="Fecha de cese (solo si el vínculo ya terminó)" required={ceseObligatorio} error={errors.fecha_cese}><input type="date" required={ceseObligatorio} min={draft.fecha_inicio || undefined} max={hoy()} value={draft.fecha_cese} onChange={valor('fecha_cese')} /></Campo>}
  </>
}

// Dónde trabaja: unidad orgánica y puesto (catálogos).
export function CamposUbicacionPuesto({ draft, poner, errors, opciones, tipoPersonal, nombre = 'vinculo' }) {
  return <>
    <CampoUnidadOrganica draft={draft} poner={poner} errors={errors} opciones={opciones} nombre={nombre} />
    <CampoPuestoCatalogo draft={draft} poner={poner} errors={errors} opciones={opciones} tipoPersonal={tipoPersonal} nombre={nombre} />
  </>
}

// A. Información del vínculo en la sección 02: tipo y modalidad, children (la ley y la sugerencia), unidad, puesto y fechas.
export function CamposVinculoBasicos({ draft, poner, errors, opciones, tipoPersonal, conCese = false, ceseObligatorio = false, nombre = 'vinculo', children }) {
  return <>
    <CampoTipoVinculo draft={draft} poner={poner} errors={errors} opciones={opciones} tipoPersonal={tipoPersonal} nombre={nombre} />
    {children}
    <CamposUbicacionPuesto draft={draft} poner={poner} errors={errors} opciones={opciones} tipoPersonal={tipoPersonal} nombre={nombre} />
    <CamposVigencia draft={draft} poner={poner} errors={errors} opciones={opciones} conCese={conCese} ceseObligatorio={ceseObligatorio} nombre={nombre} />
  </>
}

// B. Ingreso: proceso de selección (carrera y plazo determinado), número de legajo y fecha de emisión.
export function CamposDocumentoIngreso({ draft, poner, errors, opciones, nuevo = true, numeroLegajo = '', nombre = 'vinculo' }) {
  return <CamposIngreso draft={draft} poner={poner} errors={errors} opciones={opciones} nuevo={nuevo} numeroLegajo={numeroLegajo} nombre={nombre} />
}

// Las cuatro condiciones del puesto (Sí / No / Sin responder) en un bloque compacto.
export function CondicionesPuesto({ draft, poner, errors, nombre = 'vinculo' }) {
  return <div className="col-span-full grid gap-x-5 gap-y-3 rounded-xl border border-slate-100 bg-slate-50/60 p-4 sm:grid-cols-2">
    {condicionesPuesto.map(([key, text]) => <TresEstados key={key} name={`${nombre}-${key}`} label={text} value={draft[key]} onChange={poner(key)} error={errors[key]} />)}
  </div>
}

// Vínculo completo (sección 02): información, documento de ingreso y condiciones del puesto.
export function CamposVinculo({ draft, poner, errors, opciones, tipoPersonal, conCese = false, ceseObligatorio = false, nombre = 'vinculo', nuevo = true, numeroLegajo = '', children }) {
  return <>
    <CamposVinculoBasicos draft={draft} poner={poner} errors={errors} opciones={opciones} tipoPersonal={tipoPersonal} conCese={conCese} ceseObligatorio={ceseObligatorio} nombre={nombre}>{children}</CamposVinculoBasicos>
    <p className="col-span-full mt-2 text-xs font-semibold text-slate-700">Ingreso</p>
    <CamposDocumentoIngreso draft={draft} poner={poner} errors={errors} opciones={opciones} nuevo={nuevo} numeroLegajo={numeroLegajo} nombre={nombre} />
    <CondicionesPuesto draft={draft} poner={poner} errors={errors} nombre={nombre} />
  </>
}

export function CamposDatosPuesto({ docente, draft, set, errors }) {
  return docente ? <>
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
  </>
}
