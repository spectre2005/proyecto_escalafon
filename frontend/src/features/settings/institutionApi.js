import api from '../../axios'

export async function fetchInstitution(signal) {
  return (await api.get('/institucion', { signal })).data
}

export async function saveInstitution(settings, version) {
  return (await api.put('/institucion', settings, { headers: { 'If-Match': version } })).data
}
