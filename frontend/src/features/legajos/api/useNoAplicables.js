import { useMemo } from 'react'
import { useLiveQuery } from '../../../shared/queries/useLiveQuery'
import { indiceNoAplica } from '../config/aplicacion'

// Índice de los tipos de documento que no corresponden a la persona (ver config/aplicacion.js).
export function useNoAplicables(usuario) {
  const consulta = useLiveQuery(`/expedientes/${usuario}/documentos/no-aplicables`)
  return useMemo(() => indiceNoAplica(consulta.data?.data), [consulta.data])
}
