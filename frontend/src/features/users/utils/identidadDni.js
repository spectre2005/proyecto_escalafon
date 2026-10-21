import { updateUserDraft } from './usuariosEmail.js'

/** Conserva el correo escrito a mano y actualiza el sugerido cuando cambian los nombres. */
export function cambiarDniRegistro(actual, dni, dominio) {
  if (actual.numero_doc_identidad === dni) return actual
  let siguiente = updateUserDraft(actual, 'nombres', '', dominio)
  siguiente = updateUserDraft(siguiente, 'apellido_paterno', '', dominio)
  return { ...siguiente, numero_doc_identidad: dni, apellido_materno: '' }
}

/** Una respuesta tardía de otro DNI nunca reemplaza la persona que se está registrando. */
export function completarIdentidadRegistro(actual, persona, dominio) {
  if (actual.numero_doc_identidad !== persona.dni) return actual
  let siguiente = updateUserDraft(actual, 'nombres', persona.nombres, dominio)
  siguiente = updateUserDraft(siguiente, 'apellido_paterno', persona.apellido_paterno, dominio)
  return { ...siguiente, apellido_materno: persona.apellido_materno || '' }
}

/** La selección manual tiene prioridad y ninguna ubicación pasa a una persona con otro DNI. */
export function ubicacionDelRegistro(dni, manual, consulta) {
  if (manual?.dni === dni) return { value: manual.value, id: manual.id }
  const ubicacion = consulta?.dni === dni ? consulta.ubicacion : null
  return ubicacion
    ? { value: { region: ubicacion.region, provincia: ubicacion.provincia, distrito: ubicacion.distrito }, id: ubicacion.id }
    : { value: { region: '', provincia: '', distrito: '' }, id: '' }
}
