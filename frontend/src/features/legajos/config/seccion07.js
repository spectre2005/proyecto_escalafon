// Sección 07 (Evaluación de desempeño, progresión en la carrera y desplazamiento): 3 subsecciones de la directiva.
// Los valores de las listas los valida el backend (Legajos\Seccion07Controller); aquí solo se agregan las etiquetas.
export const subseccionesSeccion07 = {
  's07-desempeno': { codigo: '7.1', titulo: '7.1 Evaluación de desempeño' },
  's07-progresion': { codigo: '7.2', titulo: '7.2 Progresión en la carrera' },
  's07-desplazamiento': { codigo: '7.3', titulo: '7.3 Desplazamiento' },
}

// Enums del esquema (desplazamientos.tipo, ratificaciones_docentes.*).
export const tiposDesplazamiento = {
  designacion: 'Designación', rotacion: 'Rotación', destaque: 'Destaque',
  encargo_puesto: 'Encargo de puesto', encargo_funciones: 'Encargo de funciones', otro: 'Otro',
}
export const tiposRatificacion = { nombramiento: 'Nombramiento', ratificacion: 'Ratificación', promocion: 'Promoción' }
export const categoriasDocente = { principal: 'Principal', asociado: 'Asociado', auxiliar: 'Auxiliar' }
export const resultadosRatificacion = { pendiente: 'Pendiente', ratificado: 'Ratificado', promovido: 'Promovido', no_ratificado: 'No ratificado' }

export const formulariosDatos07 = {
  evaluaciones_desempeno: 'Evaluación de desempeño',
  progresiones_carrera: 'Progresión en la carrera',
  ratificaciones_docentes: 'Ratificación o promoción docente',
  desplazamientos: 'Desplazamiento',
}

// formulario_datos => segmento de la ruta del backend.
export const rutaFormulario = {
  evaluaciones_desempeno: 'evaluaciones-desempeno',
  progresiones_carrera: 'progresiones-carrera',
  ratificaciones_docentes: 'ratificaciones-docentes',
  desplazamientos: 'desplazamientos',
}

// Pestaña de la pantalla Evaluaciones => subsección de la ficha.
export const categoriaPorConsulta = { evaluaciones: 's07-desempeno', progresiones: 's07-progresion', ratificaciones: 's07-progresion', desplazamientos: 's07-desplazamiento' }

// Opción configurable s07.evaluaciones.puntaje: con la puntuación desactivada (puntaje_activo === false) no se muestra la
// columna «Puntaje». Si el backend no informa la opción, se conserva la columna. Los puntajes guardados no se tocan.
export function columnasEvaluacion(columnas, puntajeActivo) {
  return puntajeActivo === false ? columnas.filter(([titulo]) => titulo !== 'Puntaje') : columnas
}
