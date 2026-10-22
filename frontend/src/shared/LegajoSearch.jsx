import { useEffect, useId, useRef, useState } from 'react'
import Icon from '../components/ui/Icon'
import { apiErrorMessage } from '../axios'
import { useManualQuery } from './queries/useManualQuery'

const persona = item => item.usuario || item
const nombre = item => [persona(item).nombres, persona(item).apellido_paterno, persona(item).apellido_materno].filter(Boolean).join(' ')

export default function LegajoSearch({ search, legajo, onSearch, onSelect, onClear, mode = 'legajo', endpoint = '/reportes/formatos/legajos' }) {
  const personMode = mode === 'persona'
  const input = useRef(null)
  const listId = useId()
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState(true)
  const [active, setActive] = useState(-1)
  const term = search.trim()
  const request = useManualQuery(!legajo && term.length >= 2 ? `${endpoint}?q=${encodeURIComponent(term)}&page=${page}` : null, 300)
  const results = request.data?.data || []
  const expanded = open && !legajo && term.length >= 2
  const highlighted = active >= 0 && active < results.length ? active : -1
  useEffect(() => {
    if (expanded && highlighted >= 0) document.getElementById(`${listId}-${highlighted}`)?.scrollIntoView({ block: 'nearest' })
  }, [expanded, highlighted, listId])
  function select(item) { onSelect(item); setOpen(false); setActive(-1); setPage(1) }
  function clear() {
    onClear(); setPage(1); setActive(-1); setOpen(true)
    requestAnimationFrame(() => input.current?.focus())
  }
  function keyboard(event) {
    if (event.key === 'Escape') { event.preventDefault(); setOpen(false); setActive(-1) }
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault(); setOpen(true)
      if (results.length) setActive(event.key === 'ArrowDown' ? (highlighted + 1) % results.length : highlighted <= 0 ? results.length - 1 : highlighted - 1)
    }
    if (event.key === 'Enter' && expanded && highlighted >= 0) { event.preventDefault(); select(results[highlighted]) }
  }
  return <div className="space-y-3">
    {legajo ? <p className="text-sm font-semibold text-slate-800">{personMode ? 'Persona seleccionada' : 'Persona y legajo seleccionados'}</p> : <label htmlFor={`${listId}-input`} className="block text-sm font-semibold text-slate-800">{personMode ? 'Buscar destinatario' : 'Buscar persona o legajo'}</label>}
    {legajo ? <div className="flex flex-wrap items-center gap-4 rounded-xl border border-teal-200 bg-teal-50/60 p-4" role="status"><span className="grid size-11 place-items-center rounded-xl bg-white text-teal-700"><Icon name="user" /></span><div className="min-w-0 flex-1"><p className="break-words font-semibold text-slate-900">{nombre(legajo)}</p><p className="mt-1 text-xs text-slate-600">{!personMode && `${legajo.numero_legajo} · `}Documento: {persona(legajo).numero_doc_identidad || 'Sin registrar'}</p>{!personMode && <span className={`mt-2 inline-block rounded-full px-2 py-0.5 text-xs font-medium ${legajo.estado_legajo === 'activo' ? 'bg-teal-100 text-teal-800' : 'bg-slate-200 text-slate-700'}`}>Legajo {legajo.estado_legajo}</span>}</div><button type="button" onClick={clear} className="rounded-lg border border-teal-200 bg-white px-3 py-2 text-xs font-semibold text-teal-800 hover:bg-teal-100">Cambiar selección</button></div> : <>
      <div className="flex items-center gap-3 rounded-xl border border-slate-300 bg-white px-4 transition focus-within:border-teal-600 focus-within:ring-2 focus-within:ring-teal-100"><Icon name="search" size={20} className="shrink-0 text-slate-400" /><input ref={input} id={`${listId}-input`} data-sin-mayusculas className="min-w-0 flex-1 bg-transparent py-3.5 text-sm text-slate-800 outline-none" value={search} maxLength={120} placeholder={personMode ? 'Ej.: Ana Torres o DNI 99000841' : 'Ej.: Ana Torres, 99000841 o LEG-2026-000001'} autoComplete="off" role="combobox" aria-autocomplete="list" aria-expanded={expanded && results.length > 0} aria-controls={expanded && results.length ? listId : undefined} aria-activedescendant={expanded && highlighted >= 0 ? `${listId}-${highlighted}` : undefined} aria-describedby={`${listId}-hint`} onKeyDown={keyboard} onFocus={() => setOpen(true)} onChange={event => { onSearch(event.target.value); setPage(1); setActive(-1); setOpen(true) }} />{search && <button type="button" aria-label="Limpiar búsqueda" onClick={clear} className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100"><Icon name="close" size={17} /></button>}</div>
      <p id={`${listId}-hint`} className="text-xs text-slate-500">{personMode ? 'Escribe al menos dos caracteres y selecciona a la persona que recibirá la solicitud.' : 'Escribe al menos dos caracteres para buscar. Selecciona un legajo concreto o consulta todos los que coincidan con el texto.'}</p>
      {expanded && <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm" aria-busy={request.loading}>
        {request.loading ? <p role="status" className="px-4 py-5 text-sm text-slate-500">{personMode ? 'Buscando personas…' : 'Buscando personas y legajos…'}</p> : request.error ? <p role="alert" className="p-4 text-sm text-rose-800">{apiErrorMessage(request.error)} <button type="button" onClick={request.retry} className="font-semibold underline">Reintentar</button></p> : <>
          <div className="flex items-center justify-between gap-3 border-b border-slate-100 bg-slate-50 px-4 py-2.5 text-xs text-slate-500"><span>{request.data?.total || 0} {personMode ? 'persona(s) encontrada(s)' : 'legajo(s) encontrado(s)'}</span><span>↑ ↓ para navegar · Enter para seleccionar</span></div>
          {!results.length ? <p className="p-5 text-sm text-slate-600">No encontramos coincidencias. Prueba con el documento de identidad o solo un apellido.</p> : <ul id={listId} role="listbox" aria-label={personMode ? 'Personas encontradas' : 'Personas y legajos encontrados'} className="max-h-72 overflow-y-auto divide-y divide-slate-100">{results.map((item, index) => <li key={item.id} role="presentation"><button type="button" role="option" aria-selected={highlighted === index} id={`${listId}-${index}`} tabIndex={-1} onMouseEnter={() => setActive(index)} onClick={() => select(item)} className={`flex w-full items-start gap-3 px-4 py-3 text-left transition ${highlighted === index ? 'bg-teal-50' : 'hover:bg-slate-50'}`}><span className="mt-0.5 text-slate-400"><Icon name={personMode ? "user" : "folder"} size={18} /></span><span className="min-w-0 flex-1"><span className="block break-words text-sm font-semibold text-slate-800">{nombre(item)}</span><span className="mt-1 block text-xs text-slate-500">{!personMode && `${item.numero_legajo} · `}Documento: {persona(item).numero_doc_identidad || 'Sin registrar'}</span></span>{!personMode && <span className={`rounded-full px-2 py-1 text-[11px] font-medium ${item.estado_legajo === 'activo' ? 'bg-teal-50 text-teal-800' : 'bg-slate-100 text-slate-600'}`}>{item.estado_legajo}</span>}</button></li>)}</ul>}
          {request.data?.last_page > 1 && <div className="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-2 text-xs text-slate-500"><button type="button" disabled={page <= 1} className="rounded-lg border px-3 py-2 disabled:opacity-40" onClick={() => { setPage(value => value - 1); setActive(-1) }}>Anterior</button><span>Página {page} de {request.data.last_page}</span><button type="button" disabled={page >= request.data.last_page} className="rounded-lg border px-3 py-2 disabled:opacity-40" onClick={() => { setPage(value => value + 1); setActive(-1) }}>Siguiente</button></div>}
        </>}
      </div>}
    </>}
  </div>
}
