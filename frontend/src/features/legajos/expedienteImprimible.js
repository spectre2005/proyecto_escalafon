import { activa } from '../../shared/funcionalidadesEstado.js'

// Opciones del botón «Descargar ZIP» del legajo. Las secciones 01 a 04 van siempre; estos grupos solo se añaden si el
// usuario los marca (backend: ExpedienteImprimible::OPCIONALES, que vuelve a aplicar configuración y permisos).

export const SIEMPRE = 'Secciones 01 a 04: DNI, ficha y declaraciones; incorporación; formación académica; experiencia laboral.'

export const OPCIONES_ZIP = [
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

/** Solo lo que la institución usa y el usuario puede ver: sin funcionalidades desactivadas ni secciones ocultas. */
export function opcionesVisibles(estado, veSensibles) {
  const ocultas = new Set(estado?.secciones_ocultas || [])
  return OPCIONES_ZIP.filter(opcion => (veSensibles || !opcion.sensible)
    && (opcion.seccion ? !ocultas.has(opcion.seccion) : opcion.funcionalidades.every(codigo => activa(estado, codigo))))
}

/** URL de descarga con los grupos marcados (ninguno: solo 01 a 04). */
export function urlZip(usuario, seleccion = []) {
  const params = new URLSearchParams()
  for (const clave of OPCIONES_ZIP.map(opcion => opcion.clave).filter(clave => seleccion.includes(clave))) params.append('incluir[]', clave)
  const query = params.toString()
  return `/expedientes/${usuario}/expediente-imprimible/zip${query ? `?${query}` : ''}`
}
