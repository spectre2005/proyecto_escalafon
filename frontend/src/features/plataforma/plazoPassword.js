const DIA = 24 * 60 * 60 * 1000

export const fechaEnLima = (ahora = Date.now()) => new Date(ahora - 5 * 60 * 60 * 1000).toISOString().slice(0, 10)
export const vencimientoDelDia = fecha => fecha ? `${fecha}T23:59:59-05:00` : ''
export const limiteInicial = (ahora = Date.now()) => vencimientoDelDia(fechaEnLima(ahora + 2 * DIA))
export const fechaMaxima = (ahora = Date.now()) => fechaEnLima(ahora + 7 * DIA)
