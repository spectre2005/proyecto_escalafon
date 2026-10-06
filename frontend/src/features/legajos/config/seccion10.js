// Sección 10 (SST y Bienestar Social), Anexo 01 de la directiva: subsecciones 10.1 y 10.2 con sus literales, el bloque de
// accidentes y enfermedades (párrafo general) y otros documentos de la universidad. Los valores los valida el backend
// (App\Support\Seccion10); aquí solo se agregan las etiquetas.
export const categoriasSeccion10 = {
  's10-sst': { subseccion: '10.1', titulo: '10.1 Seguridad y Salud en el Trabajo' },
  's10-bienestar': { subseccion: '10.2', titulo: '10.2 Bienestar social' },
  's10-otros': { subseccion: null, titulo: 'Otros documentos' },
}

// Bloques de cada subsección. registros: valores de registros_sst_bienestar.tipo; documento: código del tipo de documento.
// consulta: 10.2 c) muestra los descansos médicos de la sección 05 (sin registro ni PDF propios).
export const bloquesSeccion10 = {
  '10.1': [
    { key: 'reglamento', literal: 'a', titulo: 'Entrega del Reglamento de SST', registros: ['entrega_reglamento_sst'], documento: 'S10_1A_REGLAMENTO_SST' },
    { key: 'simulacros', literal: 'b', titulo: 'Entrenamientos y simulacros de emergencia', registros: ['entrenamiento_simulacro'], documento: 'S10_1B_SIMULACROS' },
    { key: 'capacitaciones', literal: 'c', titulo: 'Capacitaciones brindadas por la entidad (lista de asistencia)', registros: ['capacitacion_sst'], documento: 'S10_1C_ASISTENCIA_CAPACITACION' },
    { key: 'accidentes', literal: null, titulo: 'Accidentes de trabajo y enfermedades ocupacionales', registros: ['accidente_trabajo', 'enfermedad_ocupacional'], documento: 'S10_1X_ACCIDENTES_ENFERMEDADES', salud: true },
  ],
  '10.2': [
    { key: 'seguros', literal: 'a', titulo: 'Trámites y gestiones de seguro', registros: ['tramite_seguro'], documento: 'S10_2A_SEGUROS' },
    { key: 'subsidios', literal: 'b', titulo: 'Trámites y gestiones de subsidios', registros: ['tramite_subsidio'], documento: 'S10_2B_SUBSIDIOS' },
    { key: 'descansos', literal: 'c', titulo: 'Descansos médicos', consulta: true, salud: true },
    { key: 'afiliacion', literal: 'd', titulo: 'Afiliación a EPS o Seguro Social', afiliacion: true, documento: 'S10_2D_AFILIACION_EPS' },
  ],
}

// Enum del esquema (registros_sst_bienestar.tipo), sin examen_medico_realizado (pendiente de validar con la universidad).
export const tiposRegistroSst = {
  entrega_reglamento_sst: 'Entrega del Reglamento de SST', entrenamiento_simulacro: 'Entrenamiento o simulacro', capacitacion_sst: 'Capacitación',
  accidente_trabajo: 'Accidente de trabajo', enfermedad_ocupacional: 'Enfermedad ocupacional', tramite_seguro: 'Trámite de seguro', tramite_subsidio: 'Trámite de subsidio',
}
export const tramites = ['tramite_seguro', 'tramite_subsidio']
export const estadosTramite = { no_aplica: 'Sin estado registrado', en_tramite: 'En trámite', aprobado: 'Aprobado', denegado: 'Denegado' }

// afiliaciones.tipo de esta sección (sindicato es de la sección 09).
export const tiposAfiliacionSalud = { eps: 'EPS', seguro_social: 'Seguro Social' }

// Permisos de la sección: todo exige además datos_sensibles.ver (el backend valida cada ruta).
export const permisosSeccion10 = { registrar: ['legajo.editar', 'datos_sensibles.ver'], anular: ['sistema.administrar', 'datos_sensibles.ver'] }
