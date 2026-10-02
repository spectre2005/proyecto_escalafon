import api from '../../../axios'

export function saveFamiliar(usuario, id, data) {
  const url = `/expedientes/${usuario}/familiares`
  return id ? api.patch(`${url}/${id}`, data) : api.post(url, data)
}

export function saveRegistro(usuario, tipo, data) {
  return api.post(`/expedientes/${usuario}/${tipo}`, data)
}

export function saveCertificado(usuario, id, data, pdf) {
  const form = new FormData()
  Object.entries(data).forEach(([key, value]) => form.append(key, value ?? ''))
  if (pdf) form.append('pdf', pdf)
  if (id) form.append('_method', 'PATCH')
  const url = `/expedientes/${usuario}/certificados-discapacidad`
  return api.post(id ? `${url}/${id}` : url, form)
}

export function descargarCertificado(usuario, id) {
  return api.get(`/expedientes/${usuario}/certificados-discapacidad/${id}/pdf`, { responseType: 'blob' })
}

// tipo: familiares, declaraciones-verificaciones o certificados-discapacidad. No se elimina: se anula.
export function anularRegistro(usuario, tipo, id, motivo) {
  return api.post(`/expedientes/${usuario}/${tipo}/${id}/anular`, { motivo_anulacion: motivo })
}
