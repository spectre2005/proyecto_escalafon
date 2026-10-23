import { updateUserDraft } from './usuariosEmail.js'

// Consulta de identidad por DNI del paso 1 del registro manual (POST /identidad/consulta-dni). Propone nombres y
// apellidos y, para quien ve datos sensibles, la ubicación del catálogo: los campos siguen editables, lo escrito no se
// reemplaza sin confirmación y el registro nunca depende de ella. El backend decide el proveedor, los permisos, el módulo
// de la institución, el límite de consultas y el emparejamiento de la ubicación con el catálogo.

export const CAMPOS_IDENTIDAD = ['nombres', 'apellido_paterno', 'apellido_materno']
export const AVISO_SIMULACION = 'Simulación: datos ficticios, no consultados a RENIEC'
export const NO_DISPONIBLE = 'La consulta de identidad no está disponible. Completa los datos manualmente.'
const dniValido = dni => /^\d{8}$/.test(String(dni || '').trim())

/**
 * Qué mostrar junto al DNI: 'boton' (consultar), 'no_disponible' (la institución la tiene activa pero no hay proveedor) o
 * null. Nada si el DNI no tiene ocho dígitos, si la persona ya está registrada (se conserva su aviso, sin consultar) o si
 * el usuario no puede registrar.
 */
export function modoConsulta({ dni, existente, estado, puedeRegistrar }) {
  if (!puedeRegistrar || !dniValido(dni) || existente || !estado?.habilitada) return null
  return estado.disponible ? 'boton' : 'no_disponible'
}

/** Espera tras el octavo dígito antes de consultar sola: corregir un dígito no gasta una consulta. */
export const ESPERA_CONSULTA_MS = 450

/**
 * Si se consulta sola al completar el DNI: solo cuando el botón estaría disponible, el formulario no está ocupado y ese
 * DNI no se consultó ya (para repetirla queda el botón). Así una consulta fallida no se repite en bucle.
 */
export function consultarAlCompletar({ modo, dni, ultimoConsultado, ocupado }) {
  return modo === 'boton' && !ocupado && dniValido(dni) && String(dni).trim() !== ultimoConsultado
}

/**
 * Valores de la respuesta para el borrador (apellido materno ausente = vacío). La dirección, si llega con texto, propone el
 * domicilio; si no llega (o el usuario no ve datos sensibles), el domicilio no se toca.
 */
export function valoresIdentidad(datos) {
  const valores = Object.fromEntries(CAMPOS_IDENTIDAD.map(campo => [campo, String(datos?.[campo] ?? '').trim()]))
  const direccion = String(datos?.direccion ?? '').trim()
  return direccion ? { ...valores, domicilio: direccion } : valores
}

/** Campos ya escritos que la respuesta cambiaría: si hay alguno, se pide confirmación antes de reemplazar. */
export function camposPorReemplazar(draft, datos) {
  const nuevos = valoresIdentidad(datos)
  return Object.keys(nuevos).filter(campo => String(draft[campo] || '').trim() && String(draft[campo]).trim() !== nuevos[campo])
}

/**
 * Borrador con los nombres y apellidos de la respuesta, campo por campo con updateUserDraft: el correo sugerido se
 * actualiza igual que al escribirlos, y uno escrito a mano se conserva.
 */
export function aplicarIdentidad(draft, datos, dominio = '') {
  const nuevos = valoresIdentidad(datos)
  return Object.keys(nuevos).reduce((borrador, campo) => updateUserDraft(borrador, campo, nuevos[campo], dominio), draft)
}

/** Al cambiar el DNI, el domicilio que trajo la consulta anterior se borra; uno escrito o corregido a mano se conserva. */
export function domicilioTrasCambioDni(draft, origen) {
  const traido = origen?.valores?.domicilio
  return traido && draft.domicilio === traido ? { ...draft, domicilio: '' } : draft
}

