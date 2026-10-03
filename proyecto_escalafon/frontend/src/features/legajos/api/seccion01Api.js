import api from '../../../axios'

export function saveFamiliar(usuario, id, data) {
  const url = `/expedientes/${usuario}/familiares`
  return id ? api.patch(`${url}/${id}`, data) : api.post(url, data)
}

export function saveRegistro(usuario, tipo, data) {
  return api.post(`/expedientes/${usuario}/${tipo}`, data)
}

// tipo: familiares, declaraciones-verificaciones o certificados-discapacidad. No se elimina: se anula.
export function anularRegistro(usuario, tipo, id, motivo) {
  return api.post(`/expedientes/${usuario}/${tipo}/${id}/anular`, { motivo_anulacion: motivo })
}
