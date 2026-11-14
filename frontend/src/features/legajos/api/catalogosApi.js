import api from '../../../axios'

// Catálogos del vínculo (unidades, puestos, modalidades y procesos de selección). Crear y corregir exige catalogos.gestionar; el
// backend asigna el código correlativo y audita. No se eliminan: se desactivan con { activo: false }.
export function crearElementoCatalogo(catalogo, data) {
  return api.post(`/catalogos-vinculo/${catalogo}`, data)
}

export function actualizarElementoCatalogo(catalogo, id, data) {
  return api.patch(`/catalogos-vinculo/${catalogo}/${id}`, data)
}
