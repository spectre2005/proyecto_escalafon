// Presentación del tiempo de experiencia de la sección 04 (backend: App\Support\TiempoExperiencia). Solo da formato;
// los cálculos y la configuración (días por mes y por año) los hace el backend.

const plural = (n, uno, varios) => `${n} ${n === 1 ? uno : varios}`

/** «5 años y 3 meses»; sin años ni meses, los días («12 días»). El detalle en días queda en «¿Cómo se calcula?». */
export function duracion(tiempo) {
  const partes = [tiempo.anios && plural(tiempo.anios, 'año', 'años'), tiempo.meses && plural(tiempo.meses, 'mes', 'meses')].filter(Boolean)
  if (partes.length) return partes.join(' y ')
  return plural(tiempo.dias || 0, 'día', 'días')
}

export const aniosDecimal = tiempo => `${Number(tiempo.anios_decimal || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} años`

/** Con periodos simultáneos, público + privado supera al total (cada día se cuenta una vez en el total). */
export const subtotalesSuperanTotal = tiempo => tiempo.publico.dias_totales + tiempo.privado.dias_totales > tiempo.total.dias_totales
