import axios from 'axios'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api/v1',
  timeout: 30000,
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
})

const updates = typeof BroadcastChannel !== 'undefined' ? new BroadcastChannel('escalafon-updates') : null
function notifyUpdate() {
  window.dispatchEvent(new Event('records-updated'))
}
if (updates) updates.onmessage = notifyUpdate
// Una escritura avisa a las listas para recargarse; una consulta que va por POST (sinAvisoCambios) no cambia nada.
api.interceptors.response.use(response => {
  if (['post', 'put', 'patch', 'delete'].includes(response.config.method) && !response.config.sinAvisoCambios) {
    notifyUpdate()
    updates?.postMessage('updated')
  }
  return response
}, error => {
  if ([401, 419].includes(error.response?.status) && !error.config?.url?.startsWith('/auth/') && window.location.pathname !== '/login_principal') window.location.replace('/login_principal')
  return Promise.reject(error)
})
if (import.meta.hot) import.meta.hot.dispose(() => updates?.close())

// Sanctum (SPA): pide la cookie XSRF-TOKEN en el mismo servidor que la API, fuera de /api/v1.
export function pedirCookieCsrf() {
  const apiUrl = new URL(api.defaults.baseURL, window.location.origin)
  return api.get(new URL('/sanctum/csrf-cookie', apiUrl).href)
}

/** Rechazo del backend por la institución del subdominio (no existe o está suspendida): su mensaje se muestra tal cual. */
export function mensajeInstitucion(error) {
  const data = error?.response?.data
  return String(data?.codigo || '').startsWith('institucion_') ? data.message : null
}

export function apiErrorMessage(error) {
  const institucion = mensajeInstitucion(error)
  if (institucion) return institucion
  const errors = error.response?.data?.errors
  if (errors) {
    return Object.entries(errors)
      .slice(0, 10)
      .map(([field, messages]) => {
        const match = field.match(/^usuarios\.(\d+)\.(.+)$/)
        const label = match
          ? `Registro ${Number(match[1]) + 1} (${match[2]}): `
          : ''
        return label + (Array.isArray(messages) ? messages.join(' ') : messages)
      })
      .join(' ')
  }
  if (error.response?.status === 403)
    return 'No tienes permiso para esta acción.'
  if (error.code === 'ECONNABORTED' || !error.response) {
    return 'No se pudo confirmar la operación. Comprueba la conexión y actualiza la lista antes de volver a guardar.'
  }
  return 'No se pudo completar la operación. Inténtalo nuevamente.'
}

export default api
