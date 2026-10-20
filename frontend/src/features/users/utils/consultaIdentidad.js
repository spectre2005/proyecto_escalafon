import { updateUserDraft } from './usuariosEmail.js'

// Consulta de identidad por DNI del paso 1 del registro manual (POST /identidad/consulta-dni). Solo propone nombres y
// apellidos: los campos siguen editables, lo escrito no se reemplaza sin confirmación y el registro nunca depende de
// ella. El backend decide el proveedor, los permisos, el módulo de la institución y el límite de consultas.

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

/** Valores de la respuesta para el borrador (apellido materno ausente = vacío). */
export function valoresIdentidad(datos) {
  return Object.fromEntries(CAMPOS_IDENTIDAD.map(campo => [campo, String(datos?.[campo] ?? '').trim()]))
}

/** Campos ya escritos que la respuesta cambiaría: si hay alguno, se pide confirmación antes de reemplazar. */
export function camposPorReemplazar(draft, datos) {
  const nuevos = valoresIdentidad(datos)
  return CAMPOS_IDENTIDAD.filter(campo => String(draft[campo] || '').trim() && String(draft[campo]).trim() !== nuevos[campo])
}

/**
 * Borrador con los nombres y apellidos de la respuesta, campo por campo con updateUserDraft: el correo sugerido se
 * actualiza igual que al escribirlos, y uno escrito a mano se conserva.
 */
export function aplicarIdentidad(draft, datos, dominio = '') {
  const nuevos = valoresIdentidad(datos)
  return CAMPOS_IDENTIDAD.reduce((borrador, campo) => updateUserDraft(borrador, campo, nuevos[campo], dominio), draft)
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
