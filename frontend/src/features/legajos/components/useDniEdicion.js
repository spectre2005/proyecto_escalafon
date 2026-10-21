import { useEffect, useRef, useState } from 'react'
import api, { apiErrorMessage } from '../../../axios'

export function useDniEdicion(usuario, tipo, dni) {
  const secuencia = useRef(0)
  const [solicitud, setSolicitud] = useState(null)
  const [estado, setEstado] = useState({ tipo: 'inicial' })
  const valido = tipo === 'dni' && /^[0-9]{8}$/.test(dni)
  const activa = valido && solicitud?.dni === dni
  const consultando = activa && (estado.solicitud !== solicitud.id || estado.tipo === 'cargando')

  useEffect(() => {
    if (!solicitud || tipo !== 'dni' || solicitud.dni !== dni) return
    const controller = new AbortController()
    const timer = setTimeout(async () => {
      setEstado({ dni, solicitud: solicitud.id, tipo: 'cargando' })
      try {
        const respuesta = await api.post(`/expedientes/${usuario}/consulta-dni`, { dni, actualizar: solicitud.actualizar }, { signal: controller.signal, notificarCambios: false })
        if (controller.signal.aborted) return
        const persona = respuesta.data.data
        setEstado({ dni, solicitud: solicitud.id, tipo: 'completado', persona, origen: persona.origen })
      } catch (error) {
        if (controller.signal.aborted) return
        const respuesta = error.response?.data
        const mensaje = [422, 429, 503].includes(error.response?.status)
          ? Object.values(respuesta?.errors || {}).flat().join(' ') || respuesta?.message
          : apiErrorMessage(error)
        setEstado({ dni, solicitud: solicitud.id, tipo: 'error', mensaje })
      }
    }, solicitud.demora)
    return () => { clearTimeout(timer); controller.abort() }
  }, [usuario, tipo, dni, solicitud])

  function programar(tipoDocumento, numero, demora = 450, actualizar = false) {
    setEstado({ tipo: 'inicial' })
    setSolicitud(tipoDocumento === 'dni' && /^[0-9]{8}$/.test(numero) ? { dni: numero, id: ++secuencia.current, demora, actualizar } : null)
  }

  return {
    valido, consultando, cambiarDocumento: programar,
    consultar: () => programar(tipo, dni, 0),
    actualizar: () => programar(tipo, dni, 0, true),
    cerrarComparacion: () => setEstado(actual => ({ ...actual, tipo: 'revisado' })),
    estado: valido && estado.dni === dni && estado.solicitud === solicitud?.id ? estado : { tipo: 'inicial' },
  }
}
