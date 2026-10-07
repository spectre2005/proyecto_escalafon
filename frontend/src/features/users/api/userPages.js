export async function loadUserPages(loadPage) {
  const first = await loadPage(1)
  const users = [...first.data]
  for (let page = 2; page <= first.meta.last_page; page += 4) {
    const pages = Array.from({ length: Math.min(4, first.meta.last_page - page + 1) }, (_, index) => page + index)
    const results = await Promise.all(pages.map(loadPage))
    for (const result of results) users.push(...result.data)
  }
  return users
}
