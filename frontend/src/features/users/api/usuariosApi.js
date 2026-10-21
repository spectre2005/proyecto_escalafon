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

// Consulta de identidad por DNI del registro manual: si está activa en la institución y si hay un proveedor.
export async function estadoConsultaIdentidad(signal) {
  const { data } = await api.get('/identidad/consulta-dni', { signal })
  return data.data
}

// El DNI va en el cuerpo, no en la URL. No crea nada: no avisa a las listas para recargarse.
export async function consultarIdentidad(dni, signal) {
  const { data } = await api.post('/identidad/consulta-dni', { dni }, { signal, sinAvisoCambios: true })
  return data.data
}
