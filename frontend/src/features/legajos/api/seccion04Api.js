import api from '../../../axios'

export function agregarDocumento04(usuario, datos, pdf) {
  const form = new FormData()
  Object.entries(datos).forEach(([key, value]) => {
    if (value !== null && value !== undefined && value !== '') form.append(key, typeof value === 'boolean' ? (value ? '1' : '0') : value)
  })
  form.append('pdf', pdf)
  return api.post(`/expedientes/${usuario}/seccion-04/documentos`, form)
}

export function revisarDocumento04(usuario, documento, datos) {
  return api.post(`/expedientes/${usuario}/seccion-04/documentos/${documento}/estado`, datos)
}
