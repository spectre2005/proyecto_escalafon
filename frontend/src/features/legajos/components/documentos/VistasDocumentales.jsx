import { useId, useState } from 'react'
import Icon from '../../../../components/ui/Icon'
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
export default function VistasDocumentales({ elementos, clasificar, idDe = elemento => elemento.id, nombreDe = elemento => elemento.nombre, titulo = null, clasica = false, children }) {
  const grupos = repartir(elementos, clasificar)
  const [vista, setVista] = useState(() => vistaInicial(grupos))
  const [busqueda, setBusqueda] = useState('')
  const buscadorId = useId()
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
  const normalizar = valor => String(valor || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es')
  const disponibles = visiblesEnVista(elementos, clasificar, vista, retenidos, idDe)
  const texto = normalizar(busqueda.trim())
  const lista = disponibles.filter(elemento => normalizar(`${nombreDe(elemento)} ${elemento.codigo || ''}`).includes(texto))
  const retenido = elemento => retenidos.includes(String(idDe(elemento))) && clasificar(elemento) !== vista

  return <div>
    <div className={clasica ? '' : 'rounded-2xl border border-slate-200 bg-white p-4 sm:p-5'}>
    {titulo && <h3 className="mb-3 text-base font-semibold text-slate-800">{titulo}</h3>}
    <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
      {clasica && <p className="text-xs text-slate-500">Selecciona un tipo para consultar sus archivos o registrar documentos.</p>}
      <div className={clasica ? 'w-full sm:w-72' : 'w-full'}>
        <label htmlFor={buscadorId} className={clasica ? 'mb-1.5 block text-xs font-medium text-slate-600' : 'sr-only'}>Buscar tipo de documento</label>
        <div className="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 focus-within:border-teal-500 focus-within:ring-2 focus-within:ring-teal-100">
          <Icon name="search" size={17} />
          <input id={buscadorId} type="search" value={busqueda} onChange={event => setBusqueda(event.target.value)} placeholder={clasica ? 'Nombre o código…' : 'Buscar tipo de documento por nombre o código…'} className="min-w-0 flex-1 bg-transparent py-2.5 text-sm outline-none" />
        </div>
      </div>
    </div>
    <div role="tablist" aria-label="Vistas de documentos" className={clasica ? 'flex flex-wrap gap-1.5 border-b border-slate-200 pb-2' : 'flex flex-wrap gap-2'}>
      {VISTAS.map(({ clave, titulo: nombre }) => {
        const cantidad = grupos[clave].length
        const activa = clave === vista
        const alerta = clave === 'pendientes' && cantidad > 0
        return <button key={clave} type="button" role="tab" aria-selected={activa} onClick={() => elegir(clave)}
          className={`inline-flex items-center gap-2 rounded-lg px-3 ${clasica ? 'py-1.5' : 'py-2'} text-sm ${!clasica ? 'border border-slate-200 ' : ''}${activa ? 'bg-teal-700 font-semibold text-white' : cantidad ? 'text-slate-700 hover:bg-slate-100' : 'text-slate-400 hover:bg-slate-50'}`}>
          {nombre}
          {clasica && <span className={`rounded-full px-1.5 text-[11px] font-semibold ${activa ? 'bg-white/20 text-white' : alerta ? 'bg-amber-100 text-amber-900' : 'bg-slate-100 text-slate-500'}`}>{cantidad}</span>}
        </button>
      })}
    </div>
    <div className="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">{clasica && <p>{datos.ayuda}</p>}{texto && <p role="status">{lista.length} de {disponibles.length} tipos en esta vista</p>}</div>
    </div>
    {movido && <p role="status" className="mt-3 flex flex-wrap items-center gap-3 rounded-lg bg-teal-50 px-3 py-2 text-sm text-teal-800">
      <span>«{movido.nombre}» pasó a {VISTAS.find(item => item.clave === movido.vista).titulo}.</span>
      <button type="button" onClick={() => elegir(movido.vista)} className="font-semibold underline">Ver</button>
    </p>}
    <div role="tabpanel" aria-label={datos.titulo} className="mt-3">
      {lista.length ? children(lista, vista, retenido) : <div className="rounded-xl border border-dashed border-slate-200 bg-white p-6 text-sm text-slate-500"><p>{texto ? 'No hay coincidencias en esta vista. Prueba con otro nombre o cambia de pestaña.' : datos.vacio}</p>{texto && <button type="button" onClick={() => setBusqueda('')} className="mt-3 font-semibold text-teal-700">Limpiar búsqueda</button>}</div>}
    </div>
  </div>
}
