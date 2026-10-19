import { useContext } from 'react'
import { SesionPlataforma } from '../sesion'
import { cambiarPassword } from '../api'
import CambiarPasswordObligatorio from '../../auth/CambiarPasswordObligatorio'

export default function CuentaPage() {
  const { administrador } = useContext(SesionPlataforma)
  return <div>
    <h1 className="text-3xl font-bold text-slate-900">Mi cuenta</h1>
    <p className="mt-3 text-sm text-slate-700">{administrador.nombre} · {administrador.email}</p>
    <CambiarPasswordObligatorio obligatorio={false} guardar={cambiarPassword} />
  </div>
}
