import { banderasPuesto } from '../../../shared/banderas.js'
import { limpiarDatosPuesto } from '../../legajos/components/seccion02/borradoresVinculo.js'
import { nivelTrasCambiarGrupo } from '../../legajos/config/seccion02.js'
import { validateUser } from './usuariosCsv.js'
import { conLey } from './leyes.js'

// Registro manual en tres pasos (el último solo revisa). Puesto y vínculo van juntos: uno decide al otro. Todo se envía en
// una sola operación al final (POST /usuarios/con-vinculo); los pasos solo ordenan la pantalla. Los errores del backend
// llegan como usuarios.0.*, vinculo.* y datos_puesto.*.
export const PASOS = [
  { numero: 1, titulo: 'Persona', resumen: 'Identidad, tipo de personal y ley' },
  { numero: 2, titulo: 'Puesto y vínculo', resumen: 'Dónde, en qué y desde cuándo' },
  { numero: 3, titulo: 'Revisar y registrar', resumen: 'Un vistazo final' },
]

/** Paso donde está el campo de un error del backend. */
export function pasoDeError(clave) {
  if (clave === 'usuarios' || clave.startsWith('usuarios.')) return 1
  if (['datos_puesto', 'vinculo'].some(parte => clave === parte || clave.startsWith(`${parte}.`))) return 2
  return null
}

/** Nombre corto de una ley para su tarjeta: «Decreto Legislativo N° 1057 (CAS)» → «D.L. 1057»; «Ley N° 30057 - …» → «Ley 30057». */
export function tituloLey(ley) {
  if (/^decreto legislativo/i.test(ley.nombre || '')) return `D.L. ${ley.codigo}`
  if (/^ley\b/i.test(ley.nombre || '')) return `Ley ${ley.codigo}`
  return ley.nombre || ley.codigo
}

/** Una línea que explica cada ley en las tarjetas del paso 1 (por código del régimen normativo). */
export const DESCRIPCION_LEY = {
  276: 'Carrera administrativa', 728: 'Régimen privado', 1057: 'CAS · sin nivel de carrera', 30057: 'Servicio Civil', 30220: 'Ley Universitaria',
}

// Condiciones que pueden quedar «Sin responder» al registrar (se completan después en la ficha).
const NOMBRES_CONDICION = {
  obligado_djbr: 'Obligado a la declaración jurada de bienes y rentas', exige_colegiatura: 'El puesto exige colegiatura',
  exige_estudios_basicos: 'El puesto exige estudios básicos', tiene_periodo_prueba: 'Tiene período de prueba',
  tiene_derechohabientes: 'Tiene derechohabientes', con_discapacidad: 'Discapacidad del servidor o de alguno de sus hijos',
}

/** Condiciones del puesto y de la persona que siguen «Sin responder» (no impiden registrar). */
export function condicionesSinResponder({ vinculo = {}, condiciones = {}, puedeDiscapacidad = true }) {
  const persona = Object.keys(condiciones).filter(clave => clave !== 'con_discapacidad' || puedeDiscapacidad)
  return [...banderasPuesto.filter(clave => vinculo[clave] !== true && vinculo[clave] !== false),
    ...persona.filter(clave => condiciones[clave] !== true && condiciones[clave] !== false)].map(clave => NOMBRES_CONDICION[clave] || clave)
}

