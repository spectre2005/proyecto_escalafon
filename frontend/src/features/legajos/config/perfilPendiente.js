// «Completa el perfil»: condiciones Sin responder de la ficha (backend: App\Support\PerfilPendiente, en
// condiciones.pendientes). Cada una se guarda donde vive: las de la persona en la ficha (PATCH /expedientes/{u}) y las
// del puesto en su vínculo laboral (PATCH /expedientes/{u}/vinculos-laborales/{v}). Solo Sí o No: Sin responder sigue
// siendo válido y es lo que queda si no se contesta.
import { banderasPersona } from '../../../shared/banderas.js'

export const preguntasPerfil = {
  tiene_derechohabientes: '¿Tiene derechohabientes (cónyuge, concubino/a o hijos)?',
  con_discapacidad: '¿El servidor o alguno de sus hijos tiene discapacidad?',
  obligado_djbr: '¿Está obligado a presentar la declaración jurada de bienes y rentas?',
  exige_colegiatura: '¿El puesto exige colegiatura?',
  exige_estudios_basicos: '¿El puesto exige estudios básicos?',
  tiene_periodo_prueba: '¿Tiene período de prueba?',
}

/** Dónde se guarda la respuesta, para que se sepa qué registro cambia. */
export function lugarPendiente(pendiente) {
  return pendiente.ambito === 'vinculo' ? `Vínculo laboral: ${pendiente.puesto || 'sin puesto'}` : 'Datos de la persona'
}

/** Clave estable de una condición pendiente (la misma condición puede faltar en dos vínculos vigentes). */
export function clavePendiente(pendiente) {
  return `${pendiente.bandera}:${pendiente.vinculo_id || 'persona'}`
}

/** Petición que guarda la respuesta en su registro. valor: true (Sí) o false (No); nunca null por omisión. */
export function solicitudRespuesta(usuario, pendiente, valor) {
  if (valor !== true && valor !== false) throw new Error('Solo se responde Sí o No.')
  const url = pendiente.ambito === 'vinculo' ? `/expedientes/${usuario}/vinculos-laborales/${pendiente.vinculo_id}` : `/expedientes/${usuario}`
  return { url, data: { [pendiente.bandera]: valor } }
}

/**
 * ¿Puede responderla quien mira la ficha? Mismos permisos que el backend: las de la persona exigen editar el legajo con
 * datos sensibles (y la discapacidad, discapacidad.editar); las del puesto, editar el legajo con un legajo activo.
 */
export function motivoSinRespuesta(pendiente, { editar, sensibles, discapacidad, legajoActivo }) {
  if (!editar) return 'Tu rol no puede editar el legajo.'
  if (banderasPersona.includes(pendiente.bandera)) {
    if (!sensibles) return 'Requiere acceso a datos sensibles.'
    if (pendiente.bandera === 'con_discapacidad' && !discapacidad) return 'Solo administración de la ORH puede registrarlo.'
    return null
  }
  return legajoActivo ? null : 'La persona no tiene un legajo activo.'
}
