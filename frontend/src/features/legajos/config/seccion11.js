import { referenciaDocumentos } from './seccion02.js'

// Sección 11 (Desvinculación), Anexo 01 de la directiva: literales a) a g), aceptación de renuncia (Anexo 05) y otros.
// Dos entradas del menú: la desvinculación de cada vínculo y los documentos. Los valores los valida el backend
// (App\Support\Seccion11); aquí solo se agregan las etiquetas.
export const categoriasSeccion11 = {
  's11-desvinculacion': { titulo: 'Desvinculación' },
  's11-documentos': { titulo: 'Documentos de desvinculación' },
}

// desvinculaciones.motivo_cese: enum del esquema más termino_carrera (literal b).
export const motivosCese = {
  renuncia: 'Renuncia', termino_designacion: 'Término de designación', termino_carrera: 'Término del servicio civil de carrera',
  termino_contrato: 'Término de contrato', no_renovacion: 'No renovación de contrato', destitucion: 'Destitución',
  jubilacion: 'Jubilación', fallecimiento: 'Fallecimiento', otro: 'Otro',
}

export const bloquesDocumentos11 = {
  termino: 'Documentos del término (acreditan la desvinculación)',
  complementario: 'Documentos complementarios del cese',
  otros: 'Otros documentos de la universidad',
}

// Permisos de la sección (no es sensible; la liquidación sí lo es y la filtra el backend).
export const permisosSeccion11 = { registrar: ['legajo.editar'], anular: ['sistema.administrar'] }

// Etiqueta de un vínculo en la sección 11.
export function etiquetaVinculo11(vinculo) {
  const v = vinculo.values
  return `${v.nombre_puesto} · ${referenciaDocumentos(v)} · desde ${v.fecha_inicio ? v.fecha_inicio.split('-').reverse().join('/') : 'sin fecha'}`
}
