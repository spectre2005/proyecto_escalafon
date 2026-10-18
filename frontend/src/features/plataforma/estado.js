/** Estados de una institución y lo que el panel ofrece en cada uno. La regla la valida el backend; esto solo la muestra. */
export const ESTADOS = {
  registrada: { etiqueta: 'Registrada', clase: 'bg-amber-50 text-amber-800 ring-amber-200' },
  activa: { etiqueta: 'Activa', clase: 'bg-teal-50 text-teal-800 ring-teal-200' },
  suspendida: { etiqueta: 'Suspendida', clase: 'bg-rose-50 text-rose-700 ring-rose-200' },
}

/**
 * Efecto de suspender, según suspension_bloquea_acceso del backend (PLATAFORMA_MODO=subdominio): con subdominio,
 * ResolverInstitucion responde 403 a toda petición de la institución, también con sesiones ya abiertas.
 */
export const AVISO_SUSPENSION_BLOQUEA = 'Sus usuarios pierden el acceso de inmediato, también los que tienen la sesión abierta, hasta que la reactives.'

/** Solo con PLATAFORMA_MODO=unica (suspension_bloquea_acceso = false): no hay subdominio con el que bloquear. */
export const AVISO_SUSPENSION = 'Este servidor atiende una sola institución (PLATAFORMA_MODO=unica): la suspensión queda registrada, pero no bloquea el acceso. Para bloquearlo, las instituciones deben identificarse por subdominio.'

export const etiquetaEstado = estado => ESTADOS[estado]?.etiqueta || estado || '—'

/** Tiempo de uso pagado (servicio.estado del backend). Sin periodos: aún no se controla. */
export const SERVICIO = {
  sin_periodos: { etiqueta: 'Sin periodos registrados', clase: 'bg-slate-100 text-slate-600 ring-slate-200' },
  vigente: { etiqueta: 'Al día', clase: 'bg-teal-50 text-teal-800 ring-teal-200' },
  en_gracia: { etiqueta: 'Vencido, en días de gracia', clase: 'bg-amber-50 text-amber-800 ring-amber-200' },
  vencido: { etiqueta: 'Vencido: sin acceso', clase: 'bg-rose-50 text-rose-700 ring-rose-200' },
}

/** Respaldos (docker/produccion/respaldar.sh): estado de la copia externa cifrada y de la restauración de prueba. */
export const COPIA_EXTERNA = {
  correcta: { etiqueta: 'Copia externa cifrada', clase: 'text-teal-700' },
  fallida: { etiqueta: 'Falló la copia externa', clase: 'text-rose-700' },
  no_configurada: { etiqueta: 'Sin copia externa', clase: 'text-amber-800' },
}
export const VERIFICACION = {
  correcta: { etiqueta: 'Restauración probada', clase: 'text-teal-700' },
  fallida: { etiqueta: 'Falló la restauración de prueba', clase: 'text-rose-700' },
}

/** Tamaño legible: 1536 → «1,5 KB». */
export function tamano(bytes) {
  if (bytes === null || bytes === undefined || Number.isNaN(Number(bytes))) return '—'
  const unidades = ['B', 'KB', 'MB', 'GB', 'TB']
  let valor = Number(bytes)
  let indice = 0
  while (valor >= 1024 && indice < unidades.length - 1) { valor /= 1024; indice++ }
  // Coma decimal sin depender de los datos de idioma del entorno (Node sin ICU completo daría «1.5»).
  const texto = indice === 0 ? String(valor) : (Math.round(valor * 10) / 10).toString().replace('.', ',')
  return `${texto} ${unidades[indice]}`
}

/** Fecha del backend (AAAA-MM-DD) como DD/MM/AAAA, sin pasar por zonas horarias. */
export const fechaCorta = valor => /^\d{4}-\d{2}-\d{2}/.test(valor || '') ? valor.slice(0, 10).split('-').reverse().join('/') : '—'

/** Texto del estado del servicio: «Al día hasta el 31/12/2026 (15 días)», «Vencido el …», etc. */
export function textoServicio(servicio) {
  if (!servicio) return '—'
  if (servicio.estado === 'vigente') return `Al día hasta el ${fechaCorta(servicio.vence_el)} (${servicio.dias_restantes === 0 ? 'vence hoy' : `${servicio.dias_restantes} día${servicio.dias_restantes === 1 ? '' : 's'}`})`
  if (servicio.estado === 'en_gracia') return `Venció el ${fechaCorta(servicio.vence_el)}; funciona hasta el ${fechaCorta(servicio.gracia_hasta)} por días de gracia`
  if (servicio.estado === 'vencido') return servicio.vence_el ? `Venció el ${fechaCorta(servicio.vence_el)}: la institución no tiene acceso` : `Sin periodo vigente${servicio.proximo_desde ? ` (el siguiente empieza el ${fechaCorta(servicio.proximo_desde)})` : ''}: la institución no tiene acceso`
  return 'Aún no se registró ningún pago: el acceso no se controla por tiempo.'
}

/**
 * Periodo propuesto para un pago nuevo: empieza el día siguiente al último periodo vigente (o hoy, si no hay ninguno
 * o ya terminó) y dura un año. hoy: AAAA-MM-DD. El usuario puede cambiarlo.
 */
export function proponerPeriodo(periodos, hoy) {
  const ultimo = (periodos || []).filter(periodo => !periodo.anulado).map(periodo => periodo.hasta).sort().at(-1)
  const desde = ultimo && ultimo >= hoy ? sumarDias(ultimo, 1) : hoy
  const [anio, mes, dia] = desde.split('-').map(Number)
  return { desde, hasta: sumarDias(new Date(Date.UTC(anio + 1, mes - 1, dia)).toISOString().slice(0, 10), -1) }
}

function sumarDias(fecha, dias) {
  const [anio, mes, dia] = fecha.split('-').map(Number)
  return new Date(Date.UTC(anio, mes - 1, dia + dias)).toISOString().slice(0, 10)
}

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

/** Datos del cliente y del contrato (opcionales). */
export const CAMPOS_CLIENTE = [
  { campo: 'razon_social', etiqueta: 'Razón social', max: 200 },
  { campo: 'ruc', etiqueta: 'RUC', max: 11, ayuda: '11 dígitos; se comprueba el dígito verificador.' },
  { campo: 'contrato_numero', etiqueta: 'N.º de contrato', max: 60 },
  { campo: 'contrato_inicio', etiqueta: 'Inicio del contrato', tipo: 'date' },
  { campo: 'contacto_nombre', etiqueta: 'Contacto', max: 150 },
  { campo: 'contacto_email', etiqueta: 'Correo de contacto', tipo: 'email', max: 255 },
  { campo: 'contacto_telefono', etiqueta: 'Teléfono de contacto', tipo: 'tel', max: 20 },
]

/** Solo los datos del cliente que cambiaron, sin espacios sobrantes; vacío = quitar el dato (null). */
export function cambiosCliente(original, borrador) {
  return Object.fromEntries(CAMPOS_CLIENTE.map(({ campo }) => campo)
    .filter(campo => String(borrador?.[campo] ?? '').trim() !== String(original?.[campo] ?? ''))
    .map(campo => [campo, String(borrador[campo]).trim() || null]))
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
  'respaldo.fallido': 'Falló un respaldo',
  'respaldo.verificacion_fallida': 'Falló la restauración de prueba de un respaldo',
  'periodo.registrado': 'Registró un pago (periodo de uso)',
  'periodo.anulado': 'Anuló un periodo de uso',
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
