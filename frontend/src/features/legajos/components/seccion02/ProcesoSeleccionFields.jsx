import { useState } from 'react'
import { useLiveQuery } from '../../../../shared/queries/useLiveQuery'
import Campo from '../seccion01/Campo'
import { rotulosIngreso } from '../../config/ingresoLaboral'
import { hoy } from '../seccion01/useGuardar'

export default function ProcesoSeleccionFields({ draft, set, poner, errors, regimenId, required = false, tipoPersonal, codigoLey }) {
  return <>
    {regimenId && <SelectorProceso draft={draft} poner={poner} errors={errors} regimenId={regimenId} />}
    <Campo label="Código oficial del proceso" required={required || !!draft.anio_proceso} error={errors.proceso_seleccion}><input name="proceso_seleccion" required={required || !!draft.anio_proceso} maxLength={100} readOnly={!!draft.proceso_seleccion_id} placeholder={rotulosIngreso(tipoPersonal, null, codigoLey).proceso} value={draft.proceso_seleccion} onChange={set('proceso_seleccion')} /></Campo>
    <Campo label="Año del proceso" required={required} error={errors.anio_proceso}><input name="anio_proceso" required={required} type="number" min="1900" max="2100" step="1" readOnly={!!draft.proceso_seleccion_id} placeholder="Ej.: 2026" value={draft.anio_proceso} onChange={set('anio_proceso')} /></Campo>
    <Campo label="Fecha del resultado de selección" error={errors.fecha_resultado_seleccion}><input type="date" max={hoy()} value={draft.fecha_resultado_seleccion} onChange={set('fecha_resultado_seleccion')} /></Campo>
  </>
}

function SelectorProceso({ draft, poner, errors, regimenId }) {
  const [busqueda, setBusqueda] = useState('')
  const consulta = useLiveQuery(`/procesos-seleccion?${new URLSearchParams({ regimen_normativo_id: regimenId, q: busqueda })}`)
  const procesos = consulta.data?.data || []
  function elegir(id) {
    const seleccionado = procesos.find(proceso => proceso.id === id)
    poner('proceso_seleccion_id')(id)
    poner('proceso_seleccion')(seleccionado?.codigo || '')
    poner('anio_proceso')(seleccionado?.anio || '')
  }
  return <div className="col-span-full grid gap-3 rounded-xl border border-teal-100 bg-teal-50/50 p-4 sm:grid-cols-2">
    <Campo label="Buscar convocatoria registrada"><input type="search" placeholder="Buscar por código" value={busqueda} maxLength={100} onChange={event => setBusqueda(event.target.value)} /></Campo>
    <Campo label="Convocatoria de esta institución" error={errors.proceso_seleccion_id}><select name="proceso_seleccion_id" value={draft.proceso_seleccion_id || ''} onChange={event => elegir(event.target.value)}>
      <option value="">Registrar código oficial de otra convocatoria</option>
      {draft.proceso_seleccion_id && !procesos.some(proceso => proceso.id === draft.proceso_seleccion_id) && <option value={draft.proceso_seleccion_id}>{draft.proceso_seleccion} · {draft.anio_proceso}</option>}
      {procesos.map(proceso => <option key={proceso.id} value={proceso.id}>{proceso.codigo} · {proceso.anio}</option>)}
    </select></Campo>
    <p className="col-span-full text-[11px] leading-relaxed text-teal-900">Selecciona una convocatoria existente o copia su código y año oficiales abajo. Al guardar se reutiliza la misma convocatoria para las personas que ingresen por ella.</p>
    {consulta.loading && <p role="status" className="col-span-full text-xs text-slate-500">Cargando convocatorias…</p>}
    {consulta.error && <p role="alert" className="col-span-full text-xs text-amber-800">No se pudo cargar el catálogo. Puedes registrar el código oficial. <button type="button" className="underline" onClick={consulta.retry}>Reintentar</button></p>}
  </div>
}