/** Mensaje para el usuario según la respuesta del backend; nunca el detalle técnico. Siempre deja seguir a mano. */
export function mensajeErrorConsulta(error) {
  const { status, data } = error?.response || {}
  const manual = 'Puedes completar los datos manualmente.'
  if (data?.codigo === 'limite_consultas') return `Se alcanzó el límite de consultas por ahora. ${manual}`
  if (data?.codigo === 'proveedor_no_configurado' || data?.codigo === 'modulo_desactivado') return NO_DISPONIBLE
  if (data?.codigo === 'proveedor_no_disponible') return `El servicio de identidad no respondió. ${manual}`
  if (status === 403) return `Tu rol no puede consultar datos de identidad. ${manual}`
  if (status === 422) return `Revisa el DNI: debe tener 8 dígitos. ${manual}`
  if (!error?.response) return `No se pudo conectar para consultar. ${manual}`
  return `No se pudo completar la consulta. ${manual}`
}

/**
 * Una consulta a la vez. consultar(dni) devuelve { estado: 'ok', datos } | { estado: 'error', error } |
 * { estado: 'duplicada' } (ya hay una en curso) | { estado: 'obsoleta' } (se canceló o empezó otra: no se aplica).
 * cancelar() aborta la consulta en curso, p. ej. al cambiar el DNI. pedir(dni, signal) hace la petición.
 */
export function crearConsultaIdentidad(pedir) {
  let actual = null
  return {
    get enCurso() { return actual !== null },
    cancelar() {
      actual?.controlador.abort()
      actual = null
    },
    async consultar(dni) {
      if (actual) return { estado: 'duplicada' }
      const propia = { dni, controlador: new AbortController() }
      actual = propia
      try {
        const datos = await pedir(dni, propia.controlador.signal)
        return actual === propia ? { estado: 'ok', datos } : { estado: 'obsoleta' }
      } catch (error) {
        return actual === propia && !propia.controlador.signal.aborted ? { estado: 'error', error } : { estado: 'obsoleta' }
      } finally {
        if (actual === propia) actual = null
      }
    },
  }
}

// Ubicación del registro: { value: { region, provincia, distrito }, id, origen }. origen es null (vacía), 'manual'
// (elegida por el usuario) o { dni } (completada por la consulta de ese DNI).
export const UBICACION_VACIA = Object.freeze({ value: { region: '', provincia: '', distrito: '' }, id: '', origen: null })

/** Selección en los campos de ubicación: es manual si eligió algo; vaciarla la deja libre para una consulta. */
export function ubicacionManual(value, id) {
  return { value, id: id || '', origen: Object.values(value).some(Boolean) ? 'manual' : null }
}

/**
 * Ubicación tras una respuesta del DNI dni: la elegida a mano se conserva; si el backend encontró un único lugar del
 * catálogo (ubicacion.id), se completa con él. Sin coincidencia, ambigua o sin dato, no cambia nada.
 */
export function ubicacionTrasConsulta(actual, dni, datos) {
  const encontrada = datos?.ubicacion
  if (actual.origen === 'manual' || !encontrada?.id) return actual
  return { value: { region: encontrada.region, provincia: encontrada.provincia, distrito: encontrada.distrito }, id: encontrada.id, origen: { dni } }
}

/** Al cambiar el DNI, la ubicación que trajo la consulta de otra persona se descarta; la elegida a mano se conserva. */
export function ubicacionTrasCambioDni(actual, dni) {
  return actual.origen && actual.origen !== 'manual' && actual.origen.dni !== dni ? UBICACION_VACIA : actual
}

/** Aviso sobre la ubicación de la respuesta (null si no hay nada que decir o el usuario no ve ubicaciones). */
export function mensajeUbicacion(actual, datos) {
  const estado = datos?.ubicacion_estado
  if (estado === 'asignada') return actual.origen === 'manual' ? 'Se conservó la ubicación que elegiste.' : 'Se completó la ubicación con el catálogo. Revísala antes de continuar.'
  if (estado === 'sin_coincidencia' || estado === 'ambigua') return 'La ubicación del DNI no coincide con un único lugar del catálogo: selecciónala manualmente.'
  // El proveedor no la envió: se avisa, salvo que ya haya una elegida a mano.
  if (estado === 'sin_dato' && actual.origen !== 'manual') return 'El servicio no informó la ubicación de este DNI: selecciónala manualmente.'
  return null
}
