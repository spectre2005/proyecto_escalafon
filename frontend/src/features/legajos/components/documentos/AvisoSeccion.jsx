import Icon from '../../../../components/ui/Icon'

export default function AvisoSeccion({ children, accion, tono }) {
  const urgente = tono === 'rose'
  const texto = typeof children === 'string' ? children.split('. ')[0] : ''
  const titulo = texto && texto.length <= 100 ? texto : 'Observación de la sección'
  if (urgente) return <div role="alert" className="mb-5 flex flex-wrap items-center gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800"><Icon name="info" /><div className="min-w-0 flex-1">{children}</div>{accion}</div>
  return <details className="group/nota mb-5 overflow-hidden rounded-2xl border border-slate-200 bg-white">
    <summary className="flex cursor-pointer list-none items-center gap-3 bg-slate-50 px-4 py-3.5 text-sm text-slate-700 focus-visible:outline-2 focus-visible:outline-teal-700 [&::-webkit-details-marker]:hidden">
      <Icon name="info" size={20} /><span className="min-w-0 flex-1 font-medium">{titulo}</span>
      <span className="shrink-0 text-xs font-semibold text-teal-700"><span className="group-open/nota:hidden">Ver detalles</span><span className="hidden group-open/nota:inline">Ocultar detalles</span></span>
      <span className="rotate-90 text-teal-700 transition-transform group-open/nota:-rotate-90"><Icon name="arrow" size={16} /></span>
    </summary>
    <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-4 text-sm leading-relaxed text-slate-600"><div className="min-w-0 flex-1 basis-64">{children}</div>{accion}</div>
  </details>
}
