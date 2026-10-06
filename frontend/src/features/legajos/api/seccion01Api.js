import api from '../../../axios'

// Datos de la sección 01. Al registrar se puede enlazar el documento recién subido que el registro acredita
// (documento_id); el enlace no cambia después. Los PDF se suben como documentos del legajo (documentosApi).
export function saveFamiliar(usuario, id, data) {
  const url = `/expedientes/${usuario}/familiares`
  return id ? api.patch(`${url}/${id}`, data) : api.post(url, data)
}

// tipo: declaraciones-verificaciones o certificados-discapacidad.
export function saveRegistro(usuario, tipo, id, data) {
  const url = `/expedientes/${usuario}/${tipo}`
  return id ? api.patch(`${url}/${id}`, data) : api.post(url, data)
}

// PDF guardado antes del sistema de folios (solo lectura). La descarga queda en la bitácora.
export function descargarRegistroPdf(usuario, tipo, id) {
  return api.get(`/expedientes/${usuario}/${tipo}/${id}/pdf`, { responseType: 'blob' })
}

// tipo: familiares, declaraciones-verificaciones o certificados-discapacidad. No se elimina: se anula.
export function anularRegistro(usuario, tipo, id, motivo) {
  return api.post(`/expedientes/${usuario}/${tipo}/${id}/anular`, { motivo_anulacion: motivo })
}
