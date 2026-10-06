import api from '../../../axios'

// Con id: corrección (solo desvinculaciones no anuladas).
export function saveDesvinculacion(usuario, id, data) {
  const url = `/expedientes/${usuario}/seccion-11/desvinculaciones`
  return id ? api.patch(`${url}/${id}`, data) : api.post(url, data)
}

// No se elimina: se anula con motivo (sistema.administrar).
export function anularDesvinculacion(usuario, id, motivo) {
  return api.post(`/expedientes/${usuario}/seccion-11/desvinculaciones/${id}/anular`, { motivo_anulacion: motivo })
}
