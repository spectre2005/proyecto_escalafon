import { Aviso, EstadoTecnico } from '../componentes'
import { COMPROBACION, cifra, fecha, resumenTecnico, textoEspacio } from '../estado'
import { useUso } from '../useUso'

/** Sección del detalle de la institución: cifras de uso y cada comprobación técnica con su estado. */
export default function UsoTecnico({ institucion }) {
  const uso = useUso(institucion.id)
  const { data } = uso
  const espacio = textoEspacio(data?.almacenamiento)
  const dato = (etiqueta, valor, nota) => <div><dt className="text-xs text-slate-500">{etiqueta}</dt><dd className="mt-1 text-sm text-slate-800">{valor}</dd>{nota && <dd className="text-xs text-slate-500">{nota}</dd>}</div>
  return <div className="mt-6 rounded-2xl border border-slate-200 bg-white p-6" aria-busy={Boolean(uso.loading)}>
    <div className="flex flex-wrap items-center justify-between gap-2">
      <h2 className="font-semibold text-slate-800">Uso y estado técnico</h2>
      <div className="flex items-center gap-3">{data && <EstadoTecnico {...resumenTecnico(data)} />}<button type="button" onClick={uso.actualizar} disabled={uso.loading} className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs">{uso.loading ? 'Midiendo…' : 'Actualizar'}</button></div>
    </div>
    {uso.loading && <p role="status" className="mt-3 text-sm text-slate-500">Midiendo uso y estado técnico…</p>}
    {uso.error && <div className="mt-3"><Aviso>{uso.error}</Aviso></div>}
    {data && <>
      <p className="mt-1 text-xs text-slate-400">Medido {fecha(data.medido_en)}. Solo cifras: ningún dato personal.</p>
      <dl className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {dato('Personas registradas', cifra(data.personas?.total), data.personas && `${cifra(data.personas.con_acceso)} con acceso`)}
        {dato('Legajos', cifra(data.legajos?.total), data.legajos && `${cifra(data.legajos.activos)} activos · ${cifra(data.legajos.pasivos)} pasivos`)}
        {dato('Espacio de sus archivos', espacio.texto, espacio.nota)}
        {dato('Último inicio de sesión', data.ultimo_ingreso ? (data.ultimo_ingreso.cualquiera ? fecha(data.ultimo_ingreso.cualquiera) : 'Nadie ha iniciado sesión') : '—',
          data.ultimo_ingreso && `de un administrador: ${data.ultimo_ingreso.administrador ? fecha(data.ultimo_ingreso.administrador) : 'ninguno'}`)}
      </dl>
      <ul className="mt-5 divide-y divide-slate-100">
        {data.comprobaciones.map(c => <li key={c.clave} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
          <span><strong className="text-slate-700">{COMPROBACION[c.clave] || c.clave}</strong><span className="text-slate-500"> — {c.mensaje}</span></span>
          <EstadoTecnico estado={c.estado} />
        </li>)}
      </ul>
    </>}
  </div>
}
