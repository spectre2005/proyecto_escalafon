import { useState } from 'react'
import { editarInstitucion, mensajeError, sesionTerminada } from './api'
import { cambios } from './estado'
import { useSesionPlataforma } from './sesion'

/**
 * Edición de la identificación (nombre, identificador, base de datos y almacenamiento). Es un hook porque el botón
 * «Editar datos» está en la cabecera de la página. onGuardado() se llama tras guardar: la página avisa y recarga.
 */
export function useEdicionIdentificacion(institucion, onGuardado) {
  const { terminada } = useSesionPlataforma()
  const [borrador, setBorrador] = useState(null)
  const [guardando, setGuardando] = useState(false)
  const [error, setError] = useState('')
  const editar = () => { setError(''); setBorrador({ nombre: institucion.nombre, slug: institucion.slug, base_datos: institucion.base_datos, almacenamiento: institucion.almacenamiento }) }
  const cancelar = () => { setBorrador(null); setError('') }
  async function guardar(event) {
    event.preventDefault()
    const datos = cambios(institucion, borrador)
    if (Object.keys(datos).length === 0) { setBorrador(null); return }
    setGuardando(true)
    setError('')
    try {
      await editarInstitucion(institucion.id, datos)
      setBorrador(null)
      onGuardado()
    } catch (failure) {
      if (sesionTerminada(failure)) terminada()
      else setError(mensajeError(failure))
    } finally {
      setGuardando(false)
    }
  }
  return { borrador, setBorrador, guardando, error, editar, cancelar, guardar }
}
