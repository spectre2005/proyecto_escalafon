export function createQueryStore(load, { maxEntries = 40, now = Date.now } = {}) {
  const entries = new Map()
  function entry(url) {
    if (!entries.has(url)) {
      for (const [key, item] of entries) {
        if (entries.size < maxEntries) break
        if (!item.listeners.size && !item.pending) entries.delete(key)
      }
      entries.set(url, { snapshot: { data: null, error: null, loading: true }, listeners: new Set(), version: 0, checkedAt: 0 })
    }
    return entries.get(url)
  }
  function publish(item, snapshot) {
    item.snapshot = snapshot
    item.listeners.forEach(listener => listener())
  }
  async function refresh(url) {
    const item = entry(url)
    if (item.pending) return item.pending
    const version = item.version
    item.controller = new AbortController()
    const task = load(url, item.controller.signal).then(data => {
      if (version !== item.version) return
      item.checkedAt = now()
      publish(item, { data, error: null, loading: false })
    }).catch(error => {
      if (version !== item.version) return
      publish(item, { data: item.snapshot.data, error, loading: false })
    }).finally(() => {
      if (version === item.version) item.pending = null
    })
    item.pending = task
    return task
  }
  function cancel(item) {
    item.version++
    item.controller?.abort()
    item.pending = null
  }
  return {
    snapshot: url => entry(url).snapshot,
    subscribe(url, listener) {
      const item = entry(url)
      item.listeners.add(listener)
      void refresh(url)
      return () => {
        item.listeners.delete(listener)
        queueMicrotask(() => {
          if (!item.listeners.size && item.pending) cancel(item)
        })
      }
    },
    refresh,
    setData(url, data) {
      const item = entry(url)
      cancel(item)
      item.checkedAt = now()
      publish(item, { data, error: null, loading: false })
    },
    invalidate() {
      for (const [url, item] of entries) {
        cancel(item)
        item.checkedAt = 0
        if (item.listeners.size) void refresh(url)
      }
    },
    refreshVisible() {
      for (const [url, item] of entries) {
        if (item.listeners.size && now() - item.checkedAt >= 1000) void refresh(url)
      }
    },
  }
}
