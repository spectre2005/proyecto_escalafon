import { useCallback, useEffect, useSyncExternalStore } from 'react'
import api from '../../axios'
import { createQueryStore } from './queryStore'

const store = createQueryStore((url, signal) => api.get(url, { signal }).then(response => response.data))
let subscribers = 0
let interval

function refreshVisible() {
  if (document.visibilityState === 'visible') store.refreshVisible()
}

export function useLiveQuery(url) {
  const subscribe = useCallback(listener => store.subscribe(url, listener), [url])
  const snapshot = useCallback(() => store.snapshot(url), [url])
  const result = useSyncExternalStore(subscribe, snapshot)
  useEffect(() => {
    if (subscribers++ === 0) {
      interval = setInterval(refreshVisible, 5000)
      window.addEventListener('focus', refreshVisible)
      document.addEventListener('visibilitychange', refreshVisible)
      window.addEventListener('records-updated', store.invalidate)
    }
    return () => {
      if (--subscribers === 0) {
        clearInterval(interval)
        window.removeEventListener('focus', refreshVisible)
        document.removeEventListener('visibilitychange', refreshVisible)
        window.removeEventListener('records-updated', store.invalidate)
      }
    }
  }, [])
  return { ...result, retry: () => store.refresh(url), setData: data => store.setData(url, data) }
}
