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

// Registro manual: una persona con su primer vínculo laboral y sus datos del puesto (abre el legajo). Todo o nada.
export async function saveUserWithLink(payload) {
  const { data } = await api.post('/usuarios/con-vinculo', payload)
  return data.data
}

export const consultarDniPersonal = (dni, signal) => api.post('/usuarios/consulta-dni', { dni }, { signal, notificarCambios: false }).then(respuesta => respuesta.data.data)
