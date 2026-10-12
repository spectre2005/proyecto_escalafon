import { ESTADOS, etiquetaEstado } from './estado'

export function EstadoInstitucion({ estado }) {
  return <span className={`inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset ${ESTADOS[estado]?.clase || 'bg-slate-50 text-slate-600 ring-slate-200'}`}>{etiquetaEstado(estado)}</span>
}

export function SinAprovisionar({ institucion }) {
  if (institucion.aprovisionada) return null
  return <span title="Su base de datos, su almacenamiento y su configuración aún no están listos y verificados." className="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-600 ring-1 ring-inset ring-slate-200">Pendiente de aprovisionamiento</span>
}

export function Aviso({ tipo = 'error', children }) {
  if (!children) return null
  const clase = tipo === 'exito' ? 'bg-teal-50 text-teal-800' : tipo === 'info' ? 'bg-amber-50 text-amber-900' : 'bg-rose-50 text-rose-700'
  return <p role={tipo === 'error' ? 'alert' : 'status'} className={`rounded-lg p-3 text-sm ${clase}`}>{children}</p>
}
