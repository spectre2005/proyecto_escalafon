import { useEffect, useState } from 'react'
import { apiErrorMessage } from '../../axios'
import { listUsers } from './usuariosApi'

export function useUsers() {
  const [users, setUsers] = useState([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')
  const [revision, setRevision] = useState(0)
  useEffect(() => {
    const controller = new AbortController()
    listUsers(controller.signal).then(data => {
      if (!controller.signal.aborted) { setUsers(data); setLoadError('') }
    }).catch(error => {
      if (!controller.signal.aborted) setLoadError(apiErrorMessage(error))
    }).finally(() => {
      if (!controller.signal.aborted) setLoading(false)
    })
    return () => controller.abort()
  }, [revision])
  function refreshUsers() {
    if (loading) return
    setLoading(true)
    setRevision(value => value + 1)
  }
  return { users, setUsers, loading, loadError, refreshUsers }
}
