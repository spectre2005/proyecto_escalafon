import Icon from '../../../../components/ui/Icon'

export default function ResumenTipo({ nombre, literal, cantidad, estado }) {
  return <span className="flex w-full flex-wrap items-center gap-3">
    <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-slate-100 text-sm font-semibold text-slate-500"><Icon name="file" size={20} /></span>
    <span className="min-w-0 flex-1 basis-40 text-sm font-medium leading-relaxed text-slate-800">{literal && <span className="mr-1 text-xs text-slate-400">{literal})</span>}{nombre}</span>
    <span className="flex flex-wrap items-center gap-3">
      {estado}
      <span className="min-w-16 text-xs tabular-nums text-slate-500">{cantidad ? `${cantidad} archivo${cantidad === 1 ? '' : 's'}` : 'Sin archivo'}</span>
      <span className="inline-flex items-center gap-2 rounded-lg border border-teal-200 bg-white px-3 py-2 text-xs font-semibold text-teal-700">Ver detalle <Icon name="arrow" size={15} /></span>
    </span>
  </span>
}
