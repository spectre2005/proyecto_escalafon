import api from '../../../axios'

// tipo: registros (SST y bienestar) o afiliaciones (EPS o seguro social). Con id: corrección (solo registros no anulados).
export function saveSeccion10(usuario, tipo, id, data) {
  const url = `/expedientes/${usuario}/seccion-10/${tipo}`
  return id ? api.patch(`${url}/${id}`, data) : api.post(url, data)
}

// No se elimina: se anula con motivo (sistema.administrar y datos_sensibles.ver).
export function anularSeccion10(usuario, tipo, id, motivo) {
  return api.post(`/expedientes/${usuario}/seccion-10/${tipo}/${id}/anular`, { motivo_anulacion: motivo })
}
