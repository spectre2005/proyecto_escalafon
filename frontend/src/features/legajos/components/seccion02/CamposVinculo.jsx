import { useState } from 'react'
import { categoriasDocente, condicionesDocente, dedicacionesDocente, funcionesPrevias, gruposOcupacionales, regimenesLaborales, subtiposExtraordinario } from '../../config/seccion02'
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
export function CampoTipoVinculo({ draft, set, poner, errors, sugerencia = null, nombre = 'vinculo' }) {
  return <>
    <Campo label="Tipo de vínculo" required error={errors.regimen_laboral}><select name={`${nombre}-tipo`} required value={draft.regimen_laboral} onChange={set('regimen_laboral')}><Opciones lista={regimenesLaborales} actual={draft.regimen_laboral} vacio="Seleccionar tipo de vínculo" /></select></Campo>
    {sugerencia && <div role="note" className="flex flex-wrap items-center justify-between gap-2 self-end rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-900 sm:col-span-1 lg:col-span-2">
      <span>{sugerencia.valor ? <>Sugerencia: <strong>{regimenesLaborales[sugerencia.valor]}</strong> ({sugerencia.motivo}) Revísala: puedes elegir otro tipo.</> : sugerencia.motivo}</span>
      {sugerencia.valor && draft.regimen_laboral !== sugerencia.valor && <button type="button" onClick={() => poner('regimen_laboral')(sugerencia.valor)} className="font-semibold underline">Usar sugerencia</button>}
    </div>}
  </>
}

// A. Información del vínculo. children: campos tras el tipo de vínculo (en 2.2, la ley y la sugerencia). conCese: solo
// en 2.2 (el primer vínculo del registro es el vigente). conTipo: el registro manual pide el tipo en el paso 3
// (CampoTipoVinculo), después de los datos del puesto que permiten sugerirlo.
export function CamposVinculoBasicos({ draft, set, poner, errors, opciones, tipoPersonal, conCese = false, conTipo = true, nombre = 'vinculo', children }) {
  return <>
    {conTipo && <Campo label="Tipo de vínculo" required error={errors.regimen_laboral}><select name={`${nombre}-tipo`} required value={draft.regimen_laboral} onChange={set('regimen_laboral')}><Opciones lista={regimenesLaborales} actual={draft.regimen_laboral} vacio="Seleccionar tipo de vínculo" /></select></Campo>}
    {children}
    <CampoPuesto valor={draft.nombre_puesto} tipoPersonal={tipoPersonal} onChange={poner('nombre_puesto')} error={errors.nombre_puesto} nombre={nombre} />
    <Campo label="Unidad orgánica" error={errors.unidad_organica_id}><select name={`${nombre}-unidad`} value={draft.unidad_organica_id} onChange={set('unidad_organica_id')} disabled={!opciones}><OpcionesCatalogo lista={opciones?.unidades} actual={draft.unidad_organica_id} vacio={opciones ? 'Seleccionar unidad orgánica' : 'Cargando unidades…'} /></select></Campo>
    <Campo label="Fecha de inicio" required error={errors.fecha_inicio}><input name={`${nombre}-inicio`} type="date" required value={draft.fecha_inicio} onChange={set('fecha_inicio')} /></Campo>
    <Campo label="Fecha de fin prevista" error={errors.fecha_fin_prevista}><input name={`${nombre}-fin`} type="date" min={draft.fecha_inicio || undefined} value={draft.fecha_fin_prevista} onChange={set('fecha_fin_prevista')} /></Campo>
    {conCese && <Campo label="Fecha de cese (solo si el vínculo ya terminó)" error={errors.fecha_cese}><input type="date" min={draft.fecha_inicio || undefined} max={hoy()} value={draft.fecha_cese} onChange={set('fecha_cese')} /></Campo>}
  </>
}

// B. Documento de ingreso: resolución o contrato y datos de la selección.
export function CamposDocumentoIngreso({ draft, set, errors }) {
  return <>
    <Campo label="N.º de resolución o contrato" error={errors.numero_documento_vinculo}><input maxLength={60} value={draft.numero_documento_vinculo} onChange={set('numero_documento_vinculo')} /></Campo>
    <Campo label="Proceso de selección" error={errors.proceso_seleccion}><input maxLength={100} placeholder="Ej.: concurso o proceso CAS" value={draft.proceso_seleccion} onChange={set('proceso_seleccion')} /></Campo>
    <Campo label="Fecha del resultado de selección" error={errors.fecha_resultado_seleccion}><input type="date" max={hoy()} value={draft.fecha_resultado_seleccion} onChange={set('fecha_resultado_seleccion')} /></Campo>
  </>
}

// Las cuatro condiciones del puesto (Sí / No / Sin responder) en un bloque compacto.
export function CondicionesPuesto({ draft, poner, errors, nombre = 'vinculo' }) {
  return <div className="col-span-full grid gap-x-5 gap-y-3 rounded-xl border border-slate-100 bg-slate-50/60 p-4 sm:grid-cols-2">
    {condicionesPuesto.map(([key, text]) => <TresEstados key={key} name={`${nombre}-${key}`} label={text} value={draft[key]} onChange={poner(key)} error={errors[key]} />)}
  </div>
}

// Vínculo completo (sección 02): información, documento de ingreso y condiciones del puesto.
export function CamposVinculo({ draft, set, poner, errors, opciones, tipoPersonal, conCese = false, nombre = 'vinculo', children }) {
  return <>
    <CamposVinculoBasicos draft={draft} set={set} poner={poner} errors={errors} opciones={opciones} tipoPersonal={tipoPersonal} conCese={conCese} nombre={nombre}>{children}</CamposVinculoBasicos>
    <CamposDocumentoIngreso draft={draft} set={set} errors={errors} />
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
