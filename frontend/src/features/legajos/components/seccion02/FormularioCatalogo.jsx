import { useState } from 'react'
import TresEstados from '../../../../components/ui/TresEstados'
import { apiErrorMessage } from '../../../../axios'
import { actualizarElementoCatalogo, crearElementoCatalogo } from '../../api/catalogosApi'
import { NOMBRES_CATALOGO, opcionesUnidad, parecido } from '../../config/catalogosVinculo'
import { gruposOcupacionales, regimenesLaborales } from '../../config/seccion02'

const control = 'min-w-0 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-800 outline-none focus:ring-2 focus:ring-teal-600 disabled:bg-slate-50 disabled:text-slate-500'
const tiposPersonal = { docente: 'Docente', administrativo: 'Administrativo' }

// Valores iniciales de cada catálogo; «contexto» trae lo que ya se eligió en el vínculo (tipo de personal, unidad…).
function inicial(catalogo, registro, contexto) {
  const r = registro || {}
  return {
    unidades: { nombre: r.nombre || contexto.texto || '', tipo: r.tipo || 'unidad', unidad_padre_id: r.unidad_padre_id || '' },
    puestos: { nombre: r.nombre || contexto.texto || '', tipo_personal: r.tipo_personal || contexto.tipoPersonal || '', unidad_organica_id: r.unidad_organica_id ?? contexto.unidadId ?? '',
      grupo_ocupacional: r.grupo_ocupacional || '', es_cargo_confianza: !!r.es_cargo_confianza, obligado_djbr: r.obligado_djbr ?? null, exige_colegiatura: r.exige_colegiatura ?? null, tiene_periodo_prueba: r.tiene_periodo_prueba ?? null },
    modalidades: { nombre: r.nombre || contexto.texto || '', regimen_laboral: r.regimen_laboral || contexto.regimen || '', tipo_personal: r.tipo_personal ?? contexto.tipoPersonal ?? '',
      regimen_normativo_id: r.regimen_normativo_id || '', tiene_periodo_prueba: r.tiene_periodo_prueba ?? null, exige_fin_previsto: !!r.exige_fin_previsto },
    procesos: { nombre: r.nombre || contexto.texto || '' },
    // De los tipos de vínculo solo se edita la descripción.
    tipos_vinculo: { descripcion: r.descripcion || '' },
  }[catalogo]
}

const vacioANull = datos => Object.fromEntries(Object.entries(datos).map(([clave, valor]) => [clave, typeof valor === 'string' ? valor.trim() || null : valor]))

function Campo({ label, required, error, children, className = '' }) {
  return <label className={`grid min-w-0 content-start gap-2 text-xs font-medium text-slate-600 ${className}`}>
    {label}{required ? ' *' : ''}
    {children}
    {error && <span className="text-rose-700">{[].concat(error).join(' ')}</span>}
  </label>
}

/**
 * Crear (o corregir, con «registro») un elemento de un catálogo del vínculo. Se muestra dentro del formulario que lo usa,
 * así que no es un <form>: Enter guarda sin enviar el formulario de fuera. onGuardado recibe el elemento guardado.
 */
