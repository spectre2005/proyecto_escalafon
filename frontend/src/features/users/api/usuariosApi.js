import api from '../../../axios'
import { loadUserPages } from './userPages'

export async function listUsers(signal) {
  return loadUserPages(async page => {
    const { data } = await api.get('/usuarios', { params: { page }, signal })
    return data
  })
}

export async function saveUsers(usuarios) {
  const { data } = await api.post('/usuarios', { usuarios })
  return data.data
}
