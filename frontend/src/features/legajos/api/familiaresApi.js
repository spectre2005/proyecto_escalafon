import api from '../../../axios'

export function saveFamiliar(usuario, id, data) {
  const url = `/expedientes/${usuario}/familiares`
  return id ? api.patch(`${url}/${id}`, data) : api.post(url, data)
}

export function deleteFamiliar(usuario, id) {
  return api.delete(`/expedientes/${usuario}/familiares/${id}`)
}
