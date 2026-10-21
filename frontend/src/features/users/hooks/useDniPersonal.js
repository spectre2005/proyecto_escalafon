import { useEffect, useState } from 'react'
import { consultarDniPersonal } from '../api/usuariosApi'
import { cambiarDniRegistro, completarIdentidadRegistro } from '../utils/identidadDni'

export function useDniPersonal(dni, setDraft, dominio, habilitado) {
  const [estado, setEstado] = useState({ dni: '', tipo: 'inicial' })
  const [intento, setIntento] = useState(0)
  const valido = /^[0-9]{8}$/.test(dni)
  const consultando = habilitado && valido && (estado.dni !== dni || estado.tipo === 'cargando')

  useEffect(() => {
    if (!habilitado || !/^[0-9]{8}$/.test(dni)) return
    const controller = new AbortController()
    const timer = setTimeout(async () => {
      setEstado({ dni, tipo: 'cargando' })
      try {
        const persona = await consultarDniPersonal(dni, controller.signal)
        if (controller.signal.aborted) return
        if (persona.registrada) {
          setEstado({ dni, tipo: 'existente', persona: persona.persona_existente || { nombre: 'Esta persona' } })
        } else {
          setDraft(actual => completarIdentidadRegistro(actual, persona, dominio))
          setEstado({ dni, tipo: 'completado', origen: persona.origen, ubicacion: persona.ubicacion || null })
        }
      } catch (error) {
        if (controller.signal.aborted) return
        const respuesta = error.response?.data
        const mensaje = [422, 429, 503].includes(error.response?.status)
          ? Object.values(respuesta?.errors || {}).flat().join(' ') || respuesta?.message
          : 'No se pudo consultar el DNI. Puedes completar los nombres manualmente o reintentar.'
        setEstado({ dni, tipo: 'error', mensaje })
      }
    }, 450)
    return () => { clearTimeout(timer); controller.abort() }
  }, [dni, setDraft, dominio, habilitado, intento])

  function cambiarDni(valor) {
    if (valor === dni) return
    setDraft(actual => cambiarDniRegistro(actual, valor, dominio))
    setEstado({ dni: '', tipo: 'inicial' })
  }

  function reintentar() {
    setEstado({ dni, tipo: 'cargando' })
    setIntento(valor => valor + 1)
  }

  return { estado: estado.dni === dni ? estado : { tipo: 'inicial' }, consultando, cambiarDni, reintentar }
}
