import { useState } from 'react'
import { fuenteDocenciaCas, modalidadesIngreso, requisitosIngreso, rotulosIngreso } from '../../config/ingresoLaboral'
import ProcesoSeleccionFields from './ProcesoSeleccionFields'
import { categoriasDocente, condicionesDocente, dedicacionesDocente, funcionesPrevias, gruposOcupacionales, regimenesLaborales, subtiposExtraordinario, tiposVinculoPara } from '../../config/seccion02'
import { etiquetaOtro, OTRO, puestoElegido, seleccionPuesto, sugerenciasPuesto } from '../../config/puestosSugeridos'
import Campo, { Opciones } from '../seccion01/Campo'
import { hoy } from '../seccion01/useGuardar'
import TresEstados from '../../../../components/ui/TresEstados'

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

// Puesto (nombre_puesto, texto libre) con sugerencias provisionales según el tipo de personal. «Otro» permite escribir
// una denominación propia, que es la que se guarda. Un puesto guardado que no está en las sugerencias (anterior o propio)
// se muestra como «Otro» con su texto, sin perderlo; también al cambiar el tipo de personal.
export function CampoPuesto({ valor, tipoPersonal, onChange, error, nombre = 'vinculo' }) {
  const [otro, setOtro] = useState(false)
  const opcion = seleccionPuesto(valor, tipoPersonal, otro)
  const sugerencias = sugerenciasPuesto(tipoPersonal)
  function elegir(event) {
    const elegido = puestoElegido(event.target.value, valor, tipoPersonal)
    setOtro(elegido.otro)
    onChange(elegido.nombre_puesto)
  }
  return <>
    <Campo label="Puesto" required error={opcion === OTRO ? undefined : error}><select name={`${nombre}-puesto`} required value={opcion} onChange={elegir}>
      <option value="">{tipoPersonal ? 'Seleccionar puesto' : 'Primero elige el tipo de personal'}</option>
      {sugerencias.length > 0 && <optgroup label="Sugerencias provisionales">{sugerencias.map(puesto => <option key={puesto} value={puesto}>{puesto}</option>)}</optgroup>}
      <option value={OTRO}>{etiquetaOtro[tipoPersonal] || 'Otro puesto'} (escribir denominación)</option>
    </select></Campo>
    {opcion === OTRO && <Campo label="Denominación del puesto" required error={error}><input name={`${nombre}-puesto-texto`} required maxLength={150} placeholder="Escribe la denominación" value={valor} onChange={event => onChange(event.target.value)} /></Campo>}
  </>
}

const condicionesPuesto = [['obligado_djbr', 'Obligado a la declaración jurada de bienes y rentas'], ['exige_colegiatura', 'El puesto exige colegiatura'], ['exige_estudios_basicos', 'El puesto exige estudios básicos'], ['tiene_periodo_prueba', 'Tiene período de prueba']]

// Tipo de vínculo con la sugerencia que se deduce de los datos ({ valor, motivo }, opcional). La sugerencia solo se aplica
// con su botón y el usuario puede elegir otro tipo: no es una validación.
export function CampoTipoVinculo({ draft, set, poner, errors, sugerencia = null, nombre = 'vinculo', tipoPersonal, codigoLey = '' }) {
  return <>
    <Campo label="Tipo de vínculo" required error={errors.regimen_laboral}><select name={`${nombre}-tipo`} required value={draft.regimen_laboral} onChange={set('regimen_laboral')}><Opciones lista={tiposVinculoPara(tipoPersonal, codigoLey)} actual={draft.regimen_laboral} vacio="Seleccionar tipo de vínculo" /></select></Campo>
    {tipoPersonal === 'docente' && <p className="self-end rounded-lg bg-teal-50 p-3 text-xs text-teal-900">La condición docente se registra como ordinario, extraordinario o contratado. La designación por confianza corresponde a un vínculo administrativo.</p>}
    {sugerencia && <div role="note" className="flex flex-wrap items-center justify-between gap-2 self-end rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-900 sm:col-span-1 lg:col-span-2">
      <span>{sugerencia.valor ? <>Sugerencia: <strong>{regimenesLaborales[sugerencia.valor]}</strong> ({sugerencia.motivo}) Revísala: puedes elegir otro tipo.</> : sugerencia.motivo}</span>
      {sugerencia.valor && draft.regimen_laboral !== sugerencia.valor && <button type="button" onClick={() => poner('regimen_laboral')(sugerencia.valor)} className="font-semibold underline">Usar sugerencia</button>}
    </div>}
  </>
}

