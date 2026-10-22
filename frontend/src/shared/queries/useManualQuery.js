import { useEffect, useState } from 'react'
import api from '../../axios'

// Consultas bajo demanda: cancela búsquedas anteriores y permite recargar sin sondeo periódico.
export function useManualQuery(url, delay = 0) {
  const [attempt, setAttempt] = useState(0)
  const [result, setResult] = useState(null)
  const key = `${url}|${attempt}`
  useEffect(() => {
    if (!url) return
    const controller = new AbortController()
    const timer = setTimeout(() => {
      api.get(url, { signal: controller.signal }).then(response => {
        if (!controller.signal.aborted) setResult({ key, data: response.data })
      }).catch(error => {
        if (!controller.signal.aborted) setResult({ key, error })
      })
    }, delay)
    return () => { clearTimeout(timer); controller.abort() }
  }, [url, key, delay])
  return { ...(result?.key === key && url ? result : {}), loading: Boolean(url && result?.key !== key), retry: () => setAttempt(value => value + 1) }
}
