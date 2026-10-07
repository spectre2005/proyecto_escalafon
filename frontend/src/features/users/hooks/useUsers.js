import { apiErrorMessage } from '../../../axios'
import { createQueryStore } from '../../../shared/queries/queryStore'
import { useLiveQuery } from '../../../shared/queries/useLiveQuery'
import { listUsers } from '../api/usuariosApi'

const usersStore = createQueryStore((_url, signal) => listUsers(signal))

export function useUsers() {
  const query = useLiveQuery('/usuarios', usersStore)
  return {
    users: query.data || [],
    setUsers: updater => query.setData(previous => typeof updater === 'function' ? updater(previous || []) : updater),
    loading: query.loading,
    loadError: query.error ? apiErrorMessage(query.error) : '',
    refreshUsers: query.retry,
  }
}
