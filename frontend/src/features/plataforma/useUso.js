import { useEffect, useState } from 'react'
import { mensajeError, sesionTerminada, verUso } from './api'
import { useSesionPlataforma } from './sesion'

/** Medición de uso de una institución: carga, error y actualización (la medición se guarda unos minutos). */
export function useUso(id) {
  const { terminada } = useSesionPlataforma()
  const [estado, setEstado] = useState({ loading: true })
  const [actualizar, setActualizar] = useState(0)
  useEffect(() => {
    const controller = new AbortController()
    verUso(id, actualizar > 0, controller.signal).then(data => setEstado({ data })).catch(failure => {
      if (controller.signal.aborted) return
      if (sesionTerminada(failure)) terminada()
      else setEstado({ error: mensajeError(failure) })
    })
    return () => controller.abort()
  }, [id, actualizar, terminada])
  return { ...estado, actualizar: () => { setEstado({ loading: true }); setActualizar(valor => valor + 1) } }
}
