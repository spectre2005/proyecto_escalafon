// Filtros del panel de Vencimientos: viven en la dirección (se recargan y se comparten). Cambiar uno vuelve a la página 1.
export const DIAS_POR_DEFECTO = 30

/** Parámetros con el filtro clave = valor (vacío lo quita) y sin página. */
export function conFiltro(params, clave, valor) {
  const siguiente = new URLSearchParams(params)
  const texto = String(valor ?? '').trim()
  if (texto) siguiente.set(clave, texto)
  else siguiente.delete(clave)
  siguiente.delete('page')
  return siguiente
}

/** Próximos días válidos (1 a 365, entero) o null. */
export function diasValidos(texto) {
  const numero = Number(String(texto ?? '').trim())
  return String(texto ?? '').trim() !== '' && Number.isInteger(numero) && numero >= 1 && numero <= 365 ? numero : null
}

/**
 * Al confirmar «Próximos días» (Enter o salir del campo): parámetros nuevos si el valor es válido y distinto del actual;
 * null si no hay nada que aplicar (inválido o igual), y entonces el campo vuelve al valor vigente.
 */
export function aplicarDias(params, texto) {
  const dias = diasValidos(texto)
  const actual = diasValidos(params.get('dias')) ?? DIAS_POR_DEFECTO
  return dias === null || dias === actual ? null : conFiltro(params, 'dias', dias)
}

/** Opciones de Estado: «fuera de plazo» solo para quien gestiona requerimientos; «Solo por vencer» excluye a quien tiene vencidos. */
export function opcionesEstado(gestionaRequerimientos) {
  return [['vencido', 'Vencido'], ...(gestionaRequerimientos ? [['fuera_de_plazo', 'Requerimiento fuera de plazo']] : []), ['por_vencer', 'Solo por vencer']]
}
