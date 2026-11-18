// Cómo leer el resumen de acierto de la lectura con IA (LecturaIaConfig), en palabras de quien lo usa.

/** Con menos datos propuestos que esto, un porcentaje no dice mucho todavía: no se da veredicto. */
export const MINIMO_PARA_VEREDICTO = 5

/**
 * Veredicto de un porcentaje de acierto. Tono: bien | aviso | mal | neutro (lo usan el color y el icono; el texto
 * siempre acompaña, nunca el color solo).
 */
export function veredicto(acierto, propuestos) {
  if (acierto === null || acierto === undefined || !propuestos) return { texto: 'Sin datos', tono: 'neutro' }
  if (propuestos < MINIMO_PARA_VEREDICTO) return { texto: 'Pocos datos aún', tono: 'neutro' }
  if (acierto >= 80) return { texto: 'Confiable', tono: 'bien' }
  if (acierto >= 60) return { texto: 'Revisar con cuidado', tono: 'aviso' }
  return { texto: 'Poco confiable', tono: 'mal' }
}

/** «De cada 10 datos que completó la IA, 8 se guardaron sin cambios.» */
export function fraseAcierto(acierto) {
  if (acierto === null || acierto === undefined) return 'Todavía no hay documentos guardados después de leerlos con IA.'
  const deDiez = Math.round(acierto / 10)
  return `De cada 10 datos que completó la IA, ${deDiez} ${deDiez === 1 ? 'se guardó' : 'se guardaron'} sin cambios.`
}

/**
 * Campos para la lista: primero los que la IA completó, del menos al más confiable (lo que conviene revisar arriba);
 * al final los que nunca completó.
 */
export function ordenarCampos(filas = []) {
  return [...filas].sort((a, b) => {
    if (!a.propuestos !== !b.propuestos) return a.propuestos ? -1 : 1
    if (!a.propuestos) return b.no_detectado - a.no_detectado
    return (a.acierto ?? 101) - (b.acierto ?? 101) || b.propuestos - a.propuestos
  })
}

/** Ancho (%) de cada tramo de la barra de un campo: guardado sin cambios, corregido y borrado. */
export function tramos(fila) {
  const total = fila.propuestos || 0
  if (!total) return []
  return [
    { clave: 'aceptado', valor: fila.aceptado, ancho: (fila.aceptado / total) * 100 },
    { clave: 'corregido', valor: fila.corregido, ancho: (fila.corregido / total) * 100 },
    { clave: 'borrado', valor: fila.borrado, ancho: (fila.borrado / total) * 100 },
  ].filter(tramo => tramo.valor > 0)
}
