import TablaTiposDocumentales from './TablaTiposDocumentales'
import { useState } from 'react'
import { cambiosDeVista, repartir, VISTAS, vistaInicial, visiblesEnVista } from '../../config/vistasDocumentales'

/**
 * Documentos de una sección organizados por lo que piden al usuario: Pendientes · Con documentos · Opcionales · No
 * corresponde (config/vistasDocumentales.js). Cada tipo aparece en una sola vista; la sección se abre en la primera con
 * algo que hacer. Al cargar un documento, si su tipo cambia de vista, se avisa a cuál pasó y se puede ir a verlo.
 *
 * elementos: tipos (o ítems) de la sección. clasificar(elemento): su vista. idDe / nombreDe: para avisar los cambios.
 * children(lista, vista, retenido): cómo pinta la sección esa lista (filas, <details> o tabla): cada sección conserva su
 * forma. Un tipo que sale de la vista mientras se mira (p. ej. al cargar su documento) se queda visible hasta cambiar de
 * vista: su paso en curso (completar datos, enlazar) sigue abierto; retenido(elemento) lo indica.
 */
export default function VistasDocumentales({ elementos, clasificar, idDe = elemento => elemento.id, nombreDe = elemento => elemento.nombre, titulo = null, children, tabla = true, onSubir, puedeSubir }) {
  const grupos = repartir(elementos, clasificar)
  const [vista, setVista] = useState(() => vistaInicial(grupos))
  // Vista de cada tipo en el render anterior, para avisar lo que cambió tras una carga (ajuste de estado en el render).
  const actual = Object.fromEntries((elementos || []).map(elemento => [idDe(elemento), clasificar(elemento)]))
  const [previo, setPrevio] = useState(actual)
  const [movido, setMovido] = useState(null)
  const [retenidos, setRetenidos] = useState([])
  if (JSON.stringify(previo) !== JSON.stringify(actual)) {
    const cambios = cambiosDeVista(previo, actual)
    const cambio = cambios.find(item => item.a !== vista)
    const salen = cambios.filter(item => item.de === vista).map(item => String(item.id))
    setPrevio(actual)
    if (salen.length) setRetenidos(previos => [...new Set([...previos, ...salen])])
    if (cambio) setMovido({ nombre: nombreDe((elementos || []).find(elemento => String(idDe(elemento)) === String(cambio.id))), vista: cambio.a })
  }
  const elegir = clave => { setVista(clave); setMovido(null); setRetenidos([]) }
  const datos = VISTAS.find(item => item.clave === vista)
  const lista = visiblesEnVista(elementos, clasificar, vista, retenidos, idDe)
  const retenido = elemento => retenidos.includes(String(idDe(elemento))) && clasificar(elemento) !== vista

  return <div>
    {titulo && <h3 className="mb-3 text-base font-semibold text-slate-800">{titulo}</h3>}
    <div role="group" aria-label="Vistas de documentos" className="flex flex-wrap gap-1.5 border-b border-slate-200 pb-2">
      {VISTAS.map(({ clave, titulo: nombre }) => {
        const cantidad = grupos[clave].length
        const activa = clave === vista
        const alerta = clave === 'pendientes' && cantidad > 0
        return <button key={clave} type="button" aria-pressed={activa} onClick={() => elegir(clave)}
          className={`inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm ${activa ? 'bg-teal-700 font-semibold text-white' : cantidad ? 'text-slate-700 hover:bg-slate-100' : 'text-slate-400 hover:bg-slate-50'}`}>
          {nombre}
          <span className={`rounded-full px-1.5 text-[11px] font-semibold ${activa ? 'bg-white/20 text-white' : alerta ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-500'}`}>{cantidad}</span>
        </button>
      })}
    </div>
    <p className="mt-2 text-xs text-slate-500">{datos.ayuda}</p>
    {movido && <p role="status" className="mt-3 flex flex-wrap items-center gap-3 rounded-lg bg-teal-50 px-3 py-2 text-sm text-teal-800">
      <span>«{movido.nombre}» pasó a {VISTAS.find(item => item.clave === movido.vista).titulo}.</span>
      <button type="button" onClick={() => elegir(movido.vista)} className="font-semibold underline">Ver</button>
    </p>}
    <div role="region" aria-label={datos.titulo} className="mt-3">
      {lista.length ? tabla ? <TablaTiposDocumentales key={vista} elementos={lista} idDe={idDe} nombreDe={nombreDe} onSubir={onSubir} puedeSubir={puedeSubir} renderDetalle={tipo => children([tipo], vista, retenido)} /> : children(lista, vista, retenido) : <p className="rounded-xl border border-dashed border-slate-200 bg-white p-5 text-sm text-slate-500">{datos.vacio}</p>}
    </div>
  </div>
}
