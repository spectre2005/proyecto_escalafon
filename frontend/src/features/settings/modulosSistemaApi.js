import api from '../../axios'

export const consultarModulosSistema = signal => api.get('/configuracion/modulos-sistema', { signal }).then(response => response.data.data)
export const guardarModuloSistema = (codigo, datos) => api.patch(`/configuracion/modulos-sistema/${codigo}`, datos).then(response => response.data.data)
