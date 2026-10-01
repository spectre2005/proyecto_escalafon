import { BrowserRouter, Link, Route, Routes } from 'react-router-dom'
import AdminLayout from '../layouts/AdminLayout'
import DashboardPage from '../features/dashboard/DashboardPage'
import UsersPage from '../features/users/pages/UsersPage'
import LegajosPage from '../features/legajos/pages/LegajosPage'
import SettingsPage from '../features/settings/SettingsPage'
import EvaluationsPage from '../features/evaluations/EvaluationsPage'
import RequirementsPage from '../features/requirements/RequirementsPage'

export default function App() {
  return <BrowserRouter><Routes><Route element={<AdminLayout />}><Route index element={<DashboardPage />} /><Route path="legajos" element={<LegajosPage />} /><Route path="legajos/:usuario" element={<LegajosPage />} /><Route path="personal" element={<UsersPage />} /><Route path="evaluaciones" element={<EvaluationsPage />} /><Route path="requerimientos" element={<RequirementsPage />} /><Route path="configuracion" element={<SettingsPage />} /><Route path="*" element={<div className="rounded-2xl border border-slate-200 bg-white p-10 text-center"><h1 className="text-2xl font-bold">Página no encontrada</h1><Link to="/" className="mt-5 inline-block text-teal-700 underline">Volver al inicio</Link></div>} /></Route></Routes></BrowserRouter>
}
