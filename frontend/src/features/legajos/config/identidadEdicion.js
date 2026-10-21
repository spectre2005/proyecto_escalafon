export const claveDocumento = persona => `${persona.tipo_doc_identidad}:${persona.numero_doc_identidad}`

/** Corregir el documento no reemplaza datos personales antes de revisar la consulta. */
export function cambiarDocumentoEdicion(actual, campo, valor) {
  if (actual[campo] === valor) return actual
  return { ...actual, [campo]: valor }
}

const camposUbicacion = ['region', 'provincia', 'distrito']
const camposIdentidad = [['nombres', 'Nombres'], ['apellido_paterno', 'Apellido paterno'], ['apellido_materno', 'Apellido materno']]
const texto = valor => typeof valor === 'string' ? valor.trim() : ''
const partesUbicacion = ubicacion => Object.fromEntries(camposUbicacion.map(campo => [campo, texto(ubicacion?.[campo])]))
const mismaPersona = (actual, persona) => actual.tipo_doc_identidad === 'dni' && actual.numero_doc_identidad === persona?.dni
const descripcionUbicacion = ubicacion => camposUbicacion.map(campo => texto(ubicacion?.[campo])).filter(Boolean).join(' / ')

export function ubicacionDeEdicion(original, actual, manual) {
  if (manual?.documento === claveDocumento(actual)) return manual.value
  return partesUbicacion(original)
}

export function filasComparacionIdentidad(original, actual, ubicacion, persona) {
  if (!mismaPersona(actual, persona)) return []
  const filas = camposIdentidad.map(([key, label]) => ({ key, label, guardado: texto(original[key]), actual: texto(actual[key]), consultado: texto(persona[key]) }))
  const completa = camposUbicacion.every(campo => texto(persona.ubicacion?.[campo]))
  filas.push({ key: 'ubicacion', label: 'Región / Provincia / Distrito', guardado: descripcionUbicacion(original), actual: descripcionUbicacion(ubicacion), consultado: completa ? descripcionUbicacion(persona.ubicacion) : '' })
  return filas.map(fila => ({ ...fila, disponible: !!fila.consultado, diferente: !!fila.consultado && fila.actual !== fila.consultado }))
}

/** Solo los campos elegidos pasan al formulario; un dato ausente nunca borra información. */
export function aplicarSeleccionIdentidad(actual, ubicacion, persona, seleccion) {
  const filas = filasComparacionIdentidad(actual, actual, ubicacion, persona)
  const elegidas = filas.filter(fila => fila.diferente && seleccion.includes(fila.key))
  const nombres = elegidas.filter(fila => fila.key !== 'ubicacion').map(fila => [fila.key, fila.consultado])
  return {
    draft: nombres.length ? { ...actual, ...Object.fromEntries(nombres) } : actual,
    ubicacion: elegidas.some(fila => fila.key === 'ubicacion') ? partesUbicacion(persona.ubicacion) : null,
  }
}
