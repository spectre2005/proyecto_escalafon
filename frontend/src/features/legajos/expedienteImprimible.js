import { activa } from '../../shared/funcionalidadesEstado.js'

// Opciones del botón «Descargar ZIP» del legajo: quien descarga elige los grupos (backend: ExpedienteImprimible::GRUPOS,
// que vuelve a aplicar configuración y permisos). Las secciones 01 a 04 vienen marcadas al abrir; se pueden quitar.

export const OPCIONES_ZIP = [
  { clave: 'identidad', etiqueta: '01. Identidad, ficha y declaraciones', funcionalidades: ['s01.identidad', 's01.declaraciones'], sensible: true },
  { clave: 'incorporacion', etiqueta: '02. Incorporación (selección, vínculos e inducción)', funcionalidades: ['s02.seleccion', 's02.vinculos', 's02.inducciones'] },
  { clave: 'formacion', etiqueta: '03. Formación académica y capacitación', funcionalidades: ['s03.formacion'] },
  { clave: 'experiencia', etiqueta: '04. Experiencia laboral', funcionalidades: ['s04.experiencia'] },
  { clave: 'familiares', etiqueta: 'Familiares (DNI de cónyuge e hijos, partida de matrimonio)', funcionalidades: ['s01.familiares'], sensible: true },
  { clave: 'discapacidad', etiqueta: 'Certificado de discapacidad', funcionalidades: ['s01.discapacidad'], sensible: true },
  { clave: 'periodo_prueba', etiqueta: 'Período de prueba', funcionalidades: ['s02.periodos_prueba'] },
  { clave: 'seccion_05', etiqueta: '05. Movimientos del personal', seccion: 5 },
  { clave: 'seccion_06', etiqueta: '06. Compensaciones', seccion: 6 },
  { clave: 'seccion_07', etiqueta: '07. Evaluación, progresión y desplazamiento', seccion: 7 },
  { clave: 'seccion_08', etiqueta: '08. Reconocimientos y sanciones', seccion: 8 },
  { clave: 'seccion_09', etiqueta: '09. Relaciones laborales', seccion: 9, sensible: true },
  { clave: 'seccion_10', etiqueta: '10. SST y bienestar', seccion: 10, sensible: true },
  { clave: 'seccion_11', etiqueta: '11. Desvinculación', seccion: 11 },
]

/** Grupos marcados al abrir la ventana: las secciones 01 a 04. */
export const PREDETERMINADAS = ['identidad', 'incorporacion', 'formacion', 'experiencia']

/** Solo lo que la institución usa y el usuario puede ver: sin funcionalidades desactivadas ni secciones ocultas. */
export function opcionesVisibles(estado, veSensibles) {
  const ocultas = new Set(estado?.secciones_ocultas || [])
  // Un grupo de varias funcionalidades se ofrece si alguna está activa (el servidor copia solo las activas).
  return OPCIONES_ZIP.filter(opcion => (veSensibles || !opcion.sensible)
    && (opcion.seccion ? !ocultas.has(opcion.seccion) : opcion.funcionalidades.some(codigo => activa(estado, codigo))))
}

/** Selección inicial: las predeterminadas que el usuario puede ver. */
export function seleccionInicial(opciones) {
  return opciones.map(opcion => opcion.clave).filter(clave => PREDETERMINADAS.includes(clave))
}

/**
 * URL de descarga con los grupos marcados, en un orden estable. Con legajo, el de ese legajo (también uno pasivo); sin él,
 * el legajo activo de la persona.
 */
export function urlZip(usuario, seleccion = [], legajo = null) {
  const params = new URLSearchParams()
  if (legajo) params.set('legajo', legajo)
  for (const clave of OPCIONES_ZIP.map(opcion => opcion.clave).filter(clave => seleccion.includes(clave))) params.append('incluir[]', clave)
  const query = params.toString()
  return `/expedientes/${usuario}/expediente-imprimible/zip${query ? `?${query}` : ''}`
}
