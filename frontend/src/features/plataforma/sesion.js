import { createContext, useContext } from 'react'

/** Sesión del panel de plataforma: { administrador, salir }. */
export const SesionPlataforma = createContext(null)
export const useSesionPlataforma = () => useContext(SesionPlataforma)
