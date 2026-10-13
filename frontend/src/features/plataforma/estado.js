/** Estados de una institución y lo que el panel ofrece en cada uno. La regla la valida el backend; esto solo la muestra. */
export const ESTADOS = {
  registrada: { etiqueta: 'Registrada', clase: 'bg-amber-50 text-amber-800 ring-amber-200' },
  activa: { etiqueta: 'Activa', clase: 'bg-teal-50 text-teal-800 ring-teal-200' },
  suspendida: { etiqueta: 'Suspendida', clase: 'bg-rose-50 text-rose-700 ring-rose-200' },
}

/** Solo se muestra si la institución no se identifica por subdominio (suspension_bloquea_acceso = false). */
export const AVISO_SUSPENSION = 'La suspensión queda registrada, pero aún no bloquea el acceso de la institución.'

export const etiquetaEstado = estado => ESTADOS[estado]?.etiqueta || estado || '—'

/** Acciones disponibles: aprovisionar solo si está registrada y sin aprovisionar; activar solo si está aprovisionada; suspender solo si está activa. */
export function accionesDisponibles(institucion) {
  if (!institucion) return { aprovisionar: false, activar: false, suspender: false, textoActivar: 'Activar' }
  return {
    aprovisionar: institucion.estado === 'registrada' && !institucion.aprovisionada,
    activar: !institucion.motivo_no_activable,
    suspender: !institucion.motivo_no_suspendible,
    textoActivar: institucion.estado === 'suspendida' ? 'Reactivar' : 'Activar',
  }
}

/** Identificador a partir del nombre: minúsculas, sin tildes, guiones; 3 a 40 caracteres. */
export function proponerSlug(nombre) {
  return String(nombre || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40).replace(/-+$/, '')
}

/** Base de datos y almacenamiento propuestos (los mismos que asigna el backend si se dejan vacíos). */
export function proponerUbicacion(slug) {
  const limpio = String(slug || '').trim().toLowerCase()
  if (!limpio) return { base_datos: '', almacenamiento: '' }
  return { base_datos: `escalafon_${limpio.replaceAll('-', '_')}`, almacenamiento: `instituciones/${limpio}` }
}

/** Solo los campos cambiados (para PATCH); los de ubicación solo si siguen editables. */
export function cambios(original, borrador) {
  const campos = original?.ubicacion_editable ? ['nombre', 'slug', 'base_datos', 'almacenamiento'] : ['nombre']
  return Object.fromEntries(campos.filter(campo => String(borrador[campo] ?? '').trim() !== String(original?.[campo] ?? '')).map(campo => [campo, String(borrador[campo]).trim()]))
}

const ACCIONES = {
  'institucion.registrada': 'Registró la institución',
  'institucion.editada': 'Editó sus datos',
  'institucion.suspendida': 'Suspendió la institución',
  'institucion.activada': 'Activó la institución',
  'institucion.aprovisionada': 'Quedó aprovisionada',
  'institucion.aprovisionamiento_fallido': 'Falló el aprovisionamiento',
  'institucion.migrada': 'Se migró su base',
  'institucion.migracion_fallida': 'Falló la migración de su base',
  'administrador_institucion.creado': 'Agregó un administrador',
  'administrador_institucion.clave_restablecida': 'Dio una contraseña temporal nueva a un administrador',
  'administrador_institucion.desactivado': 'Desactivó a un administrador',
  'administrador_institucion.reactivado': 'Reactivó a un administrador',
  'sesion.inicio': 'Inició sesión',
  'sesion.cierre': 'Cerró sesión',
  'administrador.creado': 'Se creó una cuenta de administración',
}
export const etiquetaAccion = accion => ACCIONES[accion] || accion

export function fecha(iso) {
  if (!iso) return '—'
  const valor = new Date(iso)
  return Number.isNaN(valor.getTime()) ? '—' : valor.toLocaleString('es-PE', { dateStyle: 'medium', timeStyle: 'short' })
}
