import api from '../../../axios'

// tipo: evaluaciones-desempeno, progresiones-carrera, ratificaciones-docentes o desplazamientos. Con id: corrección (solo registros no anulados).
export function saveSeccion07(usuario, tipo, id, data) {
  const url = `/expedientes/${usuario}/seccion-07/${tipo}`
  return id ? api.patch(`${url}/${id}`, data) : api.post(url, data)
}

// No se elimina: se anula con motivo (seccion07.registrar y sistema.administrar).
export function anularSeccion07(usuario, tipo, id, motivo) {
  return api.post(`/expedientes/${usuario}/seccion-07/${tipo}/${id}/anular`, { motivo_anulacion: motivo })
}
