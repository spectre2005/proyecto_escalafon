import api from '../../axios'

// Listas de puestos y unidades orgánicas (solo sistema.administrar). tipo: 'puestos' | 'unidades-organicas'.
export const consultarCatalogo = (tipo, signal) => api.get(`/catalogos/${tipo}`, { signal }).then(response => response.data.data)
export const crearEnCatalogo = (tipo, datos) => api.post(`/catalogos/${tipo}`, datos).then(response => response.data.data)
export const actualizarEnCatalogo = (tipo, id, datos) => api.patch(`/catalogos/${tipo}/${id}`, datos).then(response => response.data.data)
