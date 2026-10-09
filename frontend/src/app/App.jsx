import { BrowserRouter, Link, Navigate, Route, Routes } from 'react-router-dom'
import RequireSession from '../features/auth/RequireSession'
import LoginPrincipal from '../features/auth/login_principal'
import DashboardPage from '../features/dashboard/DashboardPage'
import UsersPage from '../features/users/pages/UsersPage'
import LegajosPage from '../features/legajos/pages/LegajosPage'
import SettingsPage from '../features/settings/SettingsPage'
import EvaluacionesPage from '../features/evaluations/pages/EvaluacionesPage'
import IncorporacionPage from '../features/incorporation/IncorporacionPage'
import SectionAccessPage from '../features/settings/SectionAccessPage'
import RequirementsPage from '../features/requirements/RequirementsPage'
import VencimientosPage from '../features/requirements/VencimientosPage'

import { usePuede } from '../features/auth/session'

function Home() {
  const personal = usePuede('personal.ver')
  return personal ? <DashboardPage /> : <Navigate to="/mi-legajo" replace />
}

export default function App() {
  return <BrowserRouter><Routes><Route path="login_principal" element={<LoginPrincipal />} /><Route element={<RequireSession />}><Route index element={<Home />} /><Route path="legajos" element={<LegajosPage />} /><Route path="legajos/:usuario" element={<LegajosPage />} /><Route path="incorporacion" element={<IncorporacionPage />} /><Route path="accesos" element={<SectionAccessPage />} /><Route path="mi-legajo" element={<LegajosPage propio />} /><Route path="personal" element={<UsersPage />} /><Route path="evaluaciones" element={<EvaluacionesPage />} /><Route path="requerimientos" element={<RequirementsPage />} /><Route path="vencimientos" element={<VencimientosPage />} /><Route path="configuracion" element={<SettingsPage />} /><Route path="*" element={<div className="rounded-2xl border border-slate-200 bg-white p-10 text-center"><h1 className="text-2xl font-bold">Página no encontrada</h1><Link to="/" className="mt-5 inline-block text-teal-700 underline">Volver al inicio</Link></div>} /></Route></Routes></BrowserRouter>
}
