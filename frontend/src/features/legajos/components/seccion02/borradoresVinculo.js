import { valorBandera } from '../../../../shared/banderas.js'
import { leyesDelTipo } from '../../../users/utils/leyes.js'

// Borradores del vínculo y de los datos del puesto, compartidos por la sección 02 y el registro manual de personal.

// Borrador del vínculo (sin tipo de personal ni ley, que cada formulario agrega). Condiciones: Sí / No / Sin responder.
export function vinculoInicial(v = {}) {
  return {
    regimen_laboral: v.regimen_laboral || '', unidad_organica_id: v.unidad_organica_id || '', nombre_puesto: v.nombre_puesto || '',
    numero_documento_vinculo: v.numero_documento_vinculo || '', fecha_inicio: v.fecha_inicio || '', fecha_fin_prevista: v.fecha_fin_prevista || '',
    numero_resolucion: v.numero_resolucion || '', fecha_resolucion: v.fecha_resolucion || '',
    numero_contrato: v.numero_contrato || '', fecha_contrato: v.fecha_contrato || '', anio_proceso: v.anio_proceso ?? '',
    modalidad_ingreso: v.modalidad_ingreso || '', sustento_ingreso: v.sustento_ingreso || '', proceso_seleccion_id: v.proceso_seleccion_id || '',
    proceso_seleccion: v.proceso_seleccion || '', fecha_resultado_seleccion: v.fecha_resultado_seleccion || '',
    obligado_djbr: valorBandera(v.obligado_djbr), exige_colegiatura: valorBandera(v.exige_colegiatura), exige_estudios_basicos: valorBandera(v.exige_estudios_basicos), tiene_periodo_prueba: valorBandera(v.tiene_periodo_prueba),
  }
}

// Un CAS adicional pertenece a la misma persona; no hereda la resolución, la convocatoria ni el perfil docente.
export function nuevoVinculoInicial(persona, catalogo, { casAdicional = false } = {}) {
  const tipo = casAdicional ? 'administrativo' : persona?.usuario?.values?.tipo_personal || ''
  const leyes = leyesDelTipo(catalogo, tipo)
  const ley = casAdicional
    ? leyes.find(item => ['1057', 'CAS'].includes(String(item.codigo).toUpperCase()))
    : leyes.find(item => item.id === persona?.edit_values?.regimen_normativo_id)
  return { ...vinculoInicial(), tipo_personal: tipo, regimen_normativo_id: ley?.id || '', fecha_cese: '' }
}

// Un alta normal no lleva cese, incluso si quedó una fecha en un borrador histórico anterior.
export function vinculoParaGuardar(draft, { historico = false, correccion = false } = {}) {
  return { ...draft, fecha_cese: historico || correccion ? draft.fecha_cese || null : null }
}

// Borrador de los datos del puesto (perfil_docente o perfil_administrativo).
export function datosPuestoInicial(docente, v = {}) {
  return docente
    ? { condicion_docente: v.condicion_docente || '', categoria: v.categoria || '', dedicacion: v.dedicacion || '', subtipo_extraordinario: v.subtipo_extraordinario || '', funcion_previa: v.funcion_previa || '', codigo_renacyt: v.codigo_renacyt || '' }
    : { grupo_ocupacional: v.grupo_ocupacional || '', nivel_carrera: v.nivel_carrera || '', cargo_estructural: v.cargo_estructural || '', es_cargo_confianza: v.es_cargo_confianza ?? false }
}

// La categoría es solo de ordinarios y el subtipo, de extraordinarios: si no corresponde, se envía vacío.
export function limpiarDatosPuesto(docente, data) {
  return docente ? { ...data, categoria: data.condicion_docente === 'ordinario' ? data.categoria : null, subtipo_extraordinario: data.condicion_docente === 'extraordinario' ? data.subtipo_extraordinario : null } : data
}
