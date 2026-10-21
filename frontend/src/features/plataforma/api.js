import axios from 'axios'

/**
 * Cliente del panel de plataforma (api/plataforma, guard «plataforma»). Separado del cliente institucional: no comparte
 * su interceptor (que redirige a /login_principal) ni sus avisos de actualización. Tiene su propia cookie de sesión
 * (path /api/plataforma, no legible desde aquí), así que el token CSRF de esa sesión se pide a auth/csrf y viaja en
 * X-CSRF-TOKEN; no se envía el XSRF-TOKEN de la sesión institucional.
 */
const plataforma = axios.create({
  baseURL: '/api/plataforma',
  timeout: 30000,
  withCredentials: true,
  withXSRFToken: false,
  headers: { Accept: 'application/json' },
})

let tokenCsrf = null
const pedirToken = async () => (tokenCsrf = (await plataforma.get('/auth/csrf')).data.data.token)
export const esEscritura = metodo => !['get', 'head', 'options'].includes(String(metodo || 'get').toLowerCase())

plataforma.interceptors.request.use(async config => {
  if (esEscritura(config.method)) config.headers.set('X-CSRF-TOKEN', tokenCsrf || await pedirToken())
  return config
})
// 419: el token cambió (sesión nueva o cerrada). Se pide otro y se reintenta una sola vez.
plataforma.interceptors.response.use(undefined, async error => {
  const config = error.config
  if (error.response?.status === 419 && config && !config.reintentoCsrf) {
    tokenCsrf = null
    return plataforma.request({ ...config, reintentoCsrf: true })
  }
  return Promise.reject(error)
})

export const iniciarSesion = async (email, password) => {
  await pedirToken()
  return (await plataforma.post('/auth/login', { email, password })).data.data
}
export const sesionActual = signal => plataforma.get('/auth/me', { signal }).then(r => r.data.data)
export const cerrarSesion = () => plataforma.post('/auth/logout').finally(() => { tokenCsrf = null })
export const resumen = signal => plataforma.get('/dashboard', { signal }).then(r => r.data.data)
export const listarInstituciones = (params, signal) => plataforma.get('/instituciones', { params, signal }).then(r => r.data)
export const verInstitucion = (id, signal) => plataforma.get(`/instituciones/${id}`, { signal }).then(r => r.data.data)
export const registrarInstitucion = datos => plataforma.post('/instituciones', datos).then(r => r.data.data)
export const editarInstitucion = (id, datos) => plataforma.patch(`/instituciones/${id}`, datos).then(r => r.data.data)
export const suspenderInstitucion = (id, motivo) => plataforma.post(`/instituciones/${id}/suspender`, { motivo }).then(r => r.data.data)
export const activarInstitucion = (id, motivo) => plataforma.post(`/instituciones/${id}/activar`, motivo ? { motivo } : {}).then(r => r.data.data)
// Crear y migrar la base y cargar los catálogos toma unos segundos: más margen que el resto.
export const aprovisionarInstitucion = (id, datos) => plataforma.post(`/instituciones/${id}/aprovisionar`, datos, { timeout: 300000 }).then(r => r.data.data)
export const verPeriodos = (id, signal) => plataforma.get(`/instituciones/${id}/periodos`, { signal }).then(r => r.data.data)
export const registrarPeriodo = (id, datos) => plataforma.post(`/instituciones/${id}/periodos`, datos).then(r => r.data.data)
export const anularPeriodo = (id, periodo, motivo) => plataforma.post(`/instituciones/${id}/periodos/${periodo}/anular`, { motivo }).then(r => r.data.data)
export const listarAdministradores = (id, signal) => plataforma.get(`/instituciones/${id}/administradores`, { signal }).then(r => r.data.data)
export const agregarAdministrador = (id, datos) => plataforma.post(`/instituciones/${id}/administradores`, datos).then(r => r.data.data)
export const restablecerAdministrador = (id, admin, vence_en) => plataforma.post(`/instituciones/${id}/administradores/${admin}/restablecer`, vence_en ? { vence_en } : {}).then(r => r.data.data)
export const cambiarActivoAdministrador = (id, admin, activo) => plataforma.post(`/instituciones/${id}/administradores/${admin}/${activo ? 'reactivar' : 'desactivar'}`).then(r => r.data.data)

/** Mensaje legible de un error de la API de plataforma. */
export function mensajeError(error) {
  const status = error?.response?.status
  const errores = error?.response?.data?.errors
  if (errores) return Object.values(errores).flat().join(' ')
  if (status === 401 || status === 419) return 'La sesión terminó. Vuelve a iniciar sesión.'
  if (status === 403) return 'No tienes permiso para esta acción.'
  if (status === 409 || status === 429 || status === 503) return error.response.data?.message || 'La operación no se puede realizar en el estado actual.'
  if (status === 404) return 'No se encontró el registro.'
  if (!error?.response) return 'No se pudo conectar con el servidor. Comprueba la conexión e inténtalo de nuevo.'
  return 'No se pudo completar la operación. Inténtalo nuevamente.'
}

export const sesionTerminada = error => [401, 419].includes(error?.response?.status)

// Cuentas de superadministrador (no se borran: se desactivan) y cambio de la propia contraseña.
export const listarSuperadmins = signal => plataforma.get('/superadministradores', { signal }).then(r => r.data.data)
export const crearSuperadmin = datos => plataforma.post('/superadministradores', datos).then(r => r.data.data)
export const consultarIdentidadDni = (dni, signal) => plataforma.post('/identidad/dni', { dni }, { signal }).then(r => r.data.data)
export const consultarIdentidadRuc = (ruc, signal, actualizar = false) => plataforma.post('/identidad/ruc', { ruc, ...(actualizar && { actualizar: true }) }, { signal }).then(r => r.data.data)
export const accionSuperadmin = (id, accion, vence_en) => plataforma.post(`/superadministradores/${id}/${accion}`, accion === 'desactivar' || !vence_en ? {} : { vence_en }).then(r => r.data.data)
export const cambiarMiPassword = datos => plataforma.put('/auth/password', datos).then(r => r.data.data)
// Uso y estado técnico de una institución (se guarda unos minutos; actualizar lo vuelve a medir).
export const verUso = (id, actualizar = false, signal) => plataforma.get(`/instituciones/${id}/uso`, { params: actualizar ? { actualizar: 1 } : {}, signal }).then(r => r.data.data)

export const consultarModulosInstitucion = (id, signal) => plataforma.get(`/instituciones/${id}/modulos-sistema`, { signal }).then(r => r.data.data)
export const guardarModuloInstitucion = (id, codigo, datos) => plataforma.patch(`/instituciones/${id}/modulos-sistema/${codigo}`, datos).then(r => r.data.data)
