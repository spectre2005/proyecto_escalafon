import { useEffect, useId, useRef, useState } from 'react'
import Icon from '../../components/ui/Icon'
import { apiErrorMessage } from '../../axios'
import { normalizar } from '../legajos/config/catalogosVinculo'
import { actualizarEnCatalogo, consultarCatalogo, crearEnCatalogo } from './catalogosVinculoApi'

// Listas de puestos y unidades orgánicas de la institución. Empiezan vacías y se llenan solas con lo que se registra en
// los vínculos (alta de personal y sección 02); aquí el administrador agrega, corrige nombres y desactiva. Nunca se
// borra: los vínculos conservan su puesto y su unidad aunque se renombren o desactiven.

const LISTAS = {
  puestos: { titulo: 'Puestos', singular: 'puesto', vacio: 'Aún no hay puestos. Se agregan solos al registrar vínculos, o puedes agregarlos aquí.' },
  'unidades-organicas': { titulo: 'Unidades orgánicas', singular: 'unidad orgánica', vacio: 'Aún no hay unidades orgánicas. Se agregan solas al registrar vínculos, o puedes agregarlas aquí.' },
}
const tiposPersonal = { docente: 'Docente', administrativo: 'Administrativo' }
const control = 'rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 outline-none focus:border-teal-500'

