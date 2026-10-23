import { apiErrorMessage } from '../../axios'

export function mensajeError(error) {
  if (error.response?.data?.errors) return apiErrorMessage(error)
  if ([404, 409, 422, 423, 429].includes(error.response?.status)) return error.response.data?.message || apiErrorMessage(error)
  return apiErrorMessage(error)
}
