import PersonalPhoto from './PersonalPhoto'

function SummaryField({ title, value }) {
  const registered = value !== null && value !== undefined && String(value).trim() !== ''
  return <div className="min-w-0">
    <dt className="text-xs font-medium text-slate-500">{title}</dt>
    <dd className={`mt-1.5 break-words text-sm font-semibold ${registered ? 'text-slate-800' : 'font-normal text-slate-400'}`}>{registered ? value : 'No registrado'}</dd>
  </div>
}

export default function PersonalHeader({ data, usuario }) {
  const person = data.usuario.values
  const personnelType = { docente: 'Docente', administrativo: 'Administrativo' }[person.tipo_personal] || person.tipo_personal
  const documentType = person.tipo_doc_identidad?.trim()

  return <header className="mb-6 overflow-hidden rounded-2xl border border-teal-100 bg-white shadow-sm">
    <div className="h-1 bg-teal-600" />
    <div className="grid gap-7 p-6 sm:p-8 md:grid-cols-[minmax(0,1fr)_160px]">
      <div className="min-w-0">
        <p className="text-[10px] font-semibold uppercase tracking-[.2em] text-teal-700">Ficha del personal</p>
        <h1 className="mt-3 break-words text-2xl font-bold leading-tight text-slate-900 sm:text-3xl">{data.nombre}</h1>
        <dl className="mt-6 grid gap-x-8 gap-y-5 sm:grid-cols-2 xl:grid-cols-3">
          <SummaryField title={documentType ? `Documento de identidad · ${documentType.toUpperCase()}` : 'Documento de identidad'} value={person.numero_doc_identidad} />
          <SummaryField title="Tipo de personal" value={personnelType} />
          <SummaryField title="Puesto registrado" value={person.nombre_puesto} />
          <SummaryField title="Unidad orgánica" value={person.unidad_organica_id} />
          <SummaryField title="Régimen laboral" value={person.regimen_laboral} />
          <SummaryField title="Correo institucional" value={person.email_institucional} />
        </dl>
        <section aria-label="Legajos registrados" className="mt-6 border-t border-slate-100 pt-5">
          <h2 className="text-xs font-semibold text-slate-600">Legajos registrados</h2>
          {data.legajos.length ? <ul className="mt-3 flex flex-wrap gap-2">
            {data.legajos.map(legajo => <li key={legajo.id} className="rounded-lg border border-teal-100 bg-teal-50 px-3 py-2 text-xs text-teal-900">
              <span className="font-semibold">N.º de legajo: {legajo.values.numero_legajo || 'No registrado'}</span>
              <span className="mt-1 block text-teal-700">Estado: {legajo.values.estado_legajo ? legajo.values.estado_legajo.replaceAll('_', ' ') : 'No registrado'}</span>
            </li>)}
          </ul> : <p className="mt-2 text-sm text-slate-500">No hay legajos registrados para esta persona.</p>}
        </section>
      </div>
      <PersonalPhoto usuario={usuario} nombre={data.nombre} />
    </div>
  </header>
}
