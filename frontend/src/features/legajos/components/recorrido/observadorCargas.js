import { esCargaDocumento } from '../../config/recorrido.js'

/** Observa el resultado sin bloquear, cambiar ni repetir la petición del formulario. */
export function observarCargas(api, usuario, onCarga, onError) {
  let activo = true
  const interceptor = api.interceptors.response.use(response => {
    if (response.status === 201 && esCargaDocumento(response.config, usuario) && response.data?.data?.id) {
      queueMicrotask(() => {
        if (activo) Promise.resolve().then(() => { if (activo) return onCarga(response.data.data.id) }).catch(onError)
      })
    }
    return response
  }, failure => {
    if (activo && esCargaDocumento(failure.config, usuario)) onError(failure)
    return Promise.reject(failure)
  })
  return () => { activo = false; api.interceptors.response.eject(interceptor) }
}
