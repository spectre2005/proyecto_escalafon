export const legajoSections = [
  { number: '01', title: 'Información personal y familiar', categories: ['general', 'familiares', 'declaraciones-verificaciones', 'certificados-discapacidad'] },
  // Subsecciones 2.1 a 2.4 de la directiva; cada una muestra sus datos y sus documentos (sin entrada aparte).
  { number: '02', title: 'Incorporación', categories: ['s02-seleccion', 's02-vinculo', 's02-induccion', 's02-prueba'], sinDocumentos: true },
  { number: '03', title: 'Formación académica y capacitación', categories: ['formacion-academica', 'capacitaciones', 'colegiaturas'] },
  { number: '04', title: 'Experiencia laboral', categories: ['experiencia-laboral', 'tiempo-servicios-reconocidos'] },
  { number: '05', title: 'Movimientos del Personal', categories: ['licencias-permisos', 'periodos-vacacionales', 'goces-vacacionales'] },
  { number: '06', title: 'Compensaciones', categories: ['compensaciones-beneficios'] },
  { number: '07', title: 'Evaluación de desempeño, progresión en la carrera y desplazamiento', categories: ['evaluaciones-desempeno', 'progresiones-carrera', 'desplazamientos', 'ratificaciones-docentes'] },
  { number: '08', title: 'Reconocimientos y sanciones disciplinarias', categories: ['reconocimientos', 'sanciones'] },
  { number: '09', title: 'Relaciones laborales individuales y colectivas', categories: ['afiliaciones', 'controversias'] },
  { number: '10', title: 'Seguridad y Salud en el Trabajo (SST) y bienestar social', categories: ['registros-sst-bienestar'] },
  { number: '11', title: 'Desvinculación', categories: ['desvinculaciones'] },
]

export function findDocumentSection(group, sections) {
  const normalize = text => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]/g, '')
  return sections.find(section => normalize(section.nombre) === normalize(group.title))
}
