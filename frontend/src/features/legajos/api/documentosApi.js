import api from '../../../axios'

// Documentos del legajo (PDF + metadatos), común a todas las secciones. El backend valida permisos,
// folios correlativos, tipo real del archivo y que el tipo pertenezca a la sección.
export function subirDocumento(usuario, data, pdf) {
  const form = new FormData()
  Object.entries(data).forEach(([key, value]) => { if (value !== null && value !== undefined && value !== '') form.append(key, value) })
  if (pdf) form.append('pdf', pdf)
  return api.post(`/expedientes/${usuario}/documentos`, form)
}

// Recepción (art. 4.5): quién y cuándo, una sola vez. Permiso documento.recepcionar.
export function recepcionarDocumento(usuario, id) {
  return api.post(`/expedientes/${usuario}/documentos/${id}/recepcionar`)
}

// PDF servido por el backend con sesión (nunca una URL pública). accion: ver o descargar; cada acceso queda en la bitácora.
export function archivoDocumento(usuario, id, accion = 'ver') {
  return api.get(`/expedientes/${usuario}/documentos/${id}/archivo`, { params: { accion }, responseType: 'blob' })
}

// Abre el legajo de una persona sin legajo activo (permiso legajo.abrir): número correlativo y 13 secciones.
export function abrirLegajo(usuario) {
  return api.post(`/expedientes/${usuario}/legajo`)
}
