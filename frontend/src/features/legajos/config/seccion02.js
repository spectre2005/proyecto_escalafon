// Sección 02 (Incorporación): 4 subsecciones de la directiva. Los valores de las listas los valida el
// backend (Seccion02Controller); aquí solo se agregan las etiquetas para mostrar.
export const subseccionesSeccion02 = {
  's02-seleccion': { codigo: '2.1', titulo: '2.1 Proceso de selección' },
  's02-vinculo': { codigo: '2.2', titulo: '2.2 Formalización del vínculo' },
  's02-induccion': { codigo: '2.3', titulo: '2.3 Inducción' },
  's02-prueba': { codigo: '2.4', titulo: '2.4 Período de prueba' },
}

export const tiposPersonal = { docente: 'Docente', administrativo: 'Administrativo' }

// Tipo de vínculo (vinculos_laborales.regimen_laboral). Sincronía con backend RegimenesLaborales::TIPOS_VINCULO.
export const regimenesLaborales = { carrera: 'Carrera', plazo_determinado: 'Plazo determinado', designado: 'Designado (confianza)' }

// null = Sin definir: vínculo anterior cuyo valor era una ley; se confirma en «Corregir vínculo». Nunca es un tipo.
export const tipoVinculo = valor => regimenesLaborales[valor] || 'Tipo de vínculo sin definir'

// Puesto y unidad actuales (GET /expedientes, campo puesto_actual): del vínculo vigente; sin él, del vínculo abierto más
// reciente con su situación (plazo vencido sin cese o inicio futuro: backend VigenciaLaboral::situacion, la misma de la
// constancia), o los registrados antes en la persona, que se conservan como dato histórico.
export function puestoActual(actual) {
  const nota = actual?.origen === 'persona' ? 'Sin vínculo vigente: dato registrado antes en la persona.'
    : actual?.situacion && actual.situacion.codigo !== 'vigente' ? `${actual.situacion.texto}.`
      : actual?.vinculos_vigentes > 1 ? `${actual.vinculos_vigentes} vínculos vigentes: se muestra el de inicio más reciente.` : null
  return { puesto: actual?.nombre_puesto || null, unidad: actual?.unidad_organica || null, nota }
}

// Ley y tipo de vínculo vigente de la persona (GET /expedientes/{id}, campo regimen). Sin ley: Sin definir.
export function textoRegimen(regimen) {
  const tipos = (regimen?.tipos_vinculo || []).map(tipo => regimenesLaborales[tipo] || tipo).join(' · ')
  return {
    ley: regimen?.ley?.nombre || 'Sin definir',
    tipo: [tipos, regimen?.mensaje].filter(Boolean).join(' · ') || 'Sin definir: se define al registrar el vínculo en 2.2.',
  }
}

export const tiposInduccion = { general: 'General', especifica: 'Específica' }

export const resultadosPeriodo = { pendiente: 'Pendiente', aprobado: 'Aprobado', no_aprobado: 'No aprobado' }

// Estado visible del documento (backend: App\Support\Documentos\EstadoDocumento::visible). "Recepcionado" es un
// documento cargado con recepción registrada. Los estados de fiscalización quedan preparados, sin acciones todavía.
export const estadosDocumento = { aceptado: 'Aceptado', pendiente: 'Pendiente', cargado: 'Cargado', recepcionado: 'Recepcionado', en_fiscalizacion: 'En fiscalización', verificado: 'Verificado', observado: 'Observado', falso: 'Falso' }

export const coloresEstadoDocumento = { aceptado: 'bg-teal-50 text-teal-800', pendiente: 'bg-slate-100 text-slate-600', cargado: 'bg-slate-100 text-slate-600', recepcionado: 'bg-teal-50 text-teal-800' }

// Estado de cada subsección en el resumen (backend: App\Support\ResumenSeccion02).
export const estadosSubseccion = {
  completa: ['Completa', 'bg-teal-50 text-teal-800'],
  parcial: ['Parcial', 'bg-slate-100 text-slate-600'],
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

// Enums del esquema para los datos del puesto (perfil_docente / perfil_administrativo).
export const condicionesDocente = { ordinario: 'Ordinario', extraordinario: 'Extraordinario', contratado: 'Contratado' }
export const categoriasDocente = { principal: 'Principal', asociado: 'Asociado', auxiliar: 'Auxiliar' }
export const dedicacionesDocente = { exclusiva: 'Exclusiva', tiempo_completo: 'Tiempo completo', tiempo_parcial: 'Tiempo parcial' }
export const subtiposExtraordinario = { emerito: 'Emérito', honorario: 'Honorario', investigador: 'Investigador', visitante: 'Visitante', experto: 'Experto' }
export const funcionesPrevias = { jefe_practica: 'Jefe de práctica', ayudante_catedra: 'Ayudante de cátedra', ayudante_laboratorio: 'Ayudante de laboratorio' }
export const gruposOcupacionales = { profesional: 'Profesional', tecnico: 'Técnico', auxiliar: 'Auxiliar' }

// Etiquetas de los datos del puesto.
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

// 2.4: con «Tiene período de prueba» en No solo «no aplica» (pantalla explicativa y entrada atenuada) si no hay períodos
// ni documentos m. Con datos registrados se sigue viendo, con aviso: lo registrado no se esconde. data: ficha o sección 02.
export function pruebaNoAplica(data) {
  return data?.tiene_periodo_prueba === false && !data?.prueba_con_datos
}

export function pruebaEnNoConDatos(data) {
  return data?.tiene_periodo_prueba === false && !!data?.prueba_con_datos
}
