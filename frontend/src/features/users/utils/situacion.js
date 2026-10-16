// Situación laboral que calcula el backend (App\Support\SituacionLaboral), separada del acceso al sistema (activo).
export const situaciones = {
  vinculo_vigente: { texto: 'Con vínculo vigente', clase: 'bg-[#eaf4ee] text-[#3d8060]' },
  sin_vinculo_vigente: { texto: 'Sin vínculo vigente', clase: 'bg-[#faf3e5] text-[#9b7a37]' },
  sin_legajo: { texto: 'Sin legajo activo', clase: 'bg-[#f1f3f2] text-[#66776f]' },
}

export const textoAcceso = activo => activo ? 'Con acceso' : 'Sin acceso'

export function situacionDe(user) {
  return situaciones[user.situacion] || { texto: 'Sin dato', clase: 'bg-[#f1f3f2] text-[#66776f]' }
}
