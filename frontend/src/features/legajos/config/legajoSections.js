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
  { number: '10', title: 'Seguridad y Salud en el Trabajo (SST) y bienestar social', categories: ['s10-sst', 's10-bienestar'], sinDocumentos: true },
  // Anexo 01, literales a) a g) y aceptación de renuncia (Anexo 05): la desvinculación de cada vínculo y sus documentos.
  { number: '11', title: 'Desvinculación', categories: ['s11-desvinculacion', 's11-documentos'], sinDocumentos: true },
  { number: '12', title: 'Otros documentos institucionales', categories: ['seccion-12'], sinDocumentos: true },
  { number: '13', title: 'Legajos pasivos y referencias', categories: ['referencias-legajo-pasivo'], sinDocumentos: true },
]

export const subseccionesSeccion10 = {
  's10-sst': { codigo: '10.1', titulo: 'Seguridad y Salud en el Trabajo (SST)' },
  's10-bienestar': { codigo: '10.2', titulo: 'Bienestar Social' },
}

export function findDocumentSection(group, sections) {
  const normalize = text => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]/g, '')
  return sections.find(section => Number(section.id) === Number(group.number)) || sections.find(section => normalize(section.nombre) === normalize(group.title))
}

// Listados genéricos de registros (solo lectura) de 03, 04, 06 y 08 y «Todos los documentos» de cada sección: repiten lo
// que muestra el panel de la sección, pero pueden tener registros sin documento que el panel no lista. No se quitan: van
// al final de la sección, bajo «Listados de consulta», para que la entrada principal sea la del panel.
export const categoriasConsulta = ['formacion-academica', 'capacitaciones', 'colegiaturas', 'experiencia-laboral', 'compensaciones-beneficios']

export function separarMenu(categorias) {
  const consulta = key => categoriasConsulta.includes(key) || key.startsWith('documentos-')
  return { principales: categorias.filter(key => !consulta(key)), consultas: categorias.filter(consulta) }
}
