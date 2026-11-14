import { useState } from 'react'
import { usePuede } from '../../../auth/session'
import { aplicarModalidad, aplicarPuesto, CON_PROCESO, modalidadElegida, modalidadesDe, notaCompletado, NOMBRES_CATALOGO, opcionesUnidad, puestosDe } from '../../config/catalogosVinculo'
import { sugerenciasPuesto } from '../../config/puestosSugeridos'
import { regimenesLaborales } from '../../config/seccion02'
import { aMayusculas } from '../../../../shared/forms/mayusculas'
import Campo from '../seccion01/Campo'
import { hoy } from '../seccion01/useGuardar'
import FormularioCatalogo from './FormularioCatalogo'
import PanelCatalogo from './PanelCatalogo'

// Campos del vínculo que usan los catálogos, como listas normales en cascada (unidad → puesto, tipo → modalidad). La
// última opción de cada lista agrega uno nuevo en un panel encima del formulario. Los comparten el registro manual de
// personal y la sección 02. Crear exige catalogos.gestionar; sin él, el puesto se puede escribir a mano.

const NUEVO = '__nuevo__'
const aplicarCambios = (poner, cambios) => Object.entries(cambios).forEach(([clave, valor]) => poner(clave)(valor))
const Nota = ({ texto }) => texto ? <p role="status" className="col-span-full rounded-lg bg-[#eaf4ee] px-3 py-2 text-[11px] text-[#2c7650]">{texto}</p> : null

/** Panel con el formulario del catálogo; al guardar devuelve el elemento creado. */
function PanelNuevo({ catalogo, opciones, contexto, onCerrar, onCreado }) {
  return <PanelCatalogo titulo={NOMBRES_CATALOGO[catalogo].nuevo} onCerrar={onCerrar}>
    <FormularioCatalogo catalogo={catalogo} opciones={opciones} contexto={{ ...contexto, usar: true }} onCancel={onCerrar} onGuardado={item => { onCreado(item); onCerrar() }} />
  </PanelCatalogo>
}

/** Unidad orgánica: lista con la jerarquía (de qué unidad depende cada una). Cambiarla quita un puesto de otra unidad. */
export function CampoUnidadOrganica({ draft, poner, errors, opciones, nombre = 'vinculo' }) {
  const puedeCrear = usePuede('catalogos.gestionar')
  const [creando, setCreando] = useState(false)
  const unidades = opcionesUnidad(opciones?.unidades, draft.unidad_organica_id)
  function elegir(id) {
    poner('unidad_organica_id')(id)
    const puesto = (opciones?.puestos || []).find(p => p.id === draft.puesto_id)
    if (puesto?.unidad_organica_id && puesto.unidad_organica_id !== id) aplicarCambios(poner, { puesto_id: '', nombre_puesto: '' })
  }
  return <>
    <Campo label="Unidad orgánica" required error={errors.unidad_organica_id}>
      <select name={`${nombre}-unidad`} required value={draft.unidad_organica_id} disabled={!opciones} onChange={event => event.target.value === NUEVO ? setCreando(true) : elegir(event.target.value)}>
        <option value="">{opciones ? 'Seleccionar unidad orgánica' : 'Cargando unidades…'}</option>
        {unidades.map(u => <option key={u.id} value={u.id}>{u.ruta.join(' › ')}</option>)}
        {puedeCrear && <option value={NUEVO}>+ Nueva unidad orgánica</option>}
      </select>
    </Campo>
    {creando && <PanelNuevo catalogo="unidades" opciones={opciones} contexto={{}} onCerrar={() => setCreando(false)} onCreado={unidad => elegir(unidad.id)} />}
  </>
}

