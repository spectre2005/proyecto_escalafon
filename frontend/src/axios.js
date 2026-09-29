import axios from 'axios'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api/v1',
  timeout: 30000,
  headers: { Accept: 'application/json' },
})

export function apiErrorMessage(error) {
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
    return 'La API de usuarios está habilitada únicamente en desarrollo local.'
  if (error.code === 'ECONNABORTED' || !error.response) {
    return 'No se pudo confirmar la operación. Comprueba la conexión y actualiza la lista antes de volver a guardar.'
  }
  return 'No se pudo completar la operación. Inténtalo nuevamente.'
}

export default api
