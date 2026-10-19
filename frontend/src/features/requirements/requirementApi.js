import { apiErrorMessage } from '../../axios'

export function requirementError(error) {
  const data = error.response?.data
  return [409, 422].includes(error.response?.status) && !data?.errors && typeof data?.message === 'string' ? data.message : apiErrorMessage(error)
}
