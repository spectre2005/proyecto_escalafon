import { useEffect, useState } from 'react'
import { consultarRuc, mensajeError } from './api'
import { cambiarRucInstitucion, completarInstitucionRuc, rucValido } from './identidadRuc'

export function useIdentidadRuc(ruc, setDatos, { habilitado = true, completarNombre = false } = {}) {
  const [estado, setEstado] = useState({ ruc: '', tipo: 'inicial' })
  const [intento, setIntento] = useState({ numero: 0, ruc: '', actualizar: false })
  const valido = rucValido(ruc)
  const consultando = habilitado && valido && (estado.ruc !== ruc || estado.tipo === 'cargando')

  useEffect(() => {
    if (!habilitado || !rucValido(ruc)) return
    const controller = new AbortController()
    const timer = setTimeout(async () => {
      setEstado({ ruc, tipo: 'cargando' })
      try {
        const empresa = await consultarRuc(ruc, controller.signal, intento.ruc === ruc && intento.actualizar)
        if (controller.signal.aborted) return
        setDatos(actual => completarInstitucionRuc(actual, empresa, { completarNombre }))
        setEstado({ ruc, tipo: 'exito', empresa })
      } catch (error) {
        if (!controller.signal.aborted) setEstado({ ruc, tipo: 'error', mensaje: mensajeError(error) })
      }
    }, 450)
    return () => { clearTimeout(timer); controller.abort() }
  }, [ruc, setDatos, habilitado, completarNombre, intento])

  function cambiarRuc(valor) {
    if (valor === ruc || !/^[0-9]{0,11}$/.test(valor)) return
    setDatos(actual => cambiarRucInstitucion(actual, valor, { completarNombre }))
    setEstado({ ruc: '', tipo: 'inicial' })
  }

  function reintentar(actualizar = false) {
    setEstado({ ruc, tipo: 'cargando' })
    setIntento(anterior => ({ numero: anterior.numero + 1, ruc, actualizar }))
  }

  return { cambiarRuc, reintentar, consultando, valido, estado: estado.ruc === ruc ? estado : { tipo: 'inicial' } }
}
