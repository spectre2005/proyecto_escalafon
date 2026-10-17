import { BrowserRouter, Link, Route, Routes } from 'react-router-dom'
import RequireSession from '../features/auth/RequireSession'
import LoginPrincipal from '../features/auth/login_principal'
import DashboardPage from '../features/dashboard/DashboardPage'
import UsersPage from '../features/users/pages/UsersPage'
import LegajosPage from '../features/legajos/pages/LegajosPage'
import IncorporacionPage from '../features/incorporation/IncorporacionPage'
import SectionAccessPage from '../features/settings/SectionAccessPage'
import VencimientosPage from '../features/requirements/VencimientosPage'
import MiLegajoPage from '../features/legajos/pages/MiLegajoPage'
import SettingsPage from '../features/settings/SettingsPage'
import EvaluacionesPage from '../features/evaluations/pages/EvaluacionesPage'
import RequirementsPage from '../features/requirements/RequirementsPage'
import PlataformaLogin from '../features/plataforma/PlataformaLogin'
import RequierePlataforma from '../features/plataforma/RequierePlataforma'
import ResumenPlataforma from '../features/plataforma/pages/ResumenPage'
import InstitucionesPage from '../features/plataforma/pages/InstitucionesPage'
import InstitucionNuevaPage from '../features/plataforma/pages/InstitucionNuevaPage'
import InstitucionDetallePage from '../features/plataforma/pages/InstitucionDetallePage'

export default function App() {
  return <BrowserRouter><Routes><Route path="login_principal" element={<LoginPrincipal />} /><Route path="plataforma/login" element={<PlataformaLogin />} /><Route path="plataforma" element={<RequierePlataforma />}><Route index element={<ResumenPlataforma />} /><Route path="instituciones" element={<InstitucionesPage />} /><Route path="instituciones/nueva" element={<InstitucionNuevaPage />} /><Route path="instituciones/:id" element={<InstitucionDetallePage />} /><Route path="*" element={<p className="rounded-2xl border border-slate-200 bg-white p-10 text-center">Página no encontrada.</p>} /></Route><Route element={<RequireSession />}><Route path="incorporacion" element={<IncorporacionPage />} /><Route path="accesos" element={<SectionAccessPage />} /><Route path="vencimientos" element={<VencimientosPage />} /><Route path="mi-legajo" element={<MiLegajoPage />} /><Route index element={<DashboardPage />} /><Route path="legajos" element={<LegajosPage />} /><Route path="legajos/:usuario" element={<LegajosPage />} /><Route path="personal" element={<UsersPage />} /><Route path="evaluaciones" element={<EvaluacionesPage />} /><Route path="requerimientos" element={<RequirementsPage />} /><Route path="configuracion" element={<SettingsPage />} /><Route path="*" element={<div className="rounded-2xl border border-slate-200 bg-white p-10 text-center"><h1 className="text-2xl font-bold">Página no encontrada</h1><Link to="/" className="mt-5 inline-block text-teal-700 underline">Volver al inicio</Link></div>} /></Route></Routes></BrowserRouter>
}