/** Panel para escribir un puesto que no está en la lista (sin permiso para agregarlo al catálogo). */
function PanelOtroPuesto({ onCerrar, onUsar }) {
  const [texto, setTexto] = useState('')
  const usar = () => { if (texto.trim()) { onUsar(aMayusculas(texto.trim())); onCerrar() } }
  return <PanelCatalogo titulo="Otro puesto" onCerrar={onCerrar}>
    <div className="grid gap-4 rounded-2xl border border-teal-200 bg-white p-5 shadow-sm">
      <p className="text-sm font-semibold text-slate-800">Otro puesto</p>
      <label className="grid gap-2 text-xs font-medium text-slate-600">Denominación del puesto *
        <input autoFocus maxLength={150} value={texto} onChange={event => setTexto(event.target.value)}
          onKeyDown={event => { if (event.key === 'Enter') { event.preventDefault(); event.stopPropagation(); usar() } }}
          className="rounded-xl border border-slate-200 px-3 py-2.5 text-sm text-slate-800 outline-none focus:ring-2 focus:ring-teal-600" />
      </label>
      <p className="text-[11px] text-slate-500">Se guarda solo en este vínculo. Para que aparezca en la lista, pide a la administración que lo agregue al catálogo.</p>
      <div className="flex justify-end gap-2">
        <button type="button" onClick={onCerrar} className="rounded-xl border border-slate-200 px-4 py-2 text-sm text-slate-700">Cancelar</button>
        <button type="button" disabled={!texto.trim()} onClick={usar} className="rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Usar</button>
      </div>
    </div>
  </PanelCatalogo>
}

/**
 * Puesto, en cascada con la unidad: primero los puestos de esa unidad, luego los generales y las denominaciones
 * sugeridas. Al elegir uno del catálogo se proponen sus condiciones. «+ Nuevo puesto» lo agrega al catálogo; sin
 * permiso, «+ Otro puesto» permite escribir la denominación.
 */
export function CampoPuestoCatalogo({ draft, poner, errors, opciones, tipoPersonal, nombre = 'vinculo' }) {
  const puedeCrear = usePuede('catalogos.gestionar')
  const [panel, setPanel] = useState(false)
  const [nota, setNota] = useState('')
  const unidad = draft.unidad_organica_id
  const catalogo = puestosDe((opciones?.puestos || []).filter(p => p.activo !== false || p.id === draft.puesto_id), tipoPersonal, unidad)
  const deUnidad = catalogo.filter(p => unidad && p.unidad_organica_id === unidad)
  const generales = catalogo.filter(p => !p.unidad_organica_id)
  const enCatalogo = new Set(catalogo.map(p => p.nombre.toLocaleUpperCase('es-PE')))
  const sugeridos = sugerenciasPuesto(tipoPersonal).filter(texto => !enCatalogo.has(texto.toLocaleUpperCase('es-PE')))
  // Un puesto ya registrado que no está en las listas (escrito a mano o de otra unidad) se conserva como opción.
  const enLista = [...deUnidad, ...generales].some(p => p.id === draft.puesto_id)
  const valor = draft.puesto_id && enLista ? draft.puesto_id : draft.nombre_puesto ? `texto:${draft.nombre_puesto}` : ''
  const propio = valor.startsWith('texto:') && !sugeridos.includes(draft.nombre_puesto) ? draft.nombre_puesto : ''

  function elegirDelCatalogo(puesto) {
    const { draft: siguiente, completados } = aplicarPuesto(draft, puesto)
    aplicarCambios(poner, Object.fromEntries(['puesto_id', 'nombre_puesto', 'obligado_djbr', 'exige_colegiatura', 'tiene_periodo_prueba'].map(clave => [clave, siguiente[clave]])))
    setNota(notaCompletado('el puesto', completados))
  }
  function elegir(elegido) {
    setNota('')
    if (elegido === NUEVO) return setPanel(true)
    if (elegido.startsWith('texto:')) return aplicarCambios(poner, { puesto_id: '', nombre_puesto: elegido.slice(6) })
    if (!elegido) return aplicarCambios(poner, { puesto_id: '', nombre_puesto: '' })
    elegirDelCatalogo((opciones?.puestos || []).find(p => p.id === elegido))
  }
  return <>
    <Campo label="Puesto" required error={errors.nombre_puesto || errors.puesto_id}>
      <select name={`${nombre}-puesto`} required value={valor} disabled={!tipoPersonal || !unidad || !opciones} onChange={event => elegir(event.target.value)}>
        <option value="">{!tipoPersonal ? 'Primero elige el tipo de personal' : !unidad ? 'Primero elige la unidad orgánica' : 'Seleccionar puesto'}</option>
        {propio && <option value={valor}>{propio}</option>}
        {deUnidad.length > 0 && <optgroup label="Puestos de esta unidad">{deUnidad.map(p => <option key={p.id} value={p.id}>{p.nombre}</option>)}</optgroup>}
        {generales.length > 0 && <optgroup label="Puestos generales">{generales.map(p => <option key={p.id} value={p.id}>{p.nombre}</option>)}</optgroup>}
        {sugeridos.length > 0 && <optgroup label="Denominaciones sugeridas">{sugeridos.map(s => <option key={s} value={`texto:${s}`}>{s}</option>)}</optgroup>}
        <option value={NUEVO}>{puedeCrear ? '+ Nuevo puesto' : '+ Otro puesto (escribir)'}</option>
      </select>
    </Campo>
    {panel && puedeCrear && <PanelNuevo catalogo="puestos" opciones={opciones} contexto={{ tipoPersonal, unidadId: unidad || '' }} onCerrar={() => setPanel(false)} onCreado={elegirDelCatalogo} />}
    {panel && !puedeCrear && <PanelOtroPuesto onCerrar={() => setPanel(false)} onUsar={texto => aplicarCambios(poner, { puesto_id: '', nombre_puesto: texto })} />}
    <Nota texto={nota} />
  </>
}

