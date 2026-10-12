import { esBandera, textoBandera } from '../../../shared/banderas.js'

export const personalGroups = [
  ['Identificación personal', [
    ['nombres', 'Nombres'], ['apellido_paterno', 'Apellido paterno'], ['apellido_materno', 'Apellido materno'],
    ['tipo_doc_identidad', 'Tipo de documento'], ['numero_doc_identidad', 'Número de documento'],
    ['fecha_nacimiento', 'Fecha de nacimiento'], ['nacionalidad', 'Nacionalidad'], ['estado_civil', 'Estado civil'],
  ]],
  ['Contacto y domicilio', [
    ['email_institucional', 'Correo institucional'], ['telefono', 'Teléfono'], ['domicilio', 'Domicilio'],
    ['region', 'Región'], ['provincia', 'Provincia'], ['distrito', 'Distrito'],
  ]],
  ['Información laboral', [
    ['tipo_personal', 'Tipo de personal'], ['nombre_puesto', 'Puesto actual'], ['unidad_organica_id', 'Unidad orgánica actual'],
    // ley y tipo_vinculo: del campo regimen del expediente (DownloadPersonalReport); sin ley, «Sin definir».
    ['ley', 'Ley / régimen normativo'], ['tipo_vinculo', 'Tipo de vínculo vigente'], ['activo', 'Activo'],
  ]],
  ['Condiciones del personal', [
    ['con_discapacidad', 'Con discapacidad'], ['tiene_derechohabientes', 'Tiene derechohabientes'],
    ['obligado_djbr', 'Obligado a declarar bienes y rentas'], ['exige_colegiatura', 'Requiere colegiatura'],
    ['exige_estudios_basicos', 'Exige estudios básicos'], ['tiene_periodo_prueba', 'Tiene período de prueba'],
  ]],
]

export const familyFields = [
  ['apellidos_nombres', 'Apellidos y nombres'], ['parentesco', 'Parentesco'],
  ['tipo_doc_identidad', 'Tipo de documento'], ['numero_doc_identidad', 'Número de documento'],
  ['fecha_nacimiento', 'Fecha de nacimiento'], ['es_derechohabiente', 'Derechohabiente'],
  ['anulado', 'Registro anulado'], ['motivo_anulacion', 'Motivo de anulación'],
]

// key: con una bandera del perfil, null es «Sin responder» (no No). Un dato que no llegó (undefined, p. ej. oculto por
// datos sensibles) sigue siendo «No registrado».
export function reportValue(value, blank = false, key = null) {
  if (blank) return ''
  if (esBandera(key) && value !== undefined) return textoBandera(value)
  if (value === null || value === undefined || value === '') return 'No registrado'
  if (typeof value === 'boolean') return value ? 'Sí' : 'No'
  if (/^\d{4}-\d{2}-\d{2}$/.test(String(value))) return value.split('-').reverse().join('/')
  return String(value)
}
