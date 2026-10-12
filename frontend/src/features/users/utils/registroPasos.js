import { banderasPuesto } from '../../../shared/banderas.js'
import { limpiarDatosPuesto } from '../../legajos/components/seccion02/borradoresVinculo.js'
import { validateUser } from './usuariosCsv.js'
import { conLey } from './leyes.js'

// Registro manual en tres pasos. Todo se envía en una sola operación al final (POST /usuarios/con-vinculo); los pasos
// solo ordenan la pantalla. Los errores del backend llegan como usuarios.0.*, vinculo.* y datos_puesto.*.
export const PASOS = [
  { numero: 1, titulo: 'Datos de la persona', resumen: 'Identificación, contacto, tipo de personal y ley' },
  { numero: 2, titulo: 'Primer vínculo laboral', resumen: 'Puesto, unidad, fechas y documento de ingreso' },
  { numero: 3, titulo: 'Cargo y datos del puesto', resumen: 'Perfil docente o administrativo y condiciones' },
]

/** Paso donde está el campo de un error del backend (las condiciones del puesto se muestran en el paso 3). */
export function pasoDeError(clave) {
  if (clave === 'usuarios' || clave.startsWith('usuarios.')) return 1
  if (clave === 'datos_puesto' || clave.startsWith('datos_puesto.')) return 3
  if (clave === 'vinculo' || clave.startsWith('vinculo.')) return banderasPuesto.includes(clave.slice('vinculo.'.length)) ? 3 : 2
  return null
}

/** Primer paso con errores, o null si ninguno es de un campo (p. ej. un 403 o un 409). */
export function primerPasoConError(errores) {
  const pasos = Object.keys(errores || {}).map(pasoDeError).filter(Boolean)
  return pasos.length ? Math.min(...pasos) : null
}

/** Errores de una parte, sin su prefijo: erroresDe(errores, 'vinculo') → { nombre_puesto: [...] }. */
export function erroresDe(errores, prefijo) {
  return Object.fromEntries(Object.entries(errores || {}).filter(([clave]) => clave.startsWith(`${prefijo}.`)).map(([clave, valor]) => [clave.slice(prefijo.length + 1), valor]))
}

/** Persona del paso 1 con los textos recortados y la ley elegida. */
export function personaDelPaso(draft, leyElegida) {
  return Object.fromEntries(Object.entries({ ...draft, regimen_normativo_id: leyElegida }).map(([clave, valor]) => [clave, typeof valor === 'string' ? valor.trim() : valor]))
}

/** Comprobaciones del paso 1 que no cubre el formulario: las mismas del alta (validateUser), ubicación y duplicados. */
export function erroresPersona(persona, { catalogo, usuarios = [], ubicacion = {}, ubigeoId = '' }) {
  const errores = validateUser(persona, catalogo)
  if (Object.values(ubicacion).some(Boolean) && !ubigeoId) errores.push('Selecciona región, provincia y distrito para completar la ubicación.')
  if (usuarios.some(item => item.numero_doc_identidad === persona.numero_doc_identidad || item.email_institucional?.toLowerCase() === persona.email_institucional.toLowerCase())) {
    errores.push('Ya existe un usuario con ese DNI o correo.')
  }
  return errores
}

const recortar = datos => Object.fromEntries(Object.entries(datos).map(([clave, valor]) => [clave, typeof valor === 'string' ? valor.trim() || null : valor]))

/**
 * Cuerpo de la operación final: persona (con su ley por id), primer vínculo y datos del puesto del tipo de personal de
 * la persona. Los datos del otro tipo, si se llegaron a escribir, no se envían.
 */
export function armarRegistro({ persona, catalogo, ubigeoId, condiciones, puedeDiscapacidad, vinculo, puestos }) {
  const docente = persona.tipo_personal === 'docente'
  return {
    usuarios: [conLey(catalogo, { ...persona, ubigeo_id: ubigeoId || null, tiene_derechohabientes: condiciones.tiene_derechohabientes, con_discapacidad: puedeDiscapacidad ? condiciones.con_discapacidad : null })],
    vinculo: recortar(vinculo),
    datos_puesto: limpiarDatosPuesto(docente, recortar(puestos[persona.tipo_personal] || {})),
  }
}