/** Modalidad dentro del tipo de vínculo elegido (p. ej. CAS en plazo determinado). */
export function CampoModalidad({ draft, poner, errors, opciones, tipoPersonal, nombre = 'vinculo' }) {
  const puedeCrear = usePuede('catalogos.gestionar')
  const [creando, setCreando] = useState(false)
  const [nota, setNota] = useState('')
  const lista = modalidadesDe(opciones?.modalidades, draft.regimen_laboral, tipoPersonal).filter(m => m.activo !== false || m.id === draft.modalidad_vinculo_id)
  const elegida = modalidadElegida(opciones, draft)
  function elegir(modalidad) {
    const { draft: siguiente, completados } = aplicarModalidad(draft, modalidad)
    aplicarCambios(poner, { modalidad_vinculo_id: siguiente.modalidad_vinculo_id, tiene_periodo_prueba: siguiente.tiene_periodo_prueba })
    setNota(notaCompletado('la modalidad', completados))
  }
  return <>
    <Campo label="Modalidad" error={errors.modalidad_vinculo_id}>
      <select name={`${nombre}-modalidad`} value={draft.modalidad_vinculo_id || ''} disabled={!draft.regimen_laboral || !opciones}
        onChange={event => event.target.value === NUEVO ? setCreando(true) : elegir(lista.find(m => m.id === event.target.value) || null)}>
        <option value="">{draft.regimen_laboral ? 'Sin modalidad' : 'Primero elige el tipo de vínculo'}</option>
        {lista.map(m => <option key={m.id} value={m.id}>{m.nombre}{m.activo === false ? ' (desactivada)' : ''}</option>)}
        {puedeCrear && draft.regimen_laboral && <option value={NUEVO}>+ Nueva modalidad</option>}
      </select>
    </Campo>
    {elegida?.exige_fin_previsto && !draft.fecha_fin_prevista && <p role="note" className="col-span-full rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-900">
      La modalidad «{elegida.nombre}» exige la fecha de fin prevista del vínculo.</p>}
    {creando && <PanelNuevo catalogo="modalidades" opciones={opciones} contexto={{ regimen: draft.regimen_laboral, tipoPersonal: tipoPersonal || '' }}
      onCerrar={() => setCreando(false)} onCreado={elegir} />}
    <Nota texto={nota} />
  </>
}

