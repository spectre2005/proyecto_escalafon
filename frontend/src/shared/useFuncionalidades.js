import { estadoVacio } from './funcionalidadesEstado'
import { useLiveQuery } from './queries/useLiveQuery'

// Estado de la configuración de funcionalidades para cualquier sesión (qué ocultar en la navegación).
export function useFuncionalidades() {
  return useLiveQuery('/funcionalidades').data?.data || estadoVacio
}
