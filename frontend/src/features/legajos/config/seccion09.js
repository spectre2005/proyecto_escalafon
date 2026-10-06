// Sección 09 (Relaciones laborales individuales y colectivas), Anexo 01 de la directiva: literales a) a c) y otros de la
// universidad. Las entradas del menú agrupan la vista (bloque de cada tipo de documento); no son subsecciones de la directiva.
// Los valores de las listas los valida el backend (App\Support\Seccion09); aquí solo se agregan las etiquetas.
export const categoriasSeccion09 = {
  's09-individuales': { bloque: 'individuales', titulo: 'Controversias individuales', literales: 'a)' },
  's09-colectivas': { bloque: 'colectivas', titulo: 'Controversias colectivas', literales: 'b)' },
  's09-afiliacion': { bloque: 'afiliacion', titulo: 'Afiliación al sindicato', literales: 'c)' },
  's09-otros': { bloque: 'otros', titulo: 'Otros documentos', literales: null },
}

// Bloque => tipo de controversia (enum del esquema controversias.tipo).
export const tipoControversiaDe = { individuales: 'individual', colectivas: 'colectiva' }
export const tiposControversia = { individual: 'Individual', colectiva: 'Colectiva' }

// Enums del esquema (controversias.instancia y .estado). La directiva nombra la entidad, el Tribunal del Servicio Civil y el Poder Judicial, "entre otros".
export const instancias = { entidad: 'En la entidad', tribunal_servicio_civil: 'Tribunal del Servicio Civil', poder_judicial: 'Poder Judicial', otra: 'Otra' }
export const estadosControversia = { en_tramite: 'En trámite', resuelta: 'Resuelta', archivada: 'Archivada' }

// Permisos de la sección: todo exige además datos_sensibles.ver (el backend valida cada ruta).
export const permisosSeccion09 = { registrar: ['legajo.editar', 'datos_sensibles.ver'], anular: ['sistema.administrar', 'datos_sensibles.ver'] }
