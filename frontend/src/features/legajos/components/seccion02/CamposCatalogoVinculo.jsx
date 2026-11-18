import { useState } from 'react'
import { aplicarModalidad, aplicarPuesto, CON_PROCESO, modalidadElegida, modalidadesDe, notaCompletado, opcionesUnidad, puestosDe } from '../../config/catalogosVinculo'
import { sugerenciasPuesto } from '../../config/puestosSugeridos'
import { regimenesLaborales } from '../../config/seccion02'
import Campo from '../seccion01/Campo'
import { hoy } from '../seccion01/useGuardar'

// Campos del vínculo que usan los catálogos, como listas en cascada (unidad → puesto, tipo → modalidad). Solo se elige de
// lo que hay: desde el 18/11/2026 no se crean unidades, puestos, modalidades ni procesos desde los formularios (decisión
// del equipo). Lo ya guardado que no está en la lista (p. ej. un puesto escrito a mano antes) se conserva como opción.
// Los comparten el registro manual de personal y la sección 02.

const aplicarCambios = (poner, cambios) => Object.entries(cambios).forEach(([clave, valor]) => poner(clave)(valor))
const Nota = ({ texto }) => texto ? <p role="status" className="col-span-full rounded-lg bg-teal-50 px-3 py-2 text-xs text-teal-800">{texto}</p> : null

/** Unidad orgánica: lista con la jerarquía (de qué unidad depende cada una). Cambiarla quita un puesto de otra unidad. */
export function CampoUnidadOrganica({ draft, poner, errors, opciones, nombre = 'vinculo' }) {
  const unidades = opcionesUnidad(opciones?.unidades, draft.unidad_organica_id)
  function elegir(id) {
    poner('unidad_organica_id')(id)
    const puesto = (opciones?.puestos || []).find(p => p.id === draft.puesto_id)
    if (puesto?.unidad_organica_id && puesto.unidad_organica_id !== id) aplicarCambios(poner, { puesto_id: '', nombre_puesto: '' })
  }
  return <>
    <Campo label="Unidad orgánica" required error={errors.unidad_organica_id}>
      <select name={`${nombre}-unidad`} required value={draft.unidad_organica_id} disabled={!opciones} onChange={event => elegir(event.target.value)}>
        <option value="">{opciones ? 'Seleccionar unidad orgánica' : 'Cargando unidades…'}</option>
        {unidades.map(u => <option key={u.id} value={u.id}>{u.ruta.join(' › ')}</option>)}
      </select>
    </Campo>
  </>
}

/**
 * Puesto, en cascada con la unidad: primero los puestos de esa unidad, luego los generales y las denominaciones
 * sugeridas. Al elegir uno del catálogo se proponen sus condiciones.
 */
export function CampoPuestoCatalogo({ draft, poner, errors, opciones, tipoPersonal, nombre = 'vinculo' }) {
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
      </select>
    </Campo>
    <Nota texto={nota} />
  </>
}

/** Modalidad dentro del tipo de vínculo elegido (p. ej. CAS en plazo determinado). */
export function CampoModalidad({ draft, poner, errors, opciones, tipoPersonal, nombre = 'vinculo' }) {
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
        onChange={event => elegir(lista.find(m => m.id === event.target.value) || null)}>
        <option value="">{draft.regimen_laboral ? 'Sin modalidad' : 'Primero elige el tipo de vínculo'}</option>
        {lista.map(m => <option key={m.id} value={m.id}>{m.nombre}{m.activo === false ? ' (desactivada)' : ''}</option>)}
      </select>
    </Campo>
    {elegida?.exige_fin_previsto && !draft.fecha_fin_prevista && <p role="note" className="col-span-full rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
      La modalidad «{elegida.nombre}» exige la fecha de fin prevista del vínculo.</p>}
    <Nota texto={nota} />
  </>
}

/** Proceso de selección (proceso CAS, concurso público, concurso docente…) y la fecha de su resultado. */
export function CampoProcesoSeleccion({ draft, poner, errors, opciones, nombre = 'vinculo' }) {
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
      <select name={`${nombre}-proceso`} value={valor} disabled={!opciones} onChange={event => elegir(event.target.value)}>
        <option value="">Seleccionar proceso de selección</option>
        {anterior && <option value="anterior">{anterior} (registrado antes)</option>}
        {procesos.map(p => <option key={p.id} value={p.id}>{p.nombre}{p.activo === false ? ' (desactivado)' : ''}</option>)}
      </select>
    </Campo>
    <Campo label="Fecha del resultado" error={errors.fecha_resultado_seleccion}><input name={`${nombre}-resultado`} type="date" max={hoy()} value={draft.fecha_resultado_seleccion || ''} onChange={event => poner('fecha_resultado_seleccion')(event.target.value)} /></Campo>
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
    {!regimen && <p className="col-span-full text-xs text-slate-500">Elige primero el tipo de vínculo: un designado no pasa por proceso de selección.</p>}
    {regimen === 'designado' && <p role="note" className="col-span-full rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-900">
      Un {regimenesLaborales.designado.toLowerCase()} no pasa por concurso: ingresa con su resolución de designación.</p>}
    {CON_PROCESO.includes(regimen) && <CampoProcesoSeleccion draft={draft} poner={poner} errors={errors} opciones={opciones} nombre={nombre} />}
    {numeroLegajo && <div className="grid min-w-0 content-start gap-1">
      <Campo label="N.° de legajo"><input name={`${nombre}-legajo`} value={numeroLegajo} readOnly className="bg-slate-50 font-semibold tabular-nums text-slate-700" /></Campo>
      <small className="text-xs font-normal text-slate-500">{nuevo ? 'Se asigna al registrar a la persona. Es uno por legajo.' : 'Número del legajo de la persona: todos sus vínculos lo comparten.'}</small>
    </div>}
    {!nuevo && draft.numero_documento_vinculo && <Campo label="N.° de documento (registrado antes)"><input value={draft.numero_documento_vinculo} readOnly className="bg-slate-50 text-slate-600" /></Campo>}
    <Campo label="Fecha de emisión" error={errors.documento_vinculo_fecha}><input name={`${nombre}-emision`} type="date" max={hoy()} value={draft.documento_vinculo_fecha || ''} onChange={event => poner('documento_vinculo_fecha')(event.target.value)} /></Campo>
  </>
}
