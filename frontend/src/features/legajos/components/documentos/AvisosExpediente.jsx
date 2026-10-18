import { Link } from 'react-router-dom'
import Icon from '../../../../components/ui/Icon'

function presentar(aviso) {
  if (aviso.startsWith('No tiene un vínculo laboral vigente')) return { titulo: 'Sin vínculo laboral vigente', descripcion: 'Se están utilizando los datos de la ficha personal.', categoria: 's02-vinculo', accion: 'Ir a Incorporación', icono: 'building' }
  if (aviso.includes('discapacidad') || aviso.includes('CONADIS')) return {
    titulo: aviso.startsWith('La persona figura con discapacidad') ? 'Falta certificado CONADIS' : 'Revisar condición de discapacidad',
    descripcion: aviso.startsWith('La persona figura con discapacidad') ? 'La ficha indica discapacidad y no hay certificado registrado.' : aviso,
    categoria: aviso.startsWith('Hay un certificado') ? 'general' : 's01-discapacidad',
    accion: aviso.startsWith('Hay un certificado') ? 'Ir a Información personal' : 'Ir a Discapacidad', icono: 'file',
  }
  if (aviso.includes('derechohabiente') || aviso.includes('familiares')) return { titulo: 'Revisar familiares y derechohabientes', descripcion: aviso, categoria: 's01-familia', accion: 'Ir a Familia', icono: 'users' }
  return { titulo: 'Revisar datos del expediente', descripcion: aviso, categoria: 'general', accion: 'Ir a Información personal', icono: 'user' }
}

export default function AvisosExpediente({ avisos = [], usuario }) {
  const unicos = [...new Set(avisos)]
  if (!unicos.length) return null
  return <details className="group/avisos mb-5 overflow-hidden rounded-2xl border border-slate-200 bg-white">
    <summary className="flex cursor-pointer list-none items-center gap-3 bg-slate-50 px-4 py-3.5 text-sm text-slate-700 focus-visible:outline-2 focus-visible:outline-teal-700 [&::-webkit-details-marker]:hidden">
      <Icon name="info" size={21} />
      <span className="flex-1 font-medium">{unicos.length} {unicos.length === 1 ? 'observación' : 'observaciones'} del expediente</span>
      <span className="text-xs font-semibold text-teal-700"><span className="group-open/avisos:hidden">Ver detalles</span><span className="hidden group-open/avisos:inline">Ocultar detalles</span></span>
      <span className="rotate-90 text-teal-700 transition-transform group-open/avisos:-rotate-90"><Icon name="arrow" size={16} /></span>
    </summary>
    <ul className="divide-y divide-slate-100 px-4">{unicos.map(aviso => {
      const item = presentar(aviso)
      return <li key={aviso} className="flex flex-wrap items-center gap-3 py-4 sm:gap-4">
        <span className="text-slate-500"><Icon name={item.icono} size={21} /></span>
        <div className="min-w-0 flex-1 basis-48"><p className="text-sm font-semibold text-slate-800">{item.titulo}</p><p className="mt-1 text-xs leading-relaxed text-slate-500">{item.descripcion}</p></div>
        <Link to={`/legajos/${usuario}?categoria=${item.categoria}`} className="inline-flex items-center gap-2 rounded-lg py-2 text-xs font-semibold text-teal-700 hover:underline focus-visible:outline-2 focus-visible:outline-teal-700">{item.accion}<Icon name="arrow" size={15} /></Link>
      </li>
    })}</ul>
  </details>
}
