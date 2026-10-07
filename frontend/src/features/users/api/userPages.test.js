import test from 'node:test'
import assert from 'node:assert/strict'
import { loadUserPages } from './userPages.js'

test('loads pages concurrently with at most four requests and preserves page order', async () => {
  let active = 0
  let maximum = 0
  const users = await loadUserPages(async page => {
    active++
    maximum = Math.max(maximum, active)
    await new Promise(resolve => setTimeout(resolve, 12 - page))
    active--
    return { data: [page], meta: { last_page: 10 } }
  })
  assert.equal(maximum, 4)
  assert.deepEqual(users, [1, 2, 3, 4, 5, 6, 7, 8, 9, 10])
})

test('does not return an incomplete list when a page fails', async () => {
  await assert.rejects(loadUserPages(async page => {
    if (page === 3) throw new Error('offline')
    return { data: [page], meta: { last_page: 3 } }
  }), /offline/)
})
