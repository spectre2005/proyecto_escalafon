import api from '../../axios'

// Configuración de funcionalidades (permiso configuracion.modulos). El backend valida el permiso, el motivo,
// las restricciones y deja el cambio en la auditoría.
export const urlFuncionalidades = '/configuracion/funcionalidades'

export async function cambiarFuncionalidad(codigo, activa, motivo) {
  return (await api.patch(`${urlFuncionalidades}/${codigo}`, { activa, motivo })).data
}
