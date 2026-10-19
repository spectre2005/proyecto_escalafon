// Configuración de funcionalidades de la institución (backend: GET /funcionalidades). Solo decide qué se muestra; el
// backend rechaza las escrituras de lo desactivado. Sin respuesta del backend no se oculta nada.
export const estadoVacio = { activas: {}, menu_oculto: [], secciones_ocultas: [], tipos_inactivos: [] }

// Una funcionalidad que el backend no informa se considera activa.
export function activa(estado, codigo) {
  return estado?.activas?.[codigo] !== false
}

// Secciones del menú del legajo sin las ocultas y sin sus entradas inactivas.
export function seccionesVisibles(secciones, estado) {
  const menuOculto = new Set(estado?.menu_oculto || [])
  const seccionesOcultas = new Set((estado?.secciones_ocultas || []).map(Number))
  return secciones
    .filter(seccion => !seccionesOcultas.has(Number(seccion.number)))
    .map(seccion => ({ ...seccion, categories: seccion.categories.filter(categoria => !menuOculto.has(categoria)) }))
}

// Junta lo que ocultan las banderas de la persona (condiciones.modulos_ocultos de la ficha) con lo que oculta la
// configuración: un módulo se ve solo si su funcionalidad está activa y su bandera no lo oculta.
export function conModulosOcultos(estado, modulosOcultos) {
  return { ...estadoVacio, ...estado, menu_oculto: [...(estado?.menu_oculto || []), ...(modulosOcultos || [])] }
}

// Primera entrada disponible del menú (destino al entrar a un módulo oculto); «general» si no queda ninguna.
export function primeraCategoria(secciones) {
  return secciones.find(seccion => seccion.categories.length)?.categories[0] || 'general'
}
