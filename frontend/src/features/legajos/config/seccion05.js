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

// Descanso médico: dato de salud (num. 6.2), solo con datos_sensibles.ver.
export const tiposLicenciaSalud = ['descanso_medico']

// Enum del esquema (goces_vacacionales.tipo): goce (literal b), adelanto o postergación (literal c).
export const tiposGoce = { goce: 'Goce vacacional', adelanto: 'Adelanto de vacaciones', postergacion: 'Postergación de vacaciones' }

export const formulariosSeccion05 = { licencias_permisos: 'Licencia o permiso', goces_vacacionales: 'Goce, adelanto o postergación' }
