import Icon from '../../components/ui/Icon'

export const panel = 'rounded-2xl border border-slate-200 bg-white shadow-sm'
export const button = 'inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-teal-800 transition hover:border-teal-300 hover:bg-teal-50 disabled:cursor-not-allowed disabled:opacity-50'
export const primary = 'inline-flex items-center justify-center gap-2 rounded-xl bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50'
export const input = 'w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100'


const estados = {
  pendiente: ['Pendiente', 'bg-amber-50 text-amber-800 ring-amber-200'],
  aprobada: ['Aprobada', 'bg-teal-50 text-teal-800 ring-teal-200'],
  rechazada: ['Rechazada', 'bg-rose-50 text-rose-800 ring-rose-200'],
  revocada: ['Revocada', 'bg-slate-100 text-slate-700 ring-slate-200'],
  vencida: ['Autorización vencida', 'bg-rose-50 text-rose-800 ring-rose-200'],
  sin_vigencia: ['Sin vigencia registrada', 'bg-amber-50 text-amber-800 ring-amber-200'],
  atrasado: ['Devolución atrasada', 'bg-rose-50 text-rose-800 ring-rose-200'],
  devuelto: ['Devuelto', 'bg-teal-50 text-teal-800 ring-teal-200'],
}
export function Estado({ value, prestamo = false }) {
  const [label, color] = estados[value] || [value, 'bg-slate-100 text-slate-700 ring-slate-200']
  return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${color}`}>{prestamo && value === 'pendiente' ? 'Por devolver' : label}</span>
}
export function EmptyState({ title, children, icon = 'folder' }) {
  return <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50/50 p-8 text-center"><span className="mx-auto grid size-11 place-items-center rounded-full bg-white text-slate-400"><Icon name={icon} /></span><h3 className="mt-3 text-sm font-semibold text-slate-700">{title}</h3><p className="mx-auto mt-2 max-w-lg text-sm text-slate-500">{children}</p></div>
}
export function FormSection({ step, title, description, children }) {
  return <section className="space-y-4"><div className="flex items-start gap-3"><span className="grid size-7 shrink-0 place-items-center rounded-lg bg-teal-50 text-xs font-bold text-teal-800">{step}</span><div><h3 className="text-sm font-semibold text-slate-900">{title}</h3>{description && <p className="mt-1 text-xs leading-relaxed text-slate-500">{description}</p>}</div></div><div className="space-y-4 sm:pl-10">{children}</div></section>
}
