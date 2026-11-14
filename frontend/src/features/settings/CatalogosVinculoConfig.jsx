import { useState } from 'react'
import { apiErrorMessage } from '../../axios'
import { usePuede } from '../auth/session'
import { actualizarElementoCatalogo } from '../legajos/api/catalogosApi'
import FormularioCatalogo from '../legajos/components/seccion02/FormularioCatalogo'
import PanelCatalogo from '../legajos/components/seccion02/PanelCatalogo'
import { NOMBRES_CATALOGO, normalizar, rutaUnidad } from '../legajos/config/catalogosVinculo'
import { gruposOcupacionales, regimenesLaborales } from '../legajos/config/seccion02'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'

// Catálogos del vínculo en Configuración: lo que se crea desde el registro de personal también se revisa, corrige y
// desactiva aquí. Nada se borra: un elemento desactivado deja de ofrecerse, pero los vínculos ya registrados lo conservan.

const PESTANAS = [
  { id: 'unidades', titulo: 'Unidades orgánicas' },
  { id: 'puestos', titulo: 'Puestos' },
  { id: 'tipos_vinculo', titulo: 'Tipos de vínculo' },
  { id: 'modalidades', titulo: 'Modalidades de vínculo' },
  { id: 'procesos', titulo: 'Procesos de selección' },
]
const tiposPersonal = { docente: 'Docente', administrativo: 'Administrativo' }
const colorRegimen = { carrera: 'bg-emerald-50 text-emerald-800', plazo_determinado: 'bg-sky-50 text-sky-800', designado: 'bg-amber-50 text-amber-800' }
const boton = 'rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-50'
const sino = valor => valor === true ? 'Sí' : valor === false ? 'No' : '—'

