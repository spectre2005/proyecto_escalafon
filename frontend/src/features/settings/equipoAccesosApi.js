import api from '../../axios'

export const consultarEquipo = signal => api.get('/equipo-accesos', { signal }).then(response => response.data.data)
export const buscarPersonasEquipo = (q, signal) => api.get('/equipo-accesos/personas', { params: { q }, signal }).then(response => response.data.data)
export const otorgarAccesoEquipo = datos => api.post('/equipo-accesos', datos).then(response => response.data)
export const renovarAccesoEquipo = (id, datos) => api.patch(`/equipo-accesos/${id}`, datos).then(response => response.data.data)
export const revocarAccesoEquipo = (id, datos) => api.post(`/equipo-accesos/${id}/revocar`, datos).then(response => response.data.data)
export const restablecerClaveEquipo = (id, datos) => api.post(`/equipo-accesos/${id}/restablecer`, datos).then(response => response.data.data)
