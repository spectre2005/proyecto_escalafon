import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import api from '../../axios'
import FormatosDirectivaPage from './formatos/FormatosDirectivaPage'
import { DIRECTIVA, familiasDe } from './reportesMenu'

// Pestañas del panel de reportes: una por familia y «Directiva», que lleva a los formatos de los anexos 06 a 09.
export default function ReportesPestanas({ familias, activa, directiva }) {
  const pestanas = [...familias.map(familia => ({ ...familia, to: `/reportes?familia=${encodeURIComponent(familia.id)}` })), ...(directiva ? [{ ...DIRECTIVA, to: '/reportes/formatos' }] : [])]
  return <nav aria-label="Familias de reportes" className="flex gap-1 overflow-x-auto border-b border-slate-200">
    {pestanas.map(pestana => <Link key={pestana.id} to={pestana.to} aria-current={pestana.id === activa ? 'page' : undefined}
      className={`-mb-px shrink-0 whitespace-nowrap border-b-2 px-4 py-2.5 text-sm ${pestana.id === activa ? 'border-teal-700 font-semibold text-teal-800' : 'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-800'}`}>{pestana.etiqueta}</Link>)}
  </nav>
}

// La pestaña «Directiva»: los formatos tal cual, con las pestañas encima. Solo entra quien administra (lo vuelve a
// comprobar FormatosDirectivaPage y el backend). Las demás pestañas, las mismas que en Reportes: solo familias con
// reportes disponibles según permisos y configuración de la institución.
export function FormatosDirectivaConPestanas() {
  const [familias, setFamilias] = useState([])
  useEffect(() => {
    const controller = new AbortController()
    api.get('/reportes/opciones', { signal: controller.signal }).then(response => setFamilias(familiasDe(response.data.data.reportes)))
      .catch(() => { /* sin catálogo solo queda «Directiva»; los formatos funcionan igual */ })
    return () => controller.abort()
  }, [])
  return <div className="space-y-6">
    <ReportesPestanas familias={familias} activa={DIRECTIVA.id} directiva />
    <FormatosDirectivaPage />
  </div>
}
