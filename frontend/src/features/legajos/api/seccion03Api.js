import api from '../../../axios'

export function agregarDocumento03(usuario, datos, pdf) {
  const form = new FormData()
  Object.entries(datos).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') form.append(key, value)
  })
  form.append('pdf', pdf)
  return api.post(`/expedientes/${usuario}/seccion-03/documentos`, form)
}

export function revisarDocumento03(usuario, documento, datos) {
  return api.post(`/expedientes/${usuario}/seccion-03/documentos/${documento}/estado`, datos)
}