// A. Información del vínculo. children: campos tras el tipo de vínculo (en 2.2, la ley y la sugerencia). conCese: solo
// en 2.2 (el primer vínculo del registro es el vigente). conTipo: el registro manual pide el tipo en el paso 2
// (CampoTipoVinculo), después de los datos del puesto que permiten sugerirlo.
export function CamposVinculoBasicos({ draft, set, poner, errors, opciones, tipoPersonal, conCese = false, ceseObligatorio = false, conTipo = true, nombre = 'vinculo', codigoLey = '', children }) {
  return <>
    {conTipo && <Campo label="Tipo de vínculo" required error={errors.regimen_laboral}><select name={`${nombre}-tipo`} required value={draft.regimen_laboral} onChange={set('regimen_laboral')}><Opciones lista={tiposVinculoPara(tipoPersonal, codigoLey)} actual={draft.regimen_laboral} vacio="Seleccionar tipo de vínculo" /></select></Campo>}
    {children}
    <CampoPuesto valor={draft.nombre_puesto} tipoPersonal={tipoPersonal} onChange={poner('nombre_puesto')} error={errors.nombre_puesto} nombre={nombre} />
    <Campo label="Unidad orgánica" error={errors.unidad_organica_id}><select name={`${nombre}-unidad`} value={draft.unidad_organica_id} onChange={set('unidad_organica_id')} disabled={!opciones}><OpcionesCatalogo lista={opciones?.unidades} actual={draft.unidad_organica_id} vacio={opciones ? 'Seleccionar unidad orgánica' : 'Cargando unidades…'} /></select></Campo>
    <Campo label="Fecha de inicio" required error={errors.fecha_inicio}><input name={`${nombre}-inicio`} type="date" required value={draft.fecha_inicio} onChange={set('fecha_inicio')} /></Campo>
    <Campo label="Fecha de fin prevista" error={errors.fecha_fin_prevista}><input name={`${nombre}-fin`} type="date" min={draft.fecha_inicio || undefined} disabled={draft.regimen_laboral === 'plazo_indeterminado'} value={draft.regimen_laboral === 'plazo_indeterminado' ? '' : draft.fecha_fin_prevista} onChange={set('fecha_fin_prevista')} /></Campo>
    {conCese && <Campo label="Fecha de cese (solo si el vínculo ya terminó)" required={ceseObligatorio} error={errors.fecha_cese}><input type="date" required={ceseObligatorio} min={draft.fecha_inicio || undefined} max={hoy()} value={draft.fecha_cese} onChange={set('fecha_cese')} /></Campo>}
  </>
}

export function CamposModalidadIngreso({ draft, set, errors, tipoPersonal, required = true }) {
  const opciones = Object.fromEntries(Object.entries(modalidadesIngreso).filter(([valor]) => tipoPersonal !== 'docente' || valor !== 'designacion_confianza'))
  return <>
    <Campo label="Modalidad de incorporación" required={required} error={errors.modalidad_ingreso}><select name="modalidad_ingreso" required={required} value={draft.modalidad_ingreso} onChange={set('modalidad_ingreso')}><Opciones lista={opciones} actual={draft.modalidad_ingreso} vacio={required ? 'Seleccionar modalidad' : 'Pendiente de clasificar (registro anterior)'} /></select></Campo>
    {draft.modalidad_ingreso === 'otro_sustentado' && <Campo label="Modalidad y sustento del ingreso sin concurso" required error={errors.sustento_ingreso} className="sm:col-span-2"><textarea name="sustento_ingreso" required maxLength={500} rows={3} value={draft.sustento_ingreso} onChange={set('sustento_ingreso')} /></Campo>}
    {draft.modalidad_ingreso === 'designacion_confianza' && <p className="self-end text-xs leading-relaxed text-teal-800 sm:col-span-2">Registra la resolución de designación. Si el régimen es CAS, también corresponde el contrato. Esta modalidad no lleva convocatoria de concurso.</p>}
  </>
}

