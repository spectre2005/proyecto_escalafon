import { useState } from 'react'
import { Link } from 'react-router-dom'
import Icon from '../../components/ui/Icon'
import { useManualQuery } from '../../shared/queries/useManualQuery'
import { QueryStatus, WorkflowPagination } from '../../shared/WorkflowControls'
import { formatDate } from '../../shared/dates'
import { contieneSensibles } from './custodiaUtils'
import { button, EmptyState } from './CustodiaUI'

export default function DocumentScopePicker({ legajo, value, onChange, tipoSolicitante }) {
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const request = useManualQuery(`/operaciones/legajos/${legajo.id}/documentos?${new URLSearchParams({ q: search.trim(), page })}`, 300)
  function toggle(doc) {
    onChange(value.some(item => item.id === doc.id) ? value.filter(item => item.id !== doc.id) : [...value, doc])
  }
  return <div className="space-y-3">
    <div className="flex flex-wrap items-center justify-between gap-3"><p className="text-sm font-semibold text-slate-800">Documentos del alcance <span className="ml-1 rounded-full bg-teal-50 px-2 py-1 text-xs text-teal-800">{value.length} seleccionados / 100</span></p><Link className="text-xs font-semibold text-teal-800 underline" to={`/legajos/${legajo.usuario_id}`} target="_blank" rel="noopener noreferrer">Abrir ficha en otra pestaña</Link></div>
    <label className="flex items-center gap-2 rounded-xl border border-slate-300 px-3"><Icon name="search" size={17} className="text-slate-400" /><input aria-label="Buscar documentos del legajo" className="min-w-0 flex-1 py-3 text-sm outline-none" placeholder="Buscar por tipo, nombre o número de documento" maxLength={120} value={search} onChange={event => { setSearch(event.target.value); setPage(1) }} /></label>
    {value.length > 0 && <div className="rounded-xl border border-teal-100 bg-teal-50/40 p-3"><p className="mb-2 text-xs font-semibold text-teal-800">El alcance conservará estos documentos aunque cambies de página:</p><ul className="flex max-h-40 flex-wrap gap-2 overflow-auto">{value.map(doc => <li key={doc.id} className="inline-flex max-w-full items-center gap-2 rounded-lg border border-teal-200 bg-white px-2.5 py-1.5 text-xs"><span className="break-words">{doc.nombre || doc.tipo_documento?.nombre}{doc.num_resolucion ? ` · ${doc.num_resolucion}` : ''}</span><button type="button" onClick={() => toggle(doc)} aria-label={`Quitar ${doc.nombre || doc.tipo_documento?.nombre}`} className="rounded p-1 text-slate-500 hover:bg-rose-50 hover:text-rose-700"><Icon name="close" size={14} /></button></li>)}</ul></div>}
    <QueryStatus request={request} />
    {request.data && <>{!request.data.data.length ? <EmptyState title="No hay documentos disponibles">{search ? 'Prueba con otro nombre o número de documento.' : 'Carga primero los PDF desde la ficha del legajo. Después pulsa Actualizar documentos.'}</EmptyState> : <div className="max-h-80 divide-y divide-slate-100 overflow-auto rounded-xl border border-slate-200">{request.data.data.map(doc => {
      const checked = value.some(item => item.id === doc.id)
      const sensible = contieneSensibles(doc)
      const incompatible = sensible && tipoSolicitante !== 'titular'
      return <label key={doc.id} className={`flex cursor-pointer items-start gap-3 p-3.5 ${checked ? 'bg-teal-50/60' : 'hover:bg-slate-50'} ${incompatible && !checked ? 'opacity-60' : ''}`}><input className="mt-1 size-4 accent-teal-700" type="checkbox" checked={checked} disabled={!checked && (value.length >= 100 || incompatible)} onChange={() => toggle(doc)} /><span className="min-w-0 flex-1"><span className="block text-sm font-semibold text-slate-800">{doc.tipo_documento?.nombre}</span>{doc.nombre && <span className="mt-1 block text-xs text-slate-600">{doc.nombre}</span>}<span className="mt-1 block text-xs text-slate-500">{doc.num_resolucion || 'Sin número'} · {formatDate(doc.fecha_emision)} · {doc.estado}</span>{doc.folio_inicio && <span className="mt-1 block text-xs text-slate-500">Sección {doc.seccion_legajo_id} · Folios {doc.folio_inicio}–{doc.folio_fin}</span>}{sensible && <span className="mt-2 block text-xs font-semibold text-amber-800">Datos sensibles · Solo para el titular del legajo</span>}</span>{checked && <span className="text-teal-700"><Icon name="check" size={17} /></span>}</label>
    })}</div>}<div className="flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500"><span>{request.data.total} documento(s) disponible(s)</span><button className={button} type="button" onClick={request.retry}>Actualizar documentos</button></div><WorkflowPagination data={request.data} setPage={setPage} loading={request.loading} /></>}
  </div>
}
