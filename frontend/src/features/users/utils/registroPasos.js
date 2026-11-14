import { banderasPuesto } from '../../../shared/banderas.js'
import { limpiarDatosPuesto } from '../../legajos/components/seccion02/borradoresVinculo.js'
import { validateUser } from './usuariosCsv.js'
import { conLey } from './leyes.js'

// Registro manual en cuatro pasos (el último solo revisa). Todo se envía en una sola operación al final (POST /usuarios/con-vinculo); los pasos
// solo ordenan la pantalla. Los errores del backend llegan como usuarios.0.*, vinculo.* y datos_puesto.*.
export const PASOS = [
  { numero: 1, titulo: 'Datos de la persona', resumen: 'Identificación, contacto, tipo de personal y ley' },
  { numero: 2, titulo: 'Puesto y cargo', resumen: 'Unidad, puesto, datos del puesto y condiciones' },
  { numero: 3, titulo: 'Vínculo laboral', resumen: 'Tipo, modalidad, proceso de selección y fechas' },
  { numero: 4, titulo: 'Revisión', resumen: 'Comprueba los datos antes de registrar' },
]

// Campos del vínculo que se piden en el paso 2 (con el puesto); el resto del vínculo va en el paso 3.
const EN_PASO_2 = [...banderasPuesto, 'unidad_organica_id', 'puesto_id', 'nombre_puesto']

/** Paso donde está el campo de un error del backend. */
export function pasoDeError(clave) {
  if (clave === 'usuarios' || clave.startsWith('usuarios.')) return 1
  if (clave === 'datos_puesto' || clave.startsWith('datos_puesto.')) return 2
  if (clave === 'vinculo' || clave.startsWith('vinculo.')) return EN_PASO_2.includes(clave.slice('vinculo.'.length)) ? 2 : 3
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
// nuevoIngreso: se confirmó que su legajo activo pase a Legajos pasivos para registrarla otra vez con un legajo nuevo.
export function erroresPersona(persona, { catalogo, usuarios = [], ubicacion = {}, ubigeoId = '', nuevoIngreso = false }) {
  const errores = validateUser(persona, catalogo)
  if (Object.values(ubicacion).some(Boolean) && !ubigeoId) errores.push('Selecciona región, provincia y distrito para completar la ubicación.')
  // El reingreso solo está disponible sin legajo activo y con un legajo pasivo.
  const existente = personaExistente(persona, usuarios)
  if (existente && !existente.puedeIncorporar && !(nuevoIngreso && existente.tieneLegajoActivo)) errores.push('Esta persona ya está registrada con ese DNI.')
  else if (usuarios.some(item => item.numero_doc_identidad !== persona.numero_doc_identidad && item.email_institucional?.toLowerCase() === persona.email_institucional.toLowerCase())) {
    errores.push('Ya existe un usuario con ese correo.')
  }
  return errores
}

/** Persona con el mismo DNI y disponibilidad para una nueva incorporación, o null. */
export function personaExistente(persona, usuarios = []) {
  const dni = String(persona.numero_doc_identidad || '').trim()
  const existente = dni ? usuarios.find(item => item.numero_doc_identidad === dni) : null
  return existente ? { id: existente.id, puedeIncorporar: existente.tiene_legajo_pasivo === true && existente.tiene_legajo_activo === false, tieneLegajoActivo: existente.tiene_legajo_activo === true, numeroIncorporacion: (existente.legajos_count || 0) + 1, nombre: [existente.nombres, existente.apellido_paterno, existente.apellido_materno].filter(Boolean).join(' ') } : null
}

/**
 * Sugerencia del tipo de vínculo con los datos del registro. Es la misma regla de App\Support\RegimenesLaborales::sugerencia
 * (backend), en el mismo orden y sin equivalencias nuevas: docente ordinario → carrera; docente contratado → plazo
 * determinado; D.L. 276 → sin sugerencia (carrera o designado); cargo de confianza → designado; CAS (1057) → plazo
 * determinado. Solo propone: el tipo lo elige el usuario y se puede cambiar.
 *
 * @returns {{ valor: string|null, motivo: string }}
 */
export function sugerenciaTipoVinculo({ tipoPersonal, datosPuesto = {}, codigoLey = '' }) {
  if (tipoPersonal === 'docente' && datosPuesto.condicion_docente === 'ordinario') return { valor: 'carrera', motivo: 'Docente ordinario.' }
  if (tipoPersonal === 'docente' && datosPuesto.condicion_docente === 'contratado') return { valor: 'plazo_determinado', motivo: 'Docente contratado.' }
  if (codigoLey === '276') return { valor: null, motivo: 'Confirma si es carrera o designado.' }
  if (tipoPersonal === 'administrativo' && datosPuesto.es_cargo_confianza === true) return { valor: 'designado', motivo: 'Cargo de confianza.' }
  if (codigoLey === '1057') return { valor: 'plazo_determinado', motivo: 'Contrato CAS (D.L. 1057).' }
  return { valor: null, motivo: 'Los datos del puesto no alcanzan: elige si es carrera, plazo determinado o designado.' }
}

/** Ficha de la persona y su sección 02 en 2.2, con la lista de documentos a la vista (Seccion02Panel atiende ir=documentos). */
export const rutaFicha = id => `/legajos/${id}`
export const rutaDocumentosIngreso = id => `/legajos/${id}?categoria=s02-vinculo&ir=documentos`

const recortar = datos => Object.fromEntries(Object.entries(datos).map(([clave, valor]) => [clave, typeof valor === 'string' ? valor.trim() || null : valor]))

/**
 * Cuerpo de la operación final: persona (con su ley por id), primer vínculo y datos del puesto del tipo de personal de
 * la persona. Los datos del otro tipo, si se llegaron a escribir, no se envían.
 */
// Campos opcionales que no se envían vacíos (así quien no ve datos sensibles nunca los manda).
const sinVacios = (objeto, claves) => Object.fromEntries(Object.entries(objeto).filter(([clave, valor]) => !claves.includes(clave) || String(valor ?? '').trim() !== ''))

export function armarRegistro({ persona, catalogo, ubigeoId, condiciones, puedeDiscapacidad, vinculo, puestos, nuevoIngreso = false }) {
  const docente = persona.tipo_personal === 'docente'
  return {
    // Nuevo ingreso con su legajo aún activo: el backend lo pasa a pasivo (historial) y abre uno nuevo.
    ...(nuevoIngreso ? { cerrar_legajo_actual: true } : {}),
    usuarios: [conLey(catalogo, { ...sinVacios(persona, ['fecha_nacimiento', 'domicilio']), ubigeo_id: ubigeoId || null, tiene_derechohabientes: condiciones.tiene_derechohabientes, con_discapacidad: puedeDiscapacidad ? condiciones.con_discapacidad : null })],
    vinculo: recortar(vinculo),
    datos_puesto: limpiarDatosPuesto(docente, recortar(puestos[persona.tipo_personal] || {})),
  }
}