export function CamposProcesoSeleccion(props) {
  return <ProcesoSeleccionFields {...props} />
}

export function AvisoDocenciaCas() {
  return <details className="col-span-full rounded-xl border border-amber-200 bg-amber-50 p-4 text-xs leading-relaxed text-amber-950">
    <summary className="cursor-pointer font-semibold">¿La persona también ejerce docencia?</summary>
    <p className="mt-2">Puede tener un vínculo docente y otro CAS independiente. La excepción de doble percepción requiere ejercicio efectivo de docencia y compatibilidad horaria; Recursos Humanos debe evaluar las demás incompatibilidades, incluida una eventual dedicación exclusiva. Guardar este vínculo no acredita esa evaluación.</p>
    <p className="mt-2">La Carrera Pública Magisterial (Ley N.º 29944) y la docencia universitaria (Ley N.º 30220) son regímenes distintos. Para este puesto CAS registra su contrato y convocatoria; conserva por separado los documentos del vínculo docente, aunque pertenezca a otra entidad.</p>
    <a className="mt-2 inline-block font-semibold underline" href={fuenteDocenciaCas} target="_blank" rel="noopener noreferrer">Consultar el informe técnico de SERVIR</a>
  </details>
}

export function CamposDocumentoIngreso({ draft, set, poner, errors, tipoPersonal, codigoLey, regimenId, condicionDocente }) {
  const requisitos = requisitosIngreso(draft, codigoLey, tipoPersonal)
  const rotulos = rotulosIngreso(tipoPersonal, condicionDocente, codigoLey)
  const orden = tipoPersonal !== 'docente' && requisitos.contrato ? ['contrato', 'resolucion'] : ['resolucion', 'contrato']
  function documento(tipo) {
    const numero = `numero_${tipo}`, fecha = `fecha_${tipo}`
    const declarado = Boolean(draft[numero] || draft[fecha])
    const obligatorio = requisitos[tipo]
    const estricto = Boolean(draft.modalidad_ingreso)
    const titulo = rotulos[tipo]
    const campos = <div className="grid min-w-0 gap-4 sm:grid-cols-2">
      <Campo label={`N.º de ${tipo}`} required={obligatorio || !!draft[fecha]} error={errors[numero]}><input name={numero} required={obligatorio || !!draft[fecha]} maxLength={60} placeholder={tipo === 'contrato' ? 'Ej.: CONTRATO-028-2026' : 'Ej.: R-015-2026'} value={draft[numero]} onChange={set(numero)} /></Campo>
      <Campo label={`Fecha de ${tipo}`} required={estricto && (obligatorio || declarado)} error={errors[fecha]}><input name={fecha} type="date" required={estricto && (obligatorio || declarado)} max={hoy()} value={draft[fecha]} onChange={set(fecha)} /></Campo>
    </div>
    return obligatorio
      ? <div key={tipo} className="col-span-full space-y-3 rounded-xl border border-teal-100 bg-white p-4"><h4 className="text-xs font-semibold text-slate-800">{titulo} <span className="font-normal text-teal-700">{obligatorio ? '· Requerido para esta incorporación' : '· Documento adicional'}</span></h4>{campos}</div>
      : <details key={tipo} open={declarado || undefined} className="col-span-full rounded-xl border border-slate-200 bg-white p-4"><summary className="cursor-pointer text-xs font-semibold text-slate-600">Agregar {rotulos[tipo].toLowerCase()} adicional (si corresponde)</summary><div className="mt-4">{campos}</div></details>
  }
  return <>
    {tipoPersonal === 'administrativo' && ['1057', 'CAS'].includes(codigoLey) && <AvisoDocenciaCas />}
    <div className="col-span-full rounded-xl border border-teal-100 bg-teal-50 p-3 text-xs leading-relaxed text-teal-900">
      {tipoPersonal === 'docente' ? `${rotulos.resolucion}. La clasificación se toma de la condición docente; registra el número y la fecha de la resolución emitida por la institución.` : 'Los documentos se solicitan según el régimen y la modalidad de incorporación.'} Copia el número y la fecha del documento oficial. Los PDF se adjuntan al vínculo después de guardarlo.
    </div>
    {orden.map(documento)}
    {draft.numero_documento_vinculo && <p className="col-span-full rounded-lg bg-amber-50 p-3 text-xs text-amber-900">Referencia anterior: <strong>{draft.numero_documento_vinculo}</strong>. Se conserva sin asignarle una clasificación automática.</p>}
    {(requisitos.proceso || !draft.modalidad_ingreso) && <>
      <div className="col-span-full mt-1"><h4 className="text-xs font-semibold text-slate-800">Proceso de selección</h4><p className="mt-1 text-xs text-slate-500">La convocatoria pertenece a esta institución y al régimen del vínculo; puede incluir varias incorporaciones.</p></div>
      <CamposProcesoSeleccion draft={draft} set={set} poner={poner} errors={errors} regimenId={regimenId} tipoPersonal={tipoPersonal} codigoLey={codigoLey} required={requisitos.proceso} />
    </>}
  </>
}

