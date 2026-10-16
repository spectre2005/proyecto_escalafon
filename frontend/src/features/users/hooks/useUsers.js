import { apiErrorMessage } from '../../../axios'
import { useLiveQuery } from '../../../shared/queries/useLiveQuery'

export function useUsers({ page = 1, perPage = 10, query = '', tipoPersonal = 'todos', estado = 'todos' } = {}) {
  const params = new URLSearchParams({ page: String(page), per_page: String(perPage), q: query, tipo_personal: tipoPersonal, estado })
  const request = useLiveQuery(`/usuarios?${params}`)
  return {
    users: request.data?.data || [],
    meta: request.data?.meta,
    summary: request.data?.resumen,
    loading: request.loading,
    loadError: request.error ? apiErrorMessage(request.error) : '',
    refreshUsers: request.retry,
  }
}

export function useUsersSummary() {
  const request = useLiveQuery('/usuarios/resumen')
  return {
    summary: request.data?.data,
    loading: request.loading,
    loadError: request.error ? apiErrorMessage(request.error) : '',
    refreshUsers: request.retry,
  }
}
