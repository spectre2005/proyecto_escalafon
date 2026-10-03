// Sección 02 (Incorporación): 4 subsecciones de la directiva. Los valores de las listas los valida el
// backend (Seccion02Controller); aquí solo se agregan las etiquetas para mostrar.
export const subseccionesSeccion02 = {
  's02-seleccion': { codigo: '2.1', titulo: '2.1 Proceso de selección' },
  's02-vinculo': { codigo: '2.2', titulo: '2.2 Formalización del vínculo' },
  's02-induccion': { codigo: '2.3', titulo: '2.3 Inducción' },
  's02-prueba': { codigo: '2.4', titulo: '2.4 Período de prueba' },
}

export const tiposPersonal = { docente: 'Docente', administrativo: 'Administrativo' }

// Enum del esquema (vinculos_laborales.regimen_laboral).
export const regimenesLaborales = { carrera: 'Carrera', plazo_determinado: 'Plazo determinado', designado: 'Designado (confianza)' }

export const tiposInduccion = { general: 'General', especifica: 'Específica' }

export const resultadosPeriodo = { pendiente: 'Pendiente', aprobado: 'Aprobado', no_aprobado: 'No aprobado' }

export const estadosDocumento = { pendiente: 'Pendiente', cargado: 'Cargado', en_fiscalizacion: 'En fiscalización', verificado: 'Verificado', observado: 'Observado', falso: 'Falso' }

// Estado de cada subsección en el resumen (backend: App\Support\ResumenSeccion02).
export const estadosSubseccion = {
  completa: ['Completa', 'bg-teal-50 text-teal-800'],
  parcial: ['Parcial', 'bg-amber-50 text-amber-800'],
  sin_datos: ['Sin datos', 'bg-slate-100 text-slate-600'],
  no_aplica: ['No aplica', 'bg-slate-100 text-slate-400'],
}

// Vigencia de un vínculo (backend: Seccion02Controller::vinculo).
export const estadosVencimiento = {
  vigente: ['Vigente', 'bg-teal-50 text-teal-800'],
  sin_fin: ['Vigente · sin fecha de fin', 'bg-teal-50 text-teal-800'],
  por_vencer: ['Por vencer', 'bg-amber-50 text-amber-800'],
  vencido: ['Vencido', 'bg-rose-50 text-rose-700'],
  cesado: ['Cesado', 'bg-slate-100 text-slate-600'],
  por_iniciar: ['Por iniciar', 'bg-slate-100 text-slate-600'],
}

export const formulariosDatos = { vinculos_laborales: 'Vínculo laboral', vinculo_adendas: 'Adenda', inducciones: 'Inducción', periodos_prueba: 'Período de prueba' }

// Datos del puesto (perfil_docente / perfil_administrativo), solo lectura.
export const camposDatosPuesto = {
  condicion_docente: 'Condición docente', categoria: 'Categoría', dedicacion: 'Dedicación',
  subtipo_extraordinario: 'Subtipo (extraordinario)', funcion_previa: 'Función previa', codigo_renacyt: 'Código RENACYT',
  grupo_ocupacional: 'Grupo ocupacional', nivel_carrera: 'Nivel de carrera', cargo_estructural: 'Cargo estructural', es_cargo_confianza: 'Cargo de confianza',
}

export function etiquetaVinculo(vinculo) {
  const v = vinculo.values
  const desde = v.fecha_inicio ? v.fecha_inicio.split('-').reverse().join('/') : 'sin fecha'
  return `${v.nombre_puesto} · ${v.numero_documento_vinculo || 'sin N.º de documento'} · desde ${desde}`
}
