export const estados08 = {
  aceptado: ['Aceptado', 'bg-teal-50 text-teal-800'],
  pendiente: ['En revisión', 'bg-slate-100 text-slate-600'],
  observado: ['Observado', 'bg-rose-50 text-rose-800'],
  opcional: ['Opcional', 'bg-slate-100 text-slate-600'],
  no_aplica: ['No aplica', 'bg-slate-100 text-slate-500'],
}

// Mismo catálogo que App\Support\Sanciones (Ley 30057 y Ley 30220). Las de separación piden días y periodo.
export const tipoSancion08 = {
  AMONESTACION_VERBAL: 'Amonestación verbal',
  AMONESTACION_ESCRITA: 'Amonestación escrita',
  SUSPENSION: 'Suspensión',
  CESE_TEMPORAL: 'Cese temporal',
  DESTITUCION: 'Destitución',
  INHABILITACION: 'Inhabilitación',
}

export const sancionConSeparacion08 = ['SUSPENSION', 'CESE_TEMPORAL']

export const resultadoRecurso08 = {
  FUNDADO: 'Fundado',
  INFUNDADO: 'Infundado',
  IMPROCEDENTE: 'Improcedente',
}
