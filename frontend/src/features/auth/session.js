import { createContext, useContext } from 'react'

// Sesión del usuario: { usuario, permisos: ['legajo.ver', ...], ve_datos_sensibles }.
// Solo sirve para mostrar u ocultar opciones; el backend valida cada permiso.
export const SessionContext = createContext({ usuario: null, permisos: [], ve_datos_sensibles: false })

export function useSesion() {
  return useContext(SessionContext)
}

export function puede(sesion, permiso) {
  return !permiso || sesion.permisos.includes(permiso)
}

export function usePuede(permiso) {
  return puede(useSesion(), permiso)
}