function Editar({ tipo, item, unidades, guardar, cerrar }) {
  const dialogo = useRef(null)
  const titulo = useId()
  const [draft, setDraft] = useState({ nombre: item.nombre, codigo: item.codigo || '', tipo: item.tipo || '', unidad_padre_id: item.unidad_padre_id || '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const esUnidad = tipo === 'unidades-organicas'
  useEffect(() => { dialogo.current.showModal() }, [])
  const set = key => event => setDraft(previo => ({ ...previo, [key]: event.target.value }))
  async function enviar(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    try {
      await guardar(item.id, esUnidad ? { nombre: draft.nombre, codigo: draft.codigo.trim() || null, tipo: draft.tipo.trim() || null, unidad_padre_id: draft.unidad_padre_id || null } : { nombre: draft.nombre })
      cerrar()
    } catch (failure) {
      setError(apiErrorMessage(failure))
      setBusy(false)
    }
  }
  return <dialog ref={dialogo} aria-labelledby={titulo} onCancel={event => { event.preventDefault(); if (!busy) cerrar() }} className="m-auto w-[calc(100%_-_2rem)] max-w-lg rounded-2xl p-0 text-slate-700 shadow-xl backdrop:bg-slate-900/35">
    <form onSubmit={enviar} aria-busy={busy} className="space-y-4 p-6">
      <h3 id={titulo} className="text-lg font-semibold">Corregir {LISTAS[tipo].singular}</h3>
      <p className="text-xs leading-relaxed text-slate-500">{esUnidad ? 'Los vínculos con esta unidad mostrarán el nombre corregido.' : 'Los vínculos ya registrados conservan el nombre con el que se registraron.'} El cambio queda en la auditoría.</p>
      <label className="grid gap-1.5 text-xs font-medium text-slate-500">Nombre *<input required maxLength={150} value={draft.nombre} onChange={set('nombre')} className={control} /></label>
      {esUnidad && <div className="grid gap-4 sm:grid-cols-2">
        <label className="grid gap-1.5 text-xs font-medium text-slate-500">Código<input maxLength={20} value={draft.codigo} onChange={set('codigo')} className={control} /></label>
        <label className="grid gap-1.5 text-xs font-medium text-slate-500">Tipo<input maxLength={30} placeholder="Ej.: oficina, dirección" value={draft.tipo} onChange={set('tipo')} className={control} /></label>
        <label className="grid gap-1.5 text-xs font-medium text-slate-500 sm:col-span-2">Depende de<select value={draft.unidad_padre_id} onChange={set('unidad_padre_id')} className={control}>
          <option value="">Ninguna</option>
          {unidades.filter(unidad => unidad.id !== item.id).map(unidad => <option key={unidad.id} value={unidad.id}>{unidad.nombre}{unidad.activo ? '' : ' (desactivada)'}</option>)}
        </select></label>
      </div>}
      {error && <p role="alert" className="rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
      <div className="flex justify-end gap-3"><button type="button" disabled={busy} onClick={cerrar} className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm">Cancelar</button><button type="submit" disabled={busy} className="rounded-xl bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50">{busy ? 'Guardando…' : 'Guardar'}</button></div>
    </form>
  </dialog>
}

function Agregar({ tipo, crear }) {
  const [nombre, setNombre] = useState('')
  const [tipoPersonal, setTipoPersonal] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const esPuesto = tipo === 'puestos'
  async function enviar(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    try {
      await crear(esPuesto ? { nombre, tipo_personal: tipoPersonal } : { nombre })
      setNombre('')
    } catch (failure) {
      setError(apiErrorMessage(failure))
    }
    setBusy(false)
  }
  return <form onSubmit={enviar} aria-busy={busy} className="mb-4 rounded-xl border border-slate-200 bg-slate-50/60 p-4">
    <div className="flex flex-wrap items-end gap-3">
      <label className="grid min-w-0 flex-1 basis-56 gap-1.5 text-xs font-medium text-slate-500">Agregar {LISTAS[tipo].singular}<input required maxLength={150} value={nombre} onChange={event => setNombre(event.target.value)} className={control} /></label>
      {esPuesto && <label className="grid gap-1.5 text-xs font-medium text-slate-500">Tipo de personal<select required value={tipoPersonal} onChange={event => setTipoPersonal(event.target.value)} className={control}>
        <option value="">Selecciona</option>{Object.entries(tiposPersonal).map(([valor, texto]) => <option key={valor} value={valor}>{texto}</option>)}
      </select></label>}
      <button type="submit" disabled={busy} className="inline-flex items-center gap-2 rounded-xl bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"><Icon name="plus" size={16} />{busy ? 'Agregando…' : 'Agregar'}</button>
    </div>
    {error && <p role="alert" className="mt-3 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error}</p>}
  </form>
}

export default function CatalogosVinculoConfig({ consultar = consultarCatalogo, crear = crearEnCatalogo, actualizar = actualizarEnCatalogo }) {
  const [tipo, setTipo] = useState('puestos')
  const [datos, setDatos] = useState(null)
  const [error, setError] = useState('')
  const [aviso, setAviso] = useState('')
  const [busqueda, setBusqueda] = useState('')
  const [filtroTipo, setFiltroTipo] = useState('')
  const [editando, setEditando] = useState(null)
  const [revision, setRevision] = useState(0)
  useEffect(() => {
    const controller = new AbortController()
    consultar(tipo, controller.signal).then(data => { if (!controller.signal.aborted) { setDatos(data); setError('') } })
      .catch(failure => { if (!controller.signal.aborted) setError(apiErrorMessage(failure)) })
    return () => controller.abort()
  }, [consultar, tipo, revision])

  function cambiarLista(nueva) {
    if (nueva === tipo) return
    setTipo(nueva)
    setDatos(null)
    setAviso('')
    setBusqueda('')
    setFiltroTipo('')
  }
  async function agregar(valores) {
    const item = await crear(tipo, valores)
    setAviso(`Se agregó «${item.nombre}».`)
    setRevision(valor => valor + 1)
  }
  async function corregir(id, valores) {
    const item = await actualizar(tipo, id, valores)
    setAviso(`Se guardó «${item.nombre}». El cambio quedó en la auditoría.`)
    setRevision(valor => valor + 1)
  }
  async function alternar(item) {
    setAviso('')
    try {
      await actualizar(tipo, item.id, { activo: !item.activo })
      setAviso(item.activo ? `«${item.nombre}» quedó desactivado: ya no se ofrece al registrar vínculos.` : `«${item.nombre}» quedó activo otra vez.`)
    } catch (failure) {
      setError(apiErrorMessage(failure))
    }
    setRevision(valor => valor + 1)
  }

  const buscado = normalizar(busqueda)
  const visibles = (datos || []).filter(item => (!buscado || normalizar(item.nombre).includes(buscado)) && (!filtroTipo || item.tipo_personal === filtroTipo))
  const lista = LISTAS[tipo]

  return <section aria-label="Puestos y unidades orgánicas" className="rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
    <div className="mb-5 flex items-start gap-3"><span className="grid size-11 shrink-0 place-items-center rounded-xl bg-teal-50 text-teal-700"><Icon name="folder" /></span><div><h2 className="font-semibold text-slate-800">Puestos y unidades orgánicas</h2><p className="mt-1 text-xs leading-relaxed text-slate-500">Las listas empiezan vacías y se llenan solas con lo que se escribe al registrar vínculos. Aquí puedes agregar, corregir nombres mal escritos y desactivar los repetidos. Nada se borra.</p></div></div>
    <div role="tablist" aria-label="Lista" className="mb-4 inline-flex rounded-xl border border-slate-200 p-1">
      {Object.entries(LISTAS).map(([clave, { titulo }]) => <button key={clave} type="button" role="tab" aria-selected={tipo === clave} onClick={() => cambiarLista(clave)}
        className={`rounded-lg px-3.5 py-2 text-xs font-semibold ${tipo === clave ? 'bg-teal-700 text-white' : 'text-slate-600 hover:bg-slate-50'}`}>{titulo}</button>)}
    </div>
    <Agregar key={tipo} tipo={tipo} crear={agregar} />
    {error && <p role="alert" className="mb-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-700">{error} <button type="button" onClick={() => setRevision(valor => valor + 1)} className="font-semibold underline">Reintentar</button></p>}
    {aviso && <p role="status" className="mb-4 rounded-xl bg-teal-50 p-3 text-sm text-teal-800">{aviso}</p>}
    {!datos && !error && <p role="status" className="text-sm text-slate-500">Cargando {lista.titulo.toLowerCase()}…</p>}
    {datos && datos.length === 0 && <p className="rounded-xl border border-dashed border-slate-200 p-5 text-center text-sm text-slate-500">{lista.vacio}</p>}
    {datos && datos.length > 0 && <>
      <div role="search" className="mb-3 flex flex-wrap gap-3">
        <input aria-label={`Buscar en ${lista.titulo.toLowerCase()}`} value={busqueda} onChange={event => setBusqueda(event.target.value)} placeholder="Buscar por nombre" className={`${control} min-w-0 flex-1 basis-56`} />
        {tipo === 'puestos' && <select aria-label="Tipo de personal" value={filtroTipo} onChange={event => setFiltroTipo(event.target.value)} className={control}>
          <option value="">Todo el personal</option>{Object.entries(tiposPersonal).map(([valor, texto]) => <option key={valor} value={valor}>{texto}</option>)}
        </select>}
      </div>
      <p className="mb-2 text-[11px] text-slate-400">{visibles.length} de {datos.length}</p>
      <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200">
        {visibles.map(item => <li key={item.id} className="flex flex-wrap items-center justify-between gap-3 p-3.5">
          <div className="min-w-0 flex-1 basis-60">
            <div className="flex flex-wrap items-center gap-2">
              <span className={`text-sm font-semibold ${item.activo ? 'text-slate-800' : 'text-slate-400 line-through'}`}>{item.nombre}</span>
              {item.tipo_personal && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">{tiposPersonal[item.tipo_personal]}</span>}
              {item.codigo && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">{item.codigo}</span>}
              {!item.activo && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500">Desactivado</span>}
            </div>
            <p className="mt-1 text-[11px] text-slate-500">
              {item.vinculos} {item.vinculos === 1 ? 'vínculo' : 'vínculos'}{item.unidad_padre ? ` · Depende de ${item.unidad_padre}` : ''}{item.registrado_por ? ` · Agregado por ${item.registrado_por}` : ''}{item.created_at ? ` el ${item.created_at.split('-').reverse().join('/')}` : ''}
            </p>
          </div>
          <div className="flex gap-2">
            <button type="button" onClick={() => { setEditando(item); setAviso('') }} className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Corregir</button>
            <button type="button" onClick={() => alternar(item)} className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">{item.activo ? 'Desactivar' : 'Activar'}</button>
          </div>
        </li>)}
        {visibles.length === 0 && <li className="p-4 text-center text-sm text-slate-500">Sin resultados.</li>}
      </ul>
    </>}
    {editando && <Editar tipo={tipo} item={editando} unidades={tipo === 'unidades-organicas' ? datos || [] : []} guardar={corregir} cerrar={() => setEditando(null)} />}
  </section>
}
