import api from '../../../axios'

// tipo: controversias o afiliaciones. Con id: corrección (solo registros no anulados).
export function saveSeccion09(usuario, tipo, id, data) {
  const url = `/expedientes/${usuario}/seccion-09/${tipo}`
  return id ? api.patch(`${url}/${id}`, data) : api.post(url, data)
}

// No se elimina: se anula con motivo (sistema.administrar y datos_sensibles.ver).
export function anularSeccion09(usuario, tipo, id, motivo) {
  return api.post(`/expedientes/${usuario}/seccion-09/${tipo}/${id}/anular`, { motivo_anulacion: motivo })
}
