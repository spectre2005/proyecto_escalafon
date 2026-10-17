import { Navigate } from 'react-router-dom'
import { useSesion } from '../../auth/session'

export default function MiLegajoPage() {
  const { usuario } = useSesion()
  return usuario ? <Navigate to={`/legajos/${usuario.id}`} replace /> : null
}