// Columnas de cada catálogo: [encabezado, valor de la fila].
function columnas(catalogo, datos) {
  const ley = id => datos.regimenes?.find(item => item.id === id)?.nombre
  const unidad = id => rutaUnidad(datos.unidades, id).slice(-1)[0]
  return {
    unidades: [
      ['Tipo', item => datos.tipos_unidad?.[item.tipo] || item.tipo],
      ['Depende de', item => rutaUnidad(datos.unidades, item.unidad_padre_id).join(' › ') || '—'],
    ],
    puestos: [
      ['Personal', item => tiposPersonal[item.tipo_personal]],
      ['Unidad', item => unidad(item.unidad_organica_id) || 'Cualquiera'],
      ['Condiciones', item => [item.grupo_ocupacional && gruposOcupacionales[item.grupo_ocupacional], item.es_cargo_confianza && 'Confianza',
        `DJBR ${sino(item.obligado_djbr)}`, `Colegiatura ${sino(item.exige_colegiatura)}`, `Prueba ${sino(item.tiene_periodo_prueba)}`].filter(Boolean).join(' · ')],
    ],
    modalidades: [
      ['Tipo de vínculo', item => <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${colorRegimen[item.regimen_laboral]}`}>{regimenesLaborales[item.regimen_laboral]}</span>],
      ['Para', item => item.tipo_personal ? `${tiposPersonal[item.tipo_personal]}s` : 'Todos'],
      ['Ley', item => ley(item.regimen_normativo_id) || '—'],
      ['Fin previsto', item => item.exige_fin_previsto ? 'Obligatorio' : 'Opcional'],
    ],
    tipos_vinculo: [
      ['Descripción', item => item.descripcion || '—'],
      ['Modalidades', item => (datos.modalidades || []).filter(m => m.regimen_laboral === item.codigo && m.activo !== false).map(m => m.nombre).join(', ') || '—'],
    ],
    procesos: [
      ['Se usa en', () => 'Carrera y plazo determinado'],
    ],
  }[catalogo]
}

export default function CatalogosVinculoConfig() {
  const consulta = useLiveQuery('/catalogos-vinculo')
  const datos = consulta.data?.data
  const puedeEditar = usePuede('catalogos.gestionar')
  const [pestana, setPestana] = useState('unidades')
  const [busqueda, setBusqueda] = useState('')
  const [verInactivos, setVerInactivos] = useState(false)
  const [formulario, setFormulario] = useState(null)
  const [aviso, setAviso] = useState('')
  const [error, setError] = useState('')
  const [ocupado, setOcupado] = useState('')
  const nombres = NOMBRES_CATALOGO[pestana]
  const texto = item => item.nombre
  const buscado = normalizar(busqueda)
  const lista = (datos?.[pestana] || [])
    .filter(item => (verInactivos || item.activo !== false) && (!buscado || normalizar(`${texto(item)} ${item.codigo}`).includes(buscado)))
    .sort((a, b) => (a.activo === false) - (b.activo === false) || texto(a).localeCompare(texto(b), 'es'))
  const inactivos = datos ? datos[pestana].filter(item => item.activo === false).length : 0

  function cambiarPestana(id) {
    setPestana(id)
    setFormulario(null)
    setBusqueda('')
    setAviso('')
    setError('')
  }
  async function alternarActivo(item) {
    setOcupado(item.id)
    setAviso('')
    setError('')
    try {
      await actualizarElementoCatalogo(pestana, item.id, { activo: item.activo === false })
      setAviso(item.activo === false ? `«${texto(item)}» vuelve a estar disponible.` : `«${texto(item)}» quedó desactivado. Los vínculos que ya lo usan lo conservan.`)
      consulta.retry()
    } catch (fallo) {
      setError(apiErrorMessage(fallo))
    } finally {
      setOcupado('')
    }
  }

  return <section aria-label="Catálogos del vínculo" className="rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
    <div className="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h2 className="font-semibold text-slate-800">Catálogos del vínculo</h2>
        <p className="mt-2 max-w-2xl text-sm text-slate-500">Lo que se elige al registrar personal y en la sección 02. Lo creado desde el registro aparece aquí. Nada se elimina: se desactiva y los vínculos ya registrados conservan su valor.</p>
      </div>
      {puedeEditar && !formulario && pestana !== 'tipos_vinculo' && <button type="button" onClick={() => setFormulario({ registro: null })} className="rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white">+ {nombres.nuevo}</button>}
    </div>

    <div role="tablist" aria-label="Catálogo" className="mt-6 flex flex-wrap gap-2">
      {PESTANAS.map(item => <button key={item.id} id={`catalogo-${item.id}`} type="button" role="tab" aria-selected={pestana === item.id} onClick={() => cambiarPestana(item.id)}
        className={`rounded-full px-3.5 py-1.5 text-xs font-semibold transition ${pestana === item.id ? 'bg-teal-700 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}>
        {item.titulo}{datos && <span className={`ml-1.5 tabular-nums ${pestana === item.id ? 'text-teal-100' : 'text-slate-400'}`}>{datos[item.id].filter(e => e.activo !== false).length}</span>}
      </button>)}
    </div>

    {pestana === 'tipos_vinculo' && <div role="note" className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-900">
      <p className="max-w-2xl">Los tres tipos vienen de la directiva y de ellos depende qué documentos se exigen: su nombre no cambia, pero puedes editar su descripción. Para agregar otro tipo (por ejemplo, suplencia o encargatura), créalo como modalidad dentro de uno de ellos.</p>
      {puedeEditar && <button type="button" onClick={() => { cambiarPestana('modalidades'); setFormulario({ registro: null }) }} className="rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white">+ Nueva modalidad</button>}
    </div>}
    {!puedeEditar && <p className="mt-4 rounded-xl bg-slate-50 p-3 text-xs text-slate-600">Puedes consultar los catálogos. Para crearlos o corregirlos se necesita el permiso «catalogos.gestionar».</p>}
    {aviso && <p role="status" className="mt-4 rounded-xl bg-teal-50 p-3 text-sm text-teal-800">{aviso}</p>}
    {error && <p role="alert" className="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
    {consulta.error && <p role="alert" className="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">No se pudieron cargar los catálogos. <button type="button" className="underline" onClick={consulta.retry}>Reintentar</button></p>}

    {/* «Nuevo» y «Editar» se abren en un panel encima de la página, sin ocupar espacio en la lista. */}
    {formulario && datos && <PanelCatalogo titulo={formulario.registro ? nombres.editar : nombres.nuevo} onCerrar={() => setFormulario(null)}>
      <FormularioCatalogo key={`${pestana}-${formulario.registro?.id || 'nuevo'}`} catalogo={pestana} registro={formulario.registro} opciones={datos}
        onCancel={() => setFormulario(null)}
        onGuardado={item => { setFormulario(null); setAviso(formulario.registro ? `Se guardaron los cambios de «${texto(item)}».` : `Se creó «${texto(item)}» con el código ${item.codigo}.`); consulta.retry() }} />
    </PanelCatalogo>}

    <div className="mt-5 flex flex-wrap items-center justify-between gap-3">
      <label className="min-w-0 flex-1 sm:max-w-sm" role="search">
        <span className="sr-only">Buscar en {PESTANAS.find(p => p.id === pestana).titulo.toLowerCase()}</span>
        <input type="search" value={busqueda} onChange={event => setBusqueda(event.target.value)} placeholder="Buscar por nombre o código"
          className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-teal-600" />
      </label>
      {inactivos > 0 && <label className="flex items-center gap-2 text-xs text-slate-600"><input type="checkbox" className="size-4 accent-teal-700" checked={verInactivos} onChange={event => setVerInactivos(event.target.checked)} />Mostrar desactivados ({inactivos})</label>}
    </div>

    {!datos && !consulta.error && <p role="status" className="mt-4 text-sm text-slate-500">Cargando catálogos…</p>}
    {datos && <div className="mt-4 overflow-x-auto rounded-xl border border-slate-200">
      <table className="w-full min-w-[640px] text-left text-sm" aria-labelledby={`catalogo-${pestana}`}>
        <thead className="bg-slate-50 text-[11px] uppercase tracking-wider text-slate-500">
          <tr>
            <th scope="col" className="px-4 py-2.5 font-semibold">Nombre</th>
            {columnas(pestana, datos).map(([encabezado]) => <th key={encabezado} scope="col" className="px-4 py-2.5 font-semibold">{encabezado}</th>)}
            <th scope="col" className="px-4 py-2.5 text-right font-semibold">Personas</th>
            {puedeEditar && <th scope="col" className="px-4 py-2.5"><span className="sr-only">Acciones</span></th>}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {lista.length === 0 && <tr><td colSpan={10} className="px-4 py-6 text-center text-sm text-slate-500">
            {busqueda ? 'No hay resultados para esa búsqueda.' : `Todavía no hay ${PESTANAS.find(p => p.id === pestana).titulo.toLowerCase()}.${puedeEditar ? ' Créalos aquí o desde el registro de personal.' : ''}`}
          </td></tr>}
          {lista.map(item => <tr key={item.id} className={item.activo === false ? 'bg-slate-50/70 text-slate-400' : 'text-slate-700'}>
            <th scope="row" className="px-4 py-3 font-medium">
              <span className={item.activo === false ? '' : 'text-slate-800'}>{texto(item)}</span>
              <span className="block text-[11px] font-normal tabular-nums text-slate-400">{item.codigo}{item.activo === false ? ' · desactivado' : ''}</span>
            </th>
            {columnas(pestana, datos).map(([encabezado, valor]) => <td key={encabezado} className="px-4 py-3 text-xs">{valor(item)}</td>)}
            <td className="px-4 py-3 text-right tabular-nums">{item.personas}</td>
            {puedeEditar && <td className="px-4 py-3"><div className="flex justify-end gap-2">
              {item.activo !== false && <button type="button" className={boton} onClick={() => setFormulario({ registro: item })} aria-label={`Editar ${texto(item)}`}>Editar</button>}
              {pestana !== 'tipos_vinculo' && <button type="button" className={boton} disabled={ocupado === item.id} onClick={() => alternarActivo(item)}>{item.activo === false ? 'Reactivar' : 'Desactivar'}</button>}
            </div></td>}
          </tr>)}
        </tbody>
      </table>
    </div>}
  </section>
}
