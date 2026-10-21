import { useEffect, useState } from 'react'
import { consultarIdentidadDni, mensajeError } from './api'

export function useIdentidadDni(dni, setDatos) {
  const [estado, setEstado] = useState({ dni: '', mensaje: '', tipo: 'info' })
  const [intento, setIntento] = useState(0)
  const valido = /^[0-9]{8}$/.test(dni)
  const consultando = valido && (estado.dni !== dni || estado.tipo === 'cargando')

  useEffect(() => {
    if (!/^[0-9]{8}$/.test(dni)) return
    const controller = new AbortController()
    const timer = setTimeout(async () => {
      setEstado({ dni, tipo: 'cargando', mensaje: 'Buscando nombres y apellidos…' })
      try {
        const persona = await consultarIdentidadDni(dni, controller.signal)
        if (controller.signal.aborted) return
        setDatos(actual => actual.dni !== dni ? actual : {
          ...actual,
          nombres: persona.nombres,
          apellido_paterno: persona.apellido_paterno,
          apellido_materno: persona.apellido_materno || '',
          ...('nombre' in actual ? { nombre: [persona.nombres, persona.apellido_paterno, persona.apellido_materno].filter(Boolean).join(' ') } : {}),
        })
        setEstado({ dni, tipo: 'exito', mensaje: persona.origen === 'base_datos' ? 'Datos recuperados de la base de datos. Revisa los nombres antes de guardar.' : 'Datos obtenidos de Factiliza. Revisa los nombres antes de guardar.' })
      } catch (error) {
        if (!controller.signal.aborted) setEstado({ dni, tipo: 'error', mensaje: mensajeError(error) })
      }
    }, 450)
    return () => { clearTimeout(timer); controller.abort() }
  }, [dni, setDatos, intento])

  function cambiarDni(valor) {
    if (!/^[0-9]{0,8}$/.test(valor)) return
    setDatos(actual => ({ ...actual, dni: valor, nombres: '', apellido_paterno: '', apellido_materno: '', ...('nombre' in actual ? { nombre: '' } : {}) }))
    setEstado({ dni: '', mensaje: '', tipo: 'info' })
  }

  function reintentar() {
    setEstado({ dni, tipo: 'cargando', mensaje: 'Buscando nombres y apellidos…' })
    setIntento(valor => valor + 1)
  }

  return { cambiarDni, consultando, reintentar, estado: estado.dni === dni ? estado : { tipo: 'info', mensaje: '' } }
}
