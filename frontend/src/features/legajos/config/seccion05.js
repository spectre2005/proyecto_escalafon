// Sección 05 (Movimientos del personal), Anexo 01 de la directiva. Los valores de las listas los valida el backend
// (App\Support\Seccion05); aquí solo se agregan las etiquetas para mostrar.
// Las tres entradas del menú agrupan la vista (bloque de cada tipo de documento); no son subsecciones de la directiva.
export const categoriasSeccion05 = {
  's05-licencias': { bloque: 'licencias', titulo: 'Licencias, permisos y suspensiones', literales: 'a) y e)' },
  's05-vacaciones': { bloque: 'vacaciones', titulo: 'Vacaciones', literales: 'b), c) y d)' },
  's05-otros': { bloque: 'otros', titulo: 'Otros documentos', literales: null },
}

// Enum del esquema (licencias_permisos.tipo). Permiso, suspensión y descanso médico: párrafo general del Anexo 01; el resto, literal e).
export const tiposLicencia = {
  permiso: 'Permiso (papeleta, memorándum u otro)',
  suspension: 'Suspensión',
  descanso_medico: 'Descanso médico',
  licencia_maternidad: 'Licencia por maternidad',
  licencia_paternidad: 'Licencia por paternidad',
  lactancia: 'Lactancia',
  adopcion: 'Adopción',
  sindical: 'Sindical',
  familiar_grave: 'Familiares graves',
  comite_sst: 'Comité SST',
  asistencia_medica_familiar: 'Asistencia médica a un familiar',
  bombero_voluntario: 'Bombero voluntario',
  otra: 'Otra licencia',
}

// licencias_permisos.forma_permiso (Anexo 01: "permisos (papeletas, memorándum u otros)"): obligatoria en los permisos nuevos.
// ayudaDocumento: texto de ayuda del N.º de documento según la forma.
export const formasPermiso = {
  papeleta: { etiqueta: 'Papeleta', ayudaDocumento: 'N.º de papeleta' },
  memorandum: { etiqueta: 'Memorándum', ayudaDocumento: 'N.º de memorándum' },
  otro: { etiqueta: 'Otro', ayudaDocumento: 'N.º de documento' },
}

// Descanso médico: dato de salud (num. 6.2), solo con datos_sensibles.ver.
export const tiposLicenciaSalud = ['descanso_medico']

// Enum del esquema (goces_vacacionales.tipo): goce (literal b), adelanto o postergación (literal c).
export const tiposGoce = { goce: 'Goce vacacional', adelanto: 'Adelanto de vacaciones', postergacion: 'Postergación de vacaciones' }

export const formulariosSeccion05 = { licencias_permisos: 'Licencia o permiso', goces_vacacionales: 'Goce, adelanto o postergación' }

// Literal e): licencias otorgadas. Segundo selector del formulario cuando el movimiento es "Licencia".
export const licenciasOtorgadas = ['licencia_maternidad', 'licencia_paternidad', 'lactancia', 'adopcion', 'sindical', 'familiar_grave', 'comite_sst', 'asistencia_medica_familiar', 'bombero_voluntario', 'otra']

// Primer selector del formulario (solo de interfaz: al backend se envía licencias_permisos.tipo).
// conGoce: si se muestra la casilla "Con goce de remuneraciones". En la suspensión no se muestra ni se marca hasta que
// la universidad valide cómo se maneja (pendiente de confirmar con el cliente).
export const movimientosLicencia = {
  permiso: { etiqueta: 'Permiso', titulo: 'Registrar permiso', ayudaDocumento: 'Papeleta o memorándum', conGoce: true },
  suspension: { etiqueta: 'Suspensión', titulo: 'Registrar suspensión', ayudaDocumento: 'Resolución o memorándum', conGoce: false },
  descanso_medico: { etiqueta: 'Descanso médico', titulo: 'Registrar descanso médico', ayudaDocumento: 'Certificado o documento', conGoce: true },
  licencia: { etiqueta: 'Licencia', titulo: 'Registrar licencia', ayudaDocumento: 'Resolución o memorándum', conGoce: true },
}

// Movimiento al que pertenece un tipo de licencias_permisos (las 10 del literal e son "licencia").
export const movimientoDe = tipo => licenciasOtorgadas.includes(tipo) ? 'licencia' : tipo in movimientosLicencia ? tipo : null

// Movimientos que se ofrecen: los que admite el tipo de documento (permitidos) y, sin acceso a datos de salud, sin descanso médico.
export function opcionesMovimiento(permitidos, veSalud = true) {
  return Object.keys(movimientosLicencia).filter(movimiento => (movimiento === 'licencia'
    ? !permitidos || licenciasOtorgadas.some(tipo => permitidos.includes(tipo))
    : !permitidos || permitidos.includes(movimiento)) && (veSalud || !tiposLicenciaSalud.includes(movimiento)))
}

// Licencias del literal e) que se ofrecen (las que admite el tipo de documento).
export const opcionesLicencia = permitidos => licenciasOtorgadas.filter(tipo => !permitidos || permitidos.includes(tipo))

// Opciones del formulario de vacaciones: b) goce, c) adelanto o postergación, d) rol (solo documento).
export const movimientosVacacion = {
  goce: { literal: 'b', etiqueta: 'Goce vacacional', titulo: 'Registrar goce vacacional' },
  adelanto_postergacion: { literal: 'c', etiqueta: 'Adelanto o postergación', titulo: 'Registrar adelanto o postergación de vacaciones' },
  rol: { literal: 'd', etiqueta: 'Rol de vacaciones', titulo: 'Rol de vacaciones' },
}

// Con un documento recién subido (permitidos) solo se ofrece lo que ese documento acredita; el rol no tiene registro de datos.
export function opcionesVacacion(permitidos) {
  if (!permitidos) return Object.keys(movimientosVacacion)
  return [
    ...(permitidos.includes('goce') ? ['goce'] : []),
    ...(permitidos.some(tipo => ['adelanto', 'postergacion'].includes(tipo)) ? ['adelanto_postergacion'] : []),
  ]
}

// Etiqueta de color de cada tipo de goces_vacacionales en la lista (colores del panel).
export const coloresGoce = { goce: 'bg-teal-50 text-teal-800', adelanto: 'bg-amber-50 text-amber-800', postergacion: 'bg-amber-50 text-amber-800' }
