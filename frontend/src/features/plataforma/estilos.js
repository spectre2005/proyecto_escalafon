/** Clases comunes del panel de plataforma: un solo sitio para campos, tarjetas y botones. */

export const ENTRADA = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-800 disabled:bg-slate-50 disabled:text-slate-500'

export const TARJETA = 'rounded-2xl border border-slate-200 bg-white p-6'

const TIPOS = {
  principal: 'bg-teal-700 font-semibold text-white',
  secundario: 'border border-slate-200 bg-white text-slate-700',
  peligro: 'bg-rose-700 font-semibold text-white',
  peligroContorno: 'border border-rose-200 bg-white font-semibold text-rose-700',
}

/** normal: acciones de sección y diálogos; campo: a la altura de un campo (py-2.5); pequeno: acciones de una fila. */
const TAMANOS = {
  normal: 'px-4 py-2 text-sm',
  campo: 'px-4 py-2.5 text-sm',
  pequeno: 'px-3 py-1.5 text-xs',
}

/** Clases de un botón (o de un enlace con aspecto de botón). */
export function boton(tipo = 'secundario', tamano = 'normal') {
  if (!TIPOS[tipo] || !TAMANOS[tamano]) throw new Error(`Botón desconocido: ${tipo}/${tamano}`)
  return `rounded-xl ${TAMANOS[tamano]} ${TIPOS[tipo]}`
}