/** Iniciales para el resumen lateral: «Ana Lucía Torres» → «AT». */
export function iniciales(persona = {}) {
  return [persona.nombres, persona.apellido_paterno].map(parte => String(parte || '').trim()[0] || '').join('').toUpperCase()
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
// Información personal (sección 01), donde se sube la copia del DNI.
export const rutaDatosPersonales = id => `/legajos/${id}?categoria=general`

const recortar = datos => Object.fromEntries(Object.entries(datos).map(([clave, valor]) => [clave, typeof valor === 'string' ? valor.trim() || null : valor]))

/**
 * Cuerpo de la operación final: persona (con su ley por id), primer vínculo y datos del puesto del tipo de personal de
 * la persona. Los datos del otro tipo, si se llegaron a escribir, no se envían.
 */
// Campos opcionales que no se envían vacíos (así quien no ve datos sensibles nunca los manda).
const sinVacios = (objeto, claves) => Object.fromEntries(Object.entries(objeto).filter(([clave, valor]) => !claves.includes(clave) || String(valor ?? '').trim() !== ''))

// El nivel de carrera vale solo si es de la ley y del grupo elegidos (si se cambió la ley después, se envía vacío).
function conNivelDeLaLey(docente, datos, catalogo, escalas, leyId) {
  if (docente) return datos
  const codigoLey = (catalogo || []).find(ley => ley.id === leyId)?.codigo
  return { ...datos, nivel_carrera: nivelTrasCambiarGrupo(escalas, datos.nivel_carrera, codigoLey, datos.grupo_ocupacional) }
}

export function armarRegistro({ persona, catalogo, escalas = [], ubigeoId, condiciones, puedeDiscapacidad, vinculo, puestos, nuevoIngreso = false, legajosPrevios = null }) {
  const docente = persona.tipo_personal === 'docente'
  return {
    // Nuevo ingreso con su legajo aún activo: el backend lo pasa a pasivo (historial) y abre uno nuevo. Con cuántos legajos
    // tenía al confirmarlo: si la solicitud llega dos veces, la segunda se rechaza en lugar de cerrar el legajo recién abierto.
    ...(nuevoIngreso ? { cerrar_legajo_actual: true } : {}),
    ...(Number.isInteger(legajosPrevios) ? { legajos_previos: legajosPrevios } : {}),
    usuarios: [conLey(catalogo, { ...sinVacios(persona, ['fecha_nacimiento', 'domicilio']), ubigeo_id: ubigeoId || null, tiene_derechohabientes: condiciones.tiene_derechohabientes, con_discapacidad: puedeDiscapacidad ? condiciones.con_discapacidad : null })],
    vinculo: recortar(vinculo),
    datos_puesto: limpiarDatosPuesto(docente, conNivelDeLaLey(docente, recortar(puestos[persona.tipo_personal] || {}), catalogo, escalas, persona.regimen_normativo_id)),
  }
}

/**
 * Datos que se pueden guardar pero suelen ser un error al escribir (no bloquean: hay casos reales). Lo que nunca corresponde
 * (proceso o modalidad de otro tipo de personal o de otra ley) ya no se puede elegir y el backend lo rechaza.
 * TODO pendiente de confirmar con el cliente: si alguno de estos casos debe impedir el registro.
 */
export function avisosDeCoherencia({ vinculo, datosPuesto = {}, codigoLey = '', tipo = '', gruposPuesto = null }) {
  const condicion = tipo === 'docente' ? datosPuesto.condicion_docente : ''
  // Grupos del puesto (catálogo o denominación sugerida); solo un puesto escrito a mano se deduce por su nombre.
  const nombreDice = !vinculo.puesto_id && !gruposPuesto ? grupoPorNombre(vinculo.nombre_puesto) : ''
  const esperados = tipo !== 'administrativo' ? null : gruposPuesto || (nombreDice ? [nombreDice] : null)
  return [
    condicion === 'ordinario' && vinculo.regimen_laboral && vinculo.regimen_laboral !== 'carrera'
      && 'Un docente ordinario es de carrera, pero el tipo de vínculo no es «Carrera». Si es un contrato temporal, la condición docente es «Contratado».',
    condicion === 'contratado' && vinculo.regimen_laboral === 'carrera'
      && 'Un docente contratado no es de carrera: su tipo de vínculo suele ser «Plazo determinado».',
    esperados && datosPuesto.grupo_ocupacional && !esperados.includes(datosPuesto.grupo_ocupacional)
      && `El puesto «${vinculo.nombre_puesto}» es del grupo ${esperados.map(g => GRUPOS[g]).join(' o ')}, pero elegiste el grupo ${GRUPOS[datosPuesto.grupo_ocupacional] || datosPuesto.grupo_ocupacional}: revisa el grupo y el nivel de carrera.`,
    vinculo.regimen_laboral === 'carrera' && vinculo.fecha_fin_prevista
      && `Un vínculo de carrera (nombrado u ordinario) normalmente no tiene fecha de fin, y este termina el ${vinculo.fecha_fin_prevista.split('-').reverse().join('/')}. Si es un contrato temporal, el tipo de vínculo es «Plazo determinado».`,
    codigoLey === '1057' && vinculo.regimen_laboral === 'carrera'
      && 'Un contrato CAS (D.L. 1057) no es de carrera: suele ser «Plazo determinado», o «Designado» si es CAS de confianza.',
    tipo === 'administrativo' && datosPuesto.es_cargo_confianza && vinculo.regimen_laboral && vinculo.regimen_laboral !== 'designado'
      && 'Marcaste «Cargo de confianza», pero el tipo de vínculo no es «Designado (confianza)».',
    vinculo.fecha_resultado_seleccion && vinculo.fecha_inicio && vinculo.fecha_resultado_seleccion > vinculo.fecha_inicio
      && 'El resultado del proceso de selección es posterior al inicio del vínculo: revisa las fechas.',
  ].filter(Boolean)
}

const GRUPOS = { profesional: 'Profesional', tecnico: 'Técnico', auxiliar: 'Auxiliar' }

/**
 * Grupo ocupacional que el nombre del puesto dice al empezar («Técnico administrativo», «Técnica en enfermería» →
 * técnico), o ''. Solo al inicio: «Secretaria de Escuela Profesional» no es del grupo profesional.
 */
export function grupoPorNombre(nombrePuesto = '') {
  const texto = String(nombrePuesto || '').toUpperCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim()
  const inicio = { profesional: /^PROFESIONAL\b/, tecnico: /^TECNIC[OA]S?\b/, auxiliar: /^AUXILIAR(ES)?\b/ }
  return Object.keys(inicio).find(grupo => inicio[grupo].test(texto)) || ''
}
