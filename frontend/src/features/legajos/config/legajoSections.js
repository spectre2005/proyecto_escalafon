export const legajoSections = [
  // Seis grupos (config/seccion01.js); cada uno muestra sus datos y sus documentos (sin entrada aparte).
  { number: '01', title: 'Información personal y familiar', categories: ['general', 's01-antecedentes', 's01-declaraciones', 's01-familia', 's01-discapacidad', 's01-otros'], sinDocumentos: true },
  // Subsecciones 2.1 a 2.4 de la directiva; cada una muestra sus datos y sus documentos (sin entrada aparte).
  { number: '02', title: 'Incorporación', categories: ['s02-seleccion', 's02-vinculo', 's02-induccion', 's02-prueba'], sinDocumentos: true },
  { number: '03', title: 'Formación académica y capacitación', categories: ['seccion-03', 'formacion-academica', 'capacitaciones', 'colegiaturas'] },
  { number: '04', title: 'Experiencia laboral', categories: ['seccion-04', 'experiencia-laboral'] },
  // Anexo 01: licencias y permisos (a, e), vacaciones (b, c, d) y otros; cada entrada muestra sus registros y sus documentos.
  { number: '05', title: 'Movimientos del Personal', categories: ['s05-licencias', 's05-vacaciones', 's05-otros'], sinDocumentos: true },
  { number: '06', title: 'Compensaciones', categories: ['seccion-06', 'compensaciones-beneficios'] },
  // Subsecciones 7.1 a 7.3 de la directiva; cada una muestra sus datos y sus documentos (sin entrada aparte).
  { number: '07', title: 'Evaluación de desempeño, progresión en la carrera y desplazamiento', categories: ['s07-desempeno', 's07-progresion', 's07-desplazamiento'], sinDocumentos: true },
  { number: '08', title: 'Reconocimientos y sanciones disciplinarias', categories: ['reconocimientos', 'sanciones'] },
  // Anexo 01: controversias individuales (a), colectivas (b), afiliación al sindicato (c) y otros; cada entrada muestra sus registros y sus documentos.
  { number: '09', title: 'Relaciones laborales individuales y colectivas', categories: ['s09-individuales', 's09-colectivas', 's09-afiliacion', 's09-otros'], sinDocumentos: true },
  { number: '10', title: 'Seguridad y Salud en el Trabajo (SST) y bienestar social', categories: ['registros-sst-bienestar'] },
  // Anexo 01, literales a) a g) y aceptación de renuncia (Anexo 05): la desvinculación de cada vínculo y sus documentos.
  { number: '11', title: 'Desvinculación', categories: ['s11-desvinculacion', 's11-documentos'], sinDocumentos: true },
]

export function findDocumentSection(group, sections) {
  const normalize = text => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]/g, '')
  return sections.find(section => normalize(section.nombre) === normalize(group.title))
}
