// Configuración de funcionalidades de la institución (backend: GET /funcionalidades). Solo decide qué se muestra; el
// backend rechaza las escrituras de lo desactivado. Sin respuesta del backend no se oculta nada.
export const estadoVacio = { activas: {}, menu_oculto: [], secciones_ocultas: [], tipos_inactivos: [] }

// Una funcionalidad que el backend no informa se considera activa.
export function activa(estado, codigo) {
  return estado?.activas?.[codigo] !== false
}

// Secciones del menú del legajo sin las ocultas y sin sus entradas inactivas. «Documentos de la sección» se oculta
// con su sección; «Otros documentos» no es de ninguna funcionalidad y se ve mientras su sección esté visible.
export function seccionesVisibles(secciones, estado) {
  const menuOculto = new Set(estado?.menu_oculto || [])
  const seccionesOcultas = new Set((estado?.secciones_ocultas || []).map(Number))
  return secciones
    .filter(seccion => !seccionesOcultas.has(Number(seccion.number)))
    .map(seccion => ({
      ...seccion,
      categories: seccion.categories.filter(categoria => !menuOculto.has(categoria)),
      sinDocumentos: seccion.sinDocumentos || menuOculto.has(`documentos-${seccion.number}`),
    }))
}
