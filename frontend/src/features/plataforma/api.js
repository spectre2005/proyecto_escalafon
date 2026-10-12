import axios from 'axios'

/**
 * Cliente del panel de plataforma (api/plataforma, guard «plataforma»). Separado del cliente institucional: no comparte
 * su interceptor (que redirige a /login_principal) ni sus avisos de actualización. Misma cookie de sesión y CSRF de
 * Sanctum: la cookie XSRF-TOKEN se pide antes de iniciar sesión.
 */
const plataforma = axios.create({
  baseURL: '/api/plataforma',
  timeout: 30000,
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
})

export const iniciarSesion = async (email, password) => {
  await plataforma.get(new URL('/sanctum/csrf-cookie', window.location.origin).href)
  return (await plataforma.post('/auth/login', { email, password })).data.data
}
export const sesionActual = signal => plataforma.get('/auth/me', { signal }).then(r => r.data.data)
export const cerrarSesion = () => plataforma.post('/auth/logout')
export const resumen = signal => plataforma.get('/dashboard', { signal }).then(r => r.data.data)
export const listarInstituciones = (params, signal) => plataforma.get('/instituciones', { params, signal }).then(r => r.data)
export const verInstitucion = (id, signal) => plataforma.get(`/instituciones/${id}`, { signal }).then(r => r.data.data)
export const registrarInstitucion = datos => plataforma.post('/instituciones', datos).then(r => r.data.data)
export const editarInstitucion = (id, datos) => plataforma.patch(`/instituciones/${id}`, datos).then(r => r.data.data)
export const suspenderInstitucion = (id, motivo) => plataforma.post(`/instituciones/${id}/suspender`, { motivo }).then(r => r.data.data)
export const activarInstitucion = (id, motivo) => plataforma.post(`/instituciones/${id}/activar`, motivo ? { motivo } : {}).then(r => r.data.data)

/** Mensaje legible de un error de la API de plataforma. */
export function mensajeError(error) {
  const status = error?.response?.status
  const errores = error?.response?.data?.errors
  if (errores) return Object.values(errores).flat().join(' ')
  if (status === 401 || status === 419) return 'La sesión terminó. Vuelve a iniciar sesión.'
  if (status === 403) return 'No tienes permiso para esta acción.'
  if (status === 409 || status === 429) return error.response.data?.message || 'La operación no se puede realizar en el estado actual.'
  if (status === 404) return 'No se encontró el registro.'
  if (!error?.response) return 'No se pudo conectar con el servidor. Comprueba la conexión e inténtalo de nuevo.'
  return 'No se pudo completar la operación. Inténtalo nuevamente.'
}

export const sesionTerminada = error => [401, 419].includes(error?.response?.status)
