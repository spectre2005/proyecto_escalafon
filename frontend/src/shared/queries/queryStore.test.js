import test from 'node:test'
import assert from 'node:assert/strict'
import { createQueryStore } from './queryStore.js'

test('reuses fresh data when navigating and refreshes stale data', async () => {
  let time = 1000
  let calls = 0
  const store = createQueryStore(async () => ++calls, { now: () => time })
  await store.refresh('/test')
  const unsubscribe = store.subscribe('/test', () => {})
  assert.equal(calls, 1)
  time += 1000
  store.refreshVisible()
  await store.refresh('/test')
  assert.equal(calls, 2)
  unsubscribe()
})

test('polling does not cancel or duplicate a slow read', async () => {
  let calls = 0
  let resolve
  const store = createQueryStore(() => { calls++; return new Promise(done => { resolve = done }) })
  const unsubscribe = store.subscribe('/test', () => {})
  store.refreshVisible()
  store.refreshVisible()
  assert.equal(calls, 1)
  resolve(['saved'])
  await store.refresh('/test')
  assert.deepEqual(store.snapshot('/test').data, ['saved'])
  unsubscribe()
})

test('functional updates preserve existing users and ignore older requests', async () => {
  const store = createQueryStore(async () => [])
  store.setData('/users', [{ id: 1 }])
  store.setData('/users', previous => [{ id: 2 }, ...previous])
  assert.deepEqual(store.snapshot('/users').data, [{ id: 2 }, { id: 1 }])
})

test('deduplicates simultaneous reads and retains the previous data during refresh', async () => {
  let calls = 0
  let resolve
  const store = createQueryStore(() => { calls++; return new Promise(done => { resolve = done }) })
  const first = store.refresh('/test')
  const second = store.refresh('/test')
  assert.equal(calls, 1)
  resolve({ value: 1 })
  await Promise.all([first, second])
  const next = store.refresh('/test')
  assert.deepEqual(store.snapshot('/test').data, { value: 1 })
  assert.equal(store.snapshot('/test').loading, false)
  resolve({ value: 2 })
  await next
  assert.deepEqual(store.snapshot('/test').data, { value: 2 })
})

test('a slow read cannot replace a confirmed save', async () => {
  let resolve
  const store = createQueryStore(() => new Promise(done => { resolve = done }))
  const pending = store.refresh('/test')
  store.setData('/test', { value: 'saved' })
  resolve({ value: 'old' })
  await pending
  assert.deepEqual(store.snapshot('/test').data, { value: 'saved' })
})

test('mutation notifications refresh active queries and leave inactive ones stale', async () => {
  let counter = 0
  const store = createQueryStore(async () => ({ value: ++counter }))
  const unsubscribe = store.subscribe('/active', () => {})
  await store.refresh('/active')
  await store.refresh('/inactive')
  store.invalidate()
  await store.refresh('/active')
  assert.equal(counter, 3)
  assert.equal(store.snapshot('/active').data.value, 3)
  unsubscribe()
})

test('a failed background refresh keeps the last data and exposes the error', async () => {
  const failure = new Error('offline')
  const store = createQueryStore(async () => { throw failure })
  store.setData('/test', { value: 'previous' })
  await store.refresh('/test')
  assert.deepEqual(store.snapshot('/test').data, { value: 'previous' })
  assert.equal(store.snapshot('/test').error, failure)
})
