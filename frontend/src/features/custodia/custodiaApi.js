import { apiErrorMessage } from '../../axios'

export function custodiaError(error) {
  const data = error.response?.data
  return [403, 404, 409, 422].includes(error.response?.status) && !data?.errors && typeof data?.message === 'string' ? data.message : apiErrorMessage(error)
}
