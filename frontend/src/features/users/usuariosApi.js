import api from '../../axios'

export async function listUsers(signal) {
  const users = []
  let page = 1
  let lastPage
  do {
    const { data } = await api.get('/usuarios', { params: { page }, signal })
    users.push(...data.data)
    lastPage = data.meta.last_page
    page++
  } while (page <= lastPage)
  return users
}

export async function saveUsers(usuarios) {
  const { data } = await api.post('/usuarios', { usuarios })
  return data.data
}
