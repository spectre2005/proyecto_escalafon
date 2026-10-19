export const legajoSections = [
  // Seis grupos (config/seccion01.js); cada uno muestra sus datos y sus documentos (sin entrada aparte).
  { number: '01', title: 'Información personal y familiar', categories: ['general', 's01-antecedentes', 's01-declaraciones', 's01-familia', 's01-discapacidad', 's01-otros'] },
  // Subsecciones 2.1 a 2.4 de la directiva; cada una muestra sus datos y sus documentos (sin entrada aparte).
  { number: '02', title: 'Incorporación', categories: ['s02-seleccion', 's02-vinculo', 's02-induccion', 's02-prueba'] },
  { number: '03', title: 'Formación académica y capacitación', categories: ['seccion-03'] },
  { number: '04', title: 'Experiencia laboral', categories: ['seccion-04'] },
  // Anexo 01: licencias y permisos (a, e), vacaciones (b, c, d) y otros; cada entrada muestra sus registros y sus documentos.
  { number: '05', title: 'Movimientos del Personal', categories: ['s05-licencias', 's05-vacaciones', 's05-otros'] },
  { number: '06', title: 'Compensaciones', categories: ['seccion-06'] },
  // Subsecciones 7.1 a 7.3 de la directiva; cada una muestra sus datos y sus documentos (sin entrada aparte).
  { number: '07', title: 'Evaluación de desempeño, progresión en la carrera y desplazamiento', categories: ['s07-desempeno', 's07-progresion', 's07-desplazamiento'] },
  { number: '08', title: 'Reconocimientos y sanciones disciplinarias', categories: ['reconocimientos', 'sanciones'] },
  // Anexo 01: controversias individuales (a), colectivas (b), afiliación al sindicato (c) y otros; cada entrada muestra sus registros y sus documentos.
  { number: '09', title: 'Relaciones laborales individuales y colectivas', categories: ['s09-individuales', 's09-colectivas', 's09-afiliacion', 's09-otros'] },
  { number: '10', title: 'Seguridad y Salud en el Trabajo (SST) y bienestar social', categories: ['s10-sst', 's10-bienestar'] },
  // Anexo 01, literales a) a g) y aceptación de renuncia (Anexo 05): la desvinculación de cada vínculo y sus documentos.
  { number: '11', title: 'Desvinculación', categories: ['s11-desvinculacion', 's11-documentos'] },
]

export const subseccionesSeccion10 = {
  's10-sst': { codigo: '10.1', titulo: 'Seguridad y Salud en el Trabajo (SST)' },
  's10-bienestar': { codigo: '10.2', titulo: 'Bienestar Social' },
}

// Todos los documentos del legajo (de todas sus secciones y legajos), en una sola entrada fuera de las secciones. Antes
// cada sección 03, 04, 06 y 08 repetía su lista y sus registros genéricos; lo que mostraban está ahora en su panel.
export const CATEGORIA_DOCUMENTOS = 'documentos'

// Enlaces guardados a las entradas retiradas: llevan a la que muestra lo mismo.
const retiradas = {
  'formacion-academica': 'seccion-03', capacitaciones: 'seccion-03', colegiaturas: 'seccion-03',
  'experiencia-laboral': 'seccion-04', 'compensaciones-beneficios': 'seccion-06', 'tiempo-servicios-reconocidos': 'seccion-06',
  'seccion-10': 's10-sst', 'registros-sst-bienestar': 's10-sst', 'documentos-10': 's10-sst',
}
export function categoriaVigente(categoria) {
  if (retiradas[categoria]) return retiradas[categoria]
  return /^documentos-\d+$/.test(categoria) ? CATEGORIA_DOCUMENTOS : categoria
}
