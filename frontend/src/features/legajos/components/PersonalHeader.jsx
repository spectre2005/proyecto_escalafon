import AbrirLegajo from './AbrirLegajo'
import CerrarLegajo from './CerrarLegajo'
import PersonalPhoto from './PersonalPhoto'
import EmisionesPersonales from '../../reportes/components/EmisionesPersonales'
import { puestoActual, textoRegimen } from '../config/seccion02'

function SummaryField({ title, value, nota }) {
  const registered = value !== null && value !== undefined && String(value).trim() !== ''
  return <div className="min-w-0">
    <dt className="text-xs font-medium text-slate-500">{title}</dt>
    <dd className={`mt-1.5 break-words text-sm font-semibold ${registered ? 'text-slate-800' : 'font-normal text-slate-400'}`}>{registered ? value : 'No registrado'}</dd>
    {registered && nota && <dd className="mt-1 text-[11px] text-slate-500">{nota}</dd>}
  </div>
}

// compacto: dentro de una sección del legajo basta una línea con quién es y su legajo (la ficha completa, en «Información
// personal»); así la sección de trabajo aparece en la primera pantalla.
export default function PersonalHeader({ data, usuario, compacto = false, onVerFicha }) {
  const person = data.usuario.values
  const personnelType = { docente: 'Docente', administrativo: 'Administrativo' }[person.tipo_personal] || person.tipo_personal
  const documentType = person.tipo_doc_identidad?.trim()
  const regimen = textoRegimen(data.regimen)
  const actual = puestoActual(data.puesto_actual)
  if (compacto) {
    const activo = data.legajos.find(legajo => legajo.values.estado_legajo === 'activo')
    const legajo = activo || data.legajos[0]
    return <header className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-teal-100 bg-white px-5 py-3 shadow-sm">
      <div className="min-w-0">
        <p className="break-words text-base font-semibold text-slate-900">{data.nombre}</p>
        <p className="mt-0.5 text-xs text-slate-500">{documentType ? documentType.toUpperCase() : 'Documento'} {person.numero_doc_identidad || 'no registrado'} · {personnelType || 'Tipo de personal no registrado'} · {actual.puesto || 'Sin puesto registrado'}{legajo ? ` · Legajo ${legajo.values.numero_legajo || 'sin número'} (${legajo.values.estado_legajo === 'activo' ? 'activo' : 'cerrado'})` : ' · Sin legajo'}</p>
      </div>
      <div className="flex flex-wrap items-center gap-3">
        {activo && <CerrarLegajo usuario={usuario} legajo={activo.id} />}
        <AbrirLegajo usuario={usuario} legajoActivo={!!activo} />
        {onVerFicha && <button type="button" onClick={onVerFicha} className="text-xs font-semibold text-teal-700 hover:underline">Ver ficha completa</button>}
      </div>
    </header>
  }

  return <header className="mb-6 overflow-hidden rounded-2xl border border-teal-100 bg-white shadow-sm">
    <div className="h-1 bg-teal-600" />
    <div className="grid gap-7 p-6 sm:p-8 md:grid-cols-[minmax(0,1fr)_160px]">
      <div className="min-w-0">
        <p className="text-[10px] font-semibold uppercase tracking-[.2em] text-teal-700">Ficha del personal</p>
        <h1 className="mt-3 break-words text-2xl font-bold leading-tight text-slate-900 sm:text-3xl">{data.nombre}</h1>
        <dl className="mt-6 grid gap-x-8 gap-y-5 sm:grid-cols-2 xl:grid-cols-3">
          <SummaryField title={documentType ? `Documento de identidad · ${documentType.toUpperCase()}` : 'Documento de identidad'} value={person.numero_doc_identidad} />
          <SummaryField title="Tipo de personal" value={personnelType} />
          <SummaryField title="Puesto actual" value={actual.puesto} nota={actual.nota} />
          <SummaryField title="Unidad orgánica actual" value={actual.unidad} nota={actual.nota} />
          <SummaryField title="Ley / régimen normativo" value={regimen.ley} />
          <SummaryField title="Tipo de vínculo vigente" value={regimen.tipo} />
          <SummaryField title="Correo electrónico" value={person.email_institucional} />
        </dl>
        <section aria-label="Legajos registrados" className="mt-6 border-t border-slate-100 pt-5">
          <h2 className="text-xs font-semibold text-slate-600">Legajos registrados</h2>
          {data.legajos.length ? <ul className="mt-3 flex flex-wrap gap-2">
            {data.legajos.map(legajo => <li key={legajo.id} className="flex flex-wrap items-center gap-3 rounded-lg border border-teal-100 bg-teal-50 px-3 py-2 text-xs text-teal-900">
              <div>
              <span className="font-semibold">N.º de legajo: {legajo.values.numero_legajo || 'No registrado'}</span>
              <span className="mt-1 block text-teal-700">Estado: {legajo.values.estado_legajo === 'pasivo' ? 'Cerrado' : legajo.values.estado_legajo ? legajo.values.estado_legajo.replaceAll('_', ' ') : 'No registrado'}</span>
              </div>
              {legajo.values.estado_legajo === 'activo' && <CerrarLegajo usuario={usuario} legajo={legajo.id} />}
            </li>)}
          </ul> : <p className="mt-2 text-sm text-slate-500">No hay legajos registrados para esta persona.</p>}
          <div className="mt-3"><p className="mb-2 text-xs text-slate-600">Para una nueva incorporación abre otro legajo. Los anteriores conservan su número y documentos como históricos.</p><AbrirLegajo usuario={usuario} className="mt-3" legajoActivo={data.legajos.some(legajo => legajo.values.estado_legajo === 'activo')} /></div>
        </section>
        {data.legajos.length > 0 && <EmisionesPersonales usuario={usuario} />}
      </div>
      <PersonalPhoto usuario={usuario} nombre={data.nombre} />
    </div>
  </header>
}