// Las cuatro condiciones del puesto (Sí / No / Sin responder) en un bloque compacto.
export function CondicionesPuesto({ draft, poner, errors, nombre = 'vinculo' }) {
  return <div className="col-span-full grid gap-x-5 gap-y-3 rounded-xl border border-slate-100 bg-slate-50/60 p-4 sm:grid-cols-2">
    {condicionesPuesto.map(([key, text]) => <TresEstados key={key} name={`${nombre}-${key}`} label={text} value={draft[key]} onChange={poner(key)} error={errors[key]} />)}
  </div>
}

// Vínculo completo (sección 02): información, documento de ingreso y condiciones del puesto.
export function CamposVinculo({ draft, set, poner, errors, opciones, tipoPersonal, conCese = false, ceseObligatorio = false, nombre = 'vinculo', codigoLey = '', condicionDocente, requiereModalidad = true, children }) {
  return <>
    <CamposVinculoBasicos draft={draft} set={set} poner={poner} errors={errors} opciones={opciones} tipoPersonal={tipoPersonal} conCese={conCese} ceseObligatorio={ceseObligatorio} nombre={nombre} codigoLey={codigoLey}>{children}</CamposVinculoBasicos>
    <CamposModalidadIngreso draft={draft} set={set} errors={errors} tipoPersonal={tipoPersonal} required={requiereModalidad} />
    <CamposDocumentoIngreso draft={draft} set={set} poner={poner} errors={errors} tipoPersonal={tipoPersonal} codigoLey={codigoLey} regimenId={draft.regimen_normativo_id} condicionDocente={condicionDocente} />
    <CondicionesPuesto draft={draft} poner={poner} errors={errors} nombre={nombre} />
  </>
}

export function CamposDatosPuesto({ docente, draft, set, errors, confianzaFija }) {
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
    {confianzaFija !== undefined ? <p className="self-end text-xs text-slate-600 sm:col-span-2">Cargo de confianza: <strong>{confianzaFija ? 'Sí' : 'No'}</strong>. Se toma de la modalidad de incorporación.</p> : <label className="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2"><input type="checkbox" className="size-4 accent-teal-700" checked={draft.es_cargo_confianza} onChange={set('es_cargo_confianza')} />Es cargo de confianza</label>}
  </>
}
