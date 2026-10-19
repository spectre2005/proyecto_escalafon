import { todayLima } from '../../../../shared/dates'
import { useState } from 'react'
import { apiErrorMessage } from '../../../../axios'

// Envío de un formulario de la sección 01: textos recortados (vacío = null), errores por campo del backend.
export function useGuardar(enviar, onSaved) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [errors, setErrors] = useState({})
  async function guardar(draft) {
    if (busy) return
    setBusy(true)
    setError('')
    setErrors({})
    try {
      await enviar(Object.fromEntries(Object.entries(draft).map(([key, value]) => [key, typeof value === 'string' ? value.trim() || null : value])))
      onSaved()
    } catch (failure) {
      setErrors(failure.response?.data?.errors || {})
      setError(failure.response?.status === 409 ? failure.response.data.message : apiErrorMessage(failure))
    } finally {
      setBusy(false)
    }
  }
  return { busy, error, errors, guardar }
}

// Borrador del formulario y un manejador por campo (inputs, selects y checkboxes).
export function useBorrador(inicial) {
  const [draft, setDraft] = useState(inicial)
  const set = key => event => setDraft(previous => ({ ...previous, [key]: event.target.type === 'checkbox' ? event.target.checked : event.target.value }))
  // Para controles que entregan el valor y no un evento (p. ej. TresEstados, que admite null).
  const poner = key => valor => setDraft(previous => ({ ...previous, [key]: valor }))
  return [draft, set, poner]
}

export const hoy = todayLima
