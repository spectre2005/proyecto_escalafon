// Interpreta /solicitudes-legajo/opciones?usuario_id=…: el backend decide con AplicacionDocumental (las mismas reglas
// del legajo) y marca cada tipo con no_aplica o por_definir. Aquí no se evalúa ninguna regla.

export function clasificarTipos(tipos = []) {
  return { seleccionables: tipos.filter(t => !t.no_aplica), noAplican: tipos.filter(t => t.no_aplica) }
}

// Al cambiar de persona, quita de la selección los tipos que ya no se pueden pedir (o que esa persona no tiene).
export function depurarSeleccion(seleccion, tipos = []) {
  const validos = new Set(clasificarTipos(tipos).seleccionables.map(t => t.id))
  return { seleccion: seleccion.filter(id => validos.has(id)), quitados: seleccion.filter(id => !validos.has(id)) }
}
