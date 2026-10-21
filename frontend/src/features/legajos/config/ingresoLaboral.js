export const modalidadesIngreso = {
  concurso_publico: 'Concurso público',
  designacion_confianza: 'Designación en cargo de confianza',
  otro_sustentado: 'Otra modalidad con sustento',
}

export const fuenteDocenciaCas = 'https://www.gob.pe/institucion/servir/informes-publicaciones/6842029-informe-tecnico-n-162-2025-servir-gpgsc'

export function tieneDocencia(data) {
  return !!data?.perfil?.tipos_personal?.includes('docente') || !!data?.vinculos?.some(v => !v.values.anulado && v.values.tipo_personal === 'docente')
}

export function coexistenDocenciaYCas(vinculos = []) {
  const vigentes = vinculos.filter(v => v.vigente && !v.values.anulado)
  return vigentes.some(v => v.values.tipo_personal === 'docente')
    && vigentes.some(v => v.values.tipo_personal === 'administrativo' && ['1057', 'CAS'].includes(String(v.codigo_ley).toUpperCase()))
}

export const camposProceso = ['proceso_seleccion_id', 'proceso_seleccion', 'anio_proceso', 'fecha_resultado_seleccion']
export const sinProceso = draft => ({ ...draft, ...Object.fromEntries(camposProceso.map(campo => [campo, ''])) })

export function cambiarModalidad(draft, modalidad) {
  let siguiente = { ...draft, modalidad_ingreso: modalidad, sustento_ingreso: modalidad === 'otro_sustentado' ? draft.sustento_ingreso : '' }
  if (modalidad !== 'concurso_publico') siguiente = sinProceso(siguiente)
  if (modalidad === 'designacion_confianza') siguiente.regimen_laboral = 'designado'
  else if (draft.regimen_laboral === 'designado') siguiente.regimen_laboral = ''
  return siguiente
}

export function cambiarTipoVinculo(draft, tipo) {
  const siguiente = { ...draft, regimen_laboral: tipo, fecha_fin_prevista: tipo === 'plazo_indeterminado' ? '' : draft.fecha_fin_prevista }
  if (tipo === 'designado') return cambiarModalidad(siguiente, 'designacion_confianza')
  if (draft.modalidad_ingreso === 'designacion_confianza') return { ...siguiente, modalidad_ingreso: '' }
  return siguiente
}

export function requisitosIngreso(draft, codigoLey, tipoPersonal = draft.tipo_personal) {
  const definido = Boolean(draft.modalidad_ingreso)
  return {
    contrato: definido && (['1057', 'CAS'].includes(codigoLey) || ['plazo_determinado', 'plazo_indeterminado'].includes(draft.regimen_laboral)),
    resolucion: definido && (tipoPersonal === 'docente' || ['carrera', 'designado'].includes(draft.regimen_laboral) || draft.modalidad_ingreso === 'designacion_confianza'),
    proceso: draft.modalidad_ingreso === 'concurso_publico',
  }
}

/** Textos de orientación: los ejemplos no generan códigos oficiales ni números de documentos. */
export function rotulosIngreso(tipoPersonal, condicionDocente, codigoLey) {
  if (tipoPersonal === 'docente') {
    const resoluciones = {
      ordinario: 'Resolución de nombramiento docente',
      contratado: 'Resolución de contratación docente',
      extraordinario: 'Resolución de incorporación docente extraordinario',
    }
    return { resolucion: resoluciones[condicionDocente] || 'Resolución de incorporación docente', contrato: 'Contrato docente', proceso: 'Ej.: CONCURSO-DOCENTE-001-2026' }
  }
  return { resolucion: 'Resolución', contrato: ['1057', 'CAS'].includes(codigoLey) ? 'Contrato CAS' : 'Contrato', proceso: ['1057', 'CAS'].includes(codigoLey) ? 'Ej.: CAS-001-2026' : 'Ej.: CONCURSO-001-2026' }
}
