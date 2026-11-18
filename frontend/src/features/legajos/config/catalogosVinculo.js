// Listas de puestos y unidades orgánicas de la institución (/expediente-opciones: puestos activos y unidades). Empiezan
// vacías y aprenden de lo que se registra: lo que se escribe y no está en la lista se agrega al guardar el vínculo
// (backend: App\Support\CatalogosVinculo). Dos nombres son el mismo sin mayúsculas, tildes ni espacios repetidos.

export const normalizar = texto => String(texto ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim().toLocaleUpperCase('es-PE')

/** Nombres de los puestos activos del tipo de personal, para sugerir mientras se escribe. */
export function puestosDelTipo(puestos, tipoPersonal) {
  return (puestos || []).filter(puesto => puesto.tipo_personal === tipoPersonal).map(puesto => puesto.nombre)
}

/** Verdadero si lo escrito no está en la lista: se agregará al guardar. */
export function esNuevo(valor, nombres) {
  const buscado = normalizar(valor)
  return buscado !== '' && !(nombres || []).some(nombre => normalizar(nombre) === buscado)
}

/** Texto que muestra el campo de unidad: el nombre de la unidad elegida o lo escrito para una nueva. */
export function textoUnidad(draft, unidades) {
  if (draft.unidad_organica_id) return (unidades || []).find(unidad => unidad.id === draft.unidad_organica_id)?.nombre ?? ''
  return draft.unidad_organica_nombre || ''
}

/**
 * Lo escrito en el campo de unidad: si coincide con una unidad activa, se elige por su id; si no, se envía el texto y
 * el backend la crea (o avisa si coincide con una desactivada).
 */
export function elegirUnidad(texto, unidades) {
  const buscado = normalizar(texto)
  const unidad = buscado ? (unidades || []).find(item => item.activo !== false && normalizar(item.nombre) === buscado) : null
  return unidad ? { unidad_organica_id: unidad.id, unidad_organica_nombre: '' } : { unidad_organica_id: '', unidad_organica_nombre: texto }
}