/** Proceso de selección (proceso CAS, concurso público, concurso docente…) y la fecha de su resultado. */
export function CampoProcesoSeleccion({ draft, poner, errors, opciones, nombre = 'vinculo' }) {
  const puedeCrear = usePuede('catalogos.gestionar')
  const [creando, setCreando] = useState(false)
  const procesos = (opciones?.procesos || []).filter(p => p.activo !== false || p.id === draft.tipo_proceso_seleccion_id)
  // Un proceso escrito a mano antes de existir el catálogo se conserva como opción.
  const anterior = !draft.tipo_proceso_seleccion_id && draft.proceso_seleccion ? draft.proceso_seleccion : ''
  const valor = draft.tipo_proceso_seleccion_id || (anterior ? 'anterior' : '')
  function elegir(id) {
    if (id === 'anterior') return
    const proceso = procesos.find(p => p.id === id)
    aplicarCambios(poner, { tipo_proceso_seleccion_id: id, proceso_seleccion: proceso?.nombre || '' })
  }
  return <>
    <Campo label="Proceso de selección" error={errors.tipo_proceso_seleccion_id || errors.proceso_seleccion}>
      <select name={`${nombre}-proceso`} value={valor} disabled={!opciones} onChange={event => event.target.value === NUEVO ? setCreando(true) : elegir(event.target.value)}>
        <option value="">Seleccionar proceso de selección</option>
        {anterior && <option value="anterior">{anterior} (registrado antes)</option>}
        {procesos.map(p => <option key={p.id} value={p.id}>{p.nombre}{p.activo === false ? ' (desactivado)' : ''}</option>)}
        {puedeCrear && <option value={NUEVO}>+ Nuevo proceso de selección</option>}
      </select>
    </Campo>
    <Campo label="Fecha del resultado" error={errors.fecha_resultado_seleccion}><input name={`${nombre}-resultado`} type="date" max={hoy()} value={draft.fecha_resultado_seleccion || ''} onChange={event => poner('fecha_resultado_seleccion')(event.target.value)} /></Campo>
    {creando && <PanelNuevo catalogo="procesos" opciones={opciones} contexto={{}} onCerrar={() => setCreando(false)} onCreado={proceso => aplicarCambios(poner, { tipo_proceso_seleccion_id: proceso.id, proceso_seleccion: proceso.nombre })} />}
  </>
}

/**
 * Ingreso del vínculo. Carrera y plazo determinado: proceso de selección y fecha del resultado. Designado: no pasa por
 * concurso; ingresa con su resolución de designación. En todos, la fecha de emisión. El número de registro es el del
 * legajo (uno por legajo): numeroLegajo es el del legajo abierto o, en el registro de personal, el que se asignará.
 */
export function CamposIngreso({ draft, poner, errors, opciones, nuevo = true, numeroLegajo = '', nombre = 'vinculo' }) {
  const regimen = draft.regimen_laboral
  return <>
    {!regimen && <p className="col-span-full text-[11px] text-slate-500">Elige primero el tipo de vínculo: un designado no pasa por proceso de selección.</p>}
    {regimen === 'designado' && <p role="note" className="col-span-full rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-[11px] text-sky-900">
      Un {regimenesLaborales.designado.toLowerCase()} no pasa por concurso: ingresa con su resolución de designación.</p>}
    {CON_PROCESO.includes(regimen) && <CampoProcesoSeleccion draft={draft} poner={poner} errors={errors} opciones={opciones} nombre={nombre} />}
    {numeroLegajo && <div className="grid min-w-0 content-start gap-1">
      <Campo label="N.° de legajo"><input name={`${nombre}-legajo`} value={numeroLegajo} readOnly className="bg-slate-50 font-semibold tabular-nums text-slate-700" /></Campo>
      <small className="text-[11px] font-normal text-slate-500">{nuevo ? 'Se asigna al registrar a la persona. Es uno por legajo.' : 'Número del legajo de la persona: todos sus vínculos lo comparten.'}</small>
    </div>}
    {!nuevo && draft.numero_documento_vinculo && <Campo label="N.° de documento (registrado antes)"><input value={draft.numero_documento_vinculo} readOnly className="bg-slate-50 text-slate-600" /></Campo>}
    <Campo label="Fecha de emisión" error={errors.documento_vinculo_fecha}><input name={`${nombre}-emision`} type="date" max={hoy()} value={draft.documento_vinculo_fecha || ''} onChange={event => poner('documento_vinculo_fecha')(event.target.value)} /></Campo>
  </>
}
