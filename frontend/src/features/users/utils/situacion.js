// Situación laboral que calcula el backend (App\Support\SituacionLaboral), separada del acceso al sistema (activo).
export const situaciones = {
  vinculo_vigente: { texto: 'Con vínculo vigente', clase: 'bg-teal-50 text-teal-700' },
  sin_vinculo_vigente: { texto: 'Sin vínculo vigente', clase: 'bg-amber-50 text-amber-700' },
  sin_legajo: { texto: 'Sin legajo activo', clase: 'bg-slate-100 text-slate-500' },
}

export const textoAcceso = activo => activo ? 'Con acceso' : 'Sin acceso'

export function situacionDe(user) {
  return situaciones[user.situacion] || { texto: 'Sin dato', clase: 'bg-slate-100 text-slate-500' }
}
