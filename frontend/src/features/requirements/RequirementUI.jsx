import Icon from '../../components/ui/Icon'
import { stateLabels } from './requirementUtils'

export const panel = 'rounded-2xl border border-slate-200 bg-white shadow-sm'
export const button = 'inline-flex items-center justify-center gap-2 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-semibold text-teal-800 transition hover:border-teal-300 hover:bg-teal-50 disabled:cursor-not-allowed disabled:opacity-50'
export const primary = 'inline-flex items-center justify-center gap-2 rounded-xl bg-teal-700 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50'
export const input = 'w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 focus:border-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-100'

export function RequirementState({ state }) {
  const colors = { abierto: 'bg-sky-50 text-sky-800 ring-sky-200', pendiente: 'bg-sky-50 text-sky-800 ring-sky-200', en_revision: 'bg-violet-50 text-violet-800 ring-violet-200', observado: 'bg-amber-50 text-amber-900 ring-amber-200', cumplido: 'bg-teal-50 text-teal-800 ring-teal-200', cancelado: 'bg-slate-100 text-slate-600 ring-slate-200', vencido: 'bg-rose-50 text-rose-800 ring-rose-200', prorrogado: 'bg-amber-50 text-amber-900 ring-amber-200' }
  return <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${colors[state] || 'bg-slate-100 text-slate-700 ring-slate-200'}`}>{stateLabels[state] || state || 'Sin estado'}</span>
}
export function EmptyRequirements({ title, children }) {
  return <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50/60 p-8 text-center"><span className="mx-auto grid size-11 place-items-center rounded-full bg-white text-slate-400"><Icon name="file" /></span><h3 className="mt-3 text-sm font-semibold text-slate-700">{title}</h3><p className="mx-auto mt-2 max-w-xl text-sm text-slate-500">{children}</p></div>
}
export function RequirementSection({ number, title, children }) {
  return <section className="space-y-4"><h3 className="flex items-center gap-3 text-sm font-semibold text-slate-900"><span className="grid size-7 place-items-center rounded-lg bg-teal-50 text-xs font-bold text-teal-800">{number}</span>{title}</h3><div className="space-y-4 sm:pl-10">{children}</div></section>
}
