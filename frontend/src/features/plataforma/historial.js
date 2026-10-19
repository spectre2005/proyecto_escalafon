/**
 * Listas largas del detalle de una institución: se muestran los primeros registros (vienen del más reciente al más
 * antiguo) y «Ver todos» enseña el resto de lo que ya llegó, sin pedir nada más al backend.
 */

/** Registros visibles en la vista compacta. */
export const LIMITES = { auditoria: 10, respaldos: 3, periodos: 5 }

/**
 * Cuántos registros manda como máximo el backend (InstitucionController::show). Si la lista llega a ese número, puede
 * haber más en la base: el texto habla de «los más recientes», no de «todos». Los periodos llegan completos.
 */
export const MAXIMOS_BACKEND = { auditoria: 30, respaldos: 10 }

/** Registros a mostrar. hayMas: hay más que el límite (solo entonces se ofrece «Ver todos»). */
export function acotar(lista, limite, expandido) {
  const total = lista.length
  const hayMas = total > limite
  return { visibles: hayMas && !expandido ? lista.slice(0, limite) : lista, total, hayMas }
}

/** Conteo para la línea junto a «Ver todos» (nombre en plural: «eventos», «respaldos», «periodos»). */
export function textoConteo({ visibles, total, nombre, maximo = null }) {
  const recientes = maximo !== null && total >= maximo
  if (visibles < total) return recientes ? `Se muestran ${visibles} de los ${total} ${nombre} más recientes.` : `Se muestran ${visibles} de ${total} ${nombre}.`
  return recientes ? `Se muestran los ${total} ${nombre} más recientes.` : `Se muestran los ${total} ${nombre}.`
}
