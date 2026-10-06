import api from '../../../axios'
import { subirDocumento } from './documentosApi'

const ruta = usuario => `/expedientes/${usuario}/seccion-10/documentos`

export function agregarDocumento10(usuario, datos, pdf) {
  return subirDocumento(usuario, datos, pdf, ruta(usuario))
}

export function editarDocumento10(usuario, documento, datos) {
  return api.patch(`${ruta(usuario)}/${documento}`, datos)
}

export function revisarDocumento10(usuario, documento, datos) {
  return api.post(`${ruta(usuario)}/${documento}/estado`, datos)
}

export function anularDocumento10(usuario, documento, datos) {
  return api.post(`${ruta(usuario)}/${documento}/anular`, { motivo: datos.descripcion, estado_anterior: datos.estado_anterior })
}
