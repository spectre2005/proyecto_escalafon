import api from '../../axios'
import AuditPanel from '../../shared/AuditPanel'
import { usePuede } from '../auth/session'

const load = (url, signal) => api.get(url, { signal }).then(response => response.data)
const download = url => api.get(url, { responseType: 'blob', timeout: 120000 })
export default function AuditoriaPage() {
  const administra = usePuede('sistema.administrar')
  const sensibles = usePuede('datos_sensibles.ver')
  return administra && sensibles ? <AuditPanel load={load} download={download} /> : <p role="alert">No tienes permiso para consultar la auditoría completa.</p>
}
