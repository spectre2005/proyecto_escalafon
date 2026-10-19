import { useSesion } from './session'
import CambiarPasswordObligatorio from './CambiarPasswordObligatorio'

export default function ProfilePage() {
  const { usuario } = useSesion()
  return <div>
    <h1 className="text-2xl font-bold text-slate-900">Mi perfil</h1>
    <p className="mt-2 text-sm text-slate-600">Datos de tu cuenta institucional. Para corregirlos, contacta con el administrador.</p>
    <dl className="mt-6 grid gap-4 rounded-2xl border border-slate-200 bg-white p-6 sm:grid-cols-2">
      <div><dt className="text-xs text-slate-500">Nombre completo</dt><dd className="mt-1 font-medium">{[usuario.nombres, usuario.apellido_paterno, usuario.apellido_materno].filter(Boolean).join(' ')}</dd></div>
      <div><dt className="text-xs text-slate-500">Correo institucional</dt><dd className="mt-1 break-all font-medium">{usuario.email_institucional || 'Sin correo registrado'}</dd></div>
    </dl>
    <CambiarPasswordObligatorio usuario={usuario} obligatorio={false} />
  </div>
}
