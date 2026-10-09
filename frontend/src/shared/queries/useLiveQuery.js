import { useCallback, useEffect, useSyncExternalStore } from 'react'
import api from '../../axios'
import { createQueryStore } from './queryStore'

const store = createQueryStore((url, signal) => api.get(url, { signal }).then(response => response.data))
const subscriptions = new Map()
const disabledSnapshot = { data: null, error: null, loading: false }

export function useLiveQuery(url, queryStore = store) {
  const subscribe = useCallback(listener => url ? queryStore.subscribe(url, listener) : () => {}, [url, queryStore])
  const snapshot = useCallback(() => url ? queryStore.snapshot(url) : disabledSnapshot, [url, queryStore])
  const result = useSyncExternalStore(subscribe, snapshot)
  useEffect(() => {
    let subscription = subscriptions.get(queryStore)
    if (!subscription) {
      const refreshVisible = () => {
        if (document.visibilityState === 'visible') queryStore.refreshVisible()
      }
      subscription = { count: 0, refreshVisible, interval: setInterval(refreshVisible, 1000) }
      subscriptions.set(queryStore, subscription)
      window.addEventListener('focus', refreshVisible)
      document.addEventListener('visibilitychange', refreshVisible)
      window.addEventListener('records-updated', queryStore.invalidate)
    }
    subscription.count++
    return () => {
      if (--subscription.count === 0) {
        const { interval, refreshVisible } = subscription
        clearInterval(interval)
        window.removeEventListener('focus', refreshVisible)
        document.removeEventListener('visibilitychange', refreshVisible)
        window.removeEventListener('records-updated', queryStore.invalidate)
        subscriptions.delete(queryStore)
      }
    }
  }, [queryStore])
  return { ...result, retry: () => url && queryStore.refresh(url), setData: data => url && queryStore.setData(url, data) }
}