export default function FormularioCatalogo({ catalogo, registro = null, contexto = {}, opciones = {}, onGuardado, onCancel, compacto = false }) {
  const [draft, setDraft] = useState(() => inicial(catalogo, registro, contexto))
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [errors, setErrors] = useState({})
  const poner = clave => valor => setDraft(previo => ({ ...previo, [clave]: valor }))
  const set = clave => event => poner(clave)(event.target.type === 'checkbox' ? event.target.checked : event.target.value)
  const nombres = NOMBRES_CATALOGO[catalogo]
  const lista = Array.isArray(opciones[catalogo]) ? opciones[catalogo] : []
  const similar = !registro && catalogo !== 'tipos_vinculo' && parecido(lista.filter(item => catalogo !== 'puestos' || item.tipo_personal === draft.tipo_personal), draft.nombre)

  async function guardar() {
    if (busy) return
    setBusy(true)
    setError('')
    setErrors({})
    try {
      const datos = vacioANull(draft)
      const respuesta = registro ? await actualizarElementoCatalogo(catalogo, registro.id, datos) : await crearElementoCatalogo(catalogo, datos)
      onGuardado?.(respuesta.data.data)
    } catch (fallo) {
      setErrors(fallo.response?.data?.errors || {})
      setError(apiErrorMessage(fallo))
    } finally {
      setBusy(false)
    }
  }
  function teclado(event) {
    if (event.key === 'Enter' && event.target.tagName === 'INPUT') {
      event.preventDefault()
      event.stopPropagation()
      guardar()
    }
    if (event.key === 'Escape') {
      event.stopPropagation()
      onCancel?.()
    }
  }

  const leyes = (opciones.regimenes || []).filter(ley => ley.activo !== false && (!draft.tipo_personal || ley.aplica_a === draft.tipo_personal))
  const unidades = opcionesUnidad(opciones.unidades, catalogo === 'unidades' ? draft.unidad_padre_id : draft.unidad_organica_id).filter(u => !(catalogo === 'unidades' && registro && u.id === registro.id))

  return <div role="group" aria-label={registro ? nombres.editar : nombres.nuevo} onKeyDown={teclado}
    className={`col-span-full grid gap-4 rounded-2xl border border-teal-200 bg-white p-4 shadow-sm ${compacto ? '' : 'sm:p-5'}`}>
    <div className="flex flex-wrap items-baseline justify-between gap-2">
      <p className="text-sm font-semibold text-slate-800">{registro ? `${nombres.editar}${registro.codigo && catalogo !== 'tipos_vinculo' ? ` · ${registro.codigo}` : ''}` : nombres.nuevo}</p>
      {!registro && <p className="text-[11px] text-slate-500">El código se asigna solo al guardar.</p>}
    </div>
    {error && <p role="alert" className="rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700">{error}</p>}
    <fieldset disabled={busy} className="grid min-w-0 gap-4 sm:grid-cols-2">
      {catalogo === 'unidades' && <>
        <Campo label="Nombre" required error={errors.nombre} className="sm:col-span-2"><input className={control} maxLength={150} value={draft.nombre} onChange={set('nombre')} autoFocus /></Campo>
        <Campo label="Tipo" required error={errors.tipo}><select className={control} value={draft.tipo} onChange={set('tipo')}>
          {Object.entries(opciones.tipos_unidad || {}).map(([valor, texto]) => <option key={valor} value={valor}>{texto}</option>)}
          {draft.tipo && !(draft.tipo in (opciones.tipos_unidad || {})) && <option value={draft.tipo}>{draft.tipo}</option>}
        </select></Campo>
        <Campo label="Depende de" error={errors.unidad_padre_id}><select className={control} value={draft.unidad_padre_id} onChange={set('unidad_padre_id')}>
          <option value="">No depende de otra unidad</option>
          {unidades.map(u => <option key={u.id} value={u.id}>{u.ruta.join(' › ')}</option>)}
        </select></Campo>
      </>}

      {catalogo === 'puestos' && <>
        <Campo label="Denominación del puesto" required error={errors.nombre} className="sm:col-span-2"><input className={control} maxLength={150} value={draft.nombre} onChange={set('nombre')} autoFocus /></Campo>
        <Campo label="Tipo de personal" required error={errors.tipo_personal}><select className={control} value={draft.tipo_personal} onChange={set('tipo_personal')} disabled={!!contexto.tipoPersonal && !registro}>
          <option value="">Selecciona</option>{Object.entries(tiposPersonal).map(([valor, texto]) => <option key={valor} value={valor}>{texto}</option>)}
        </select></Campo>
        <Campo label="Unidad orgánica" error={errors.unidad_organica_id}><select className={control} value={draft.unidad_organica_id} onChange={set('unidad_organica_id')}>
          <option value="">Cualquier unidad</option>
          {unidades.map(u => <option key={u.id} value={u.id}>{u.ruta.join(' › ')}</option>)}
        </select></Campo>
        {draft.tipo_personal === 'administrativo' && <>
          <Campo label="Grupo ocupacional" error={errors.grupo_ocupacional}><select className={control} value={draft.grupo_ocupacional} onChange={set('grupo_ocupacional')}>
            <option value="">Sin indicar</option>{Object.entries(gruposOcupacionales).map(([valor, texto]) => <option key={valor} value={valor}>{texto}</option>)}
          </select></Campo>
          <label className="flex items-center gap-2 self-end pb-2.5 text-sm text-slate-700"><input type="checkbox" className="size-4 accent-teal-700" checked={draft.es_cargo_confianza} onChange={set('es_cargo_confianza')} />Es cargo de confianza</label>
        </>}
        <div className="grid gap-3 rounded-xl border border-slate-100 bg-slate-50/60 p-3 sm:col-span-2 sm:grid-cols-3">
          <p className="text-[11px] text-slate-500 sm:col-span-3">Condiciones que se proponen al elegir este puesto. Solo completan lo que siga «Sin responder».</p>
          <TresEstados name="catalogo-djbr" label="DJBR" value={draft.obligado_djbr} onChange={poner('obligado_djbr')} error={errors.obligado_djbr} />
          <TresEstados name="catalogo-colegiatura" label="Exige colegiatura" value={draft.exige_colegiatura} onChange={poner('exige_colegiatura')} error={errors.exige_colegiatura} />
          <TresEstados name="catalogo-prueba" label="Período de prueba" value={draft.tiene_periodo_prueba} onChange={poner('tiene_periodo_prueba')} error={errors.tiene_periodo_prueba} />
        </div>
      </>}

      {catalogo === 'modalidades' && <>
        <Campo label="Nombre de la modalidad" required error={errors.nombre} className="sm:col-span-2"><input className={control} maxLength={120} placeholder="Ej.: Suplencia" value={draft.nombre} onChange={set('nombre')} autoFocus /></Campo>
        <Campo label="Tipo de vínculo" required error={errors.regimen_laboral}><select className={control} value={draft.regimen_laboral} onChange={set('regimen_laboral')} disabled={!!contexto.regimen && !registro}>
          <option value="">Selecciona</option>{Object.entries(regimenesLaborales).map(([valor, texto]) => <option key={valor} value={valor}>{texto}</option>)}
        </select></Campo>
        <Campo label="Para" error={errors.tipo_personal}><select className={control} value={draft.tipo_personal} onChange={event => { set('tipo_personal')(event); poner('regimen_normativo_id')('') }}>
          <option value="">Docentes y administrativos</option>{Object.entries(tiposPersonal).map(([valor, texto]) => <option key={valor} value={valor}>Solo {texto.toLowerCase()}s</option>)}
        </select></Campo>
        <Campo label="Ley que suele corresponder" error={errors.regimen_normativo_id}><select className={control} value={draft.regimen_normativo_id} onChange={set('regimen_normativo_id')}>
          <option value="">Sin indicar</option>{leyes.map(ley => <option key={ley.id} value={ley.id}>{ley.nombre}</option>)}
        </select></Campo>
        <label className="flex items-start gap-2 self-end pb-2.5 text-sm text-slate-700"><input type="checkbox" className="mt-0.5 size-4 accent-teal-700" checked={draft.exige_fin_previsto} onChange={set('exige_fin_previsto')} />Exige fecha de fin prevista</label>
        <div className="sm:col-span-2"><TresEstados name="catalogo-modalidad-prueba" label="¿Suele tener período de prueba?" ayuda="Se propone al elegir la modalidad si el vínculo aún no lo indica." value={draft.tiene_periodo_prueba} onChange={poner('tiene_periodo_prueba')} error={errors.tiene_periodo_prueba} /></div>
      </>}

      {catalogo === 'tipos_vinculo' && <>
        <Campo label="Tipo de vínculo"><input className={control} value={registro?.nombre || ''} readOnly disabled /></Campo>
        <p className="self-end pb-2.5 text-[11px] text-slate-500">El nombre viene de la directiva y no cambia: de él depende qué documentos se exigen.</p>
        <Campo label="Descripción" error={errors.descripcion} className="sm:col-span-2"><textarea className={control} rows={3} maxLength={300} value={draft.descripcion} onChange={set('descripcion')} autoFocus
          placeholder="Qué personal abarca este tipo. Se muestra como ayuda al elegirlo." /></Campo>
      </>}

      {catalogo === 'procesos' && <Campo label="Nombre del proceso de selección" required error={errors.nombre} className="sm:col-span-2"><input className={control} maxLength={120} placeholder="Ej.: Concurso interno de méritos" value={draft.nombre} onChange={set('nombre')} autoFocus /></Campo>}
    </fieldset>
    {similar && <p role="note" className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
      Ya existe «{similar.nombre}», que es casi igual. Revisa que no sea {nombres.masculino ? 'el mismo' : 'la misma'} antes de crear {nombres.masculino ? 'otro' : 'otra'}.
    </p>}
    <div className="flex flex-wrap justify-end gap-2">
      <button type="button" onClick={onCancel} disabled={busy} className="rounded-xl border border-slate-200 px-4 py-2 text-sm text-slate-700">Cancelar</button>
      <button type="button" onClick={guardar} disabled={busy} className="rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
        {busy ? 'Guardando…' : registro ? 'Guardar cambios' : contexto.usar ? 'Crear y usar' : 'Crear'}
      </button>
    </div>
  </div>
}

