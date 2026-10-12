import { agruparTipos } from '../../config/aplicacion'

const lista = 'divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200 bg-white'

// Lista de tipos documentales en tres grupos (agruparTipos): Pendientes, Con documentos y No corresponde. Este último
// empieza plegado: sus documentos siguen accesibles (se ven y se subsanan) al desplegarlo. render(tipo) devuelve el <li>.
export default function GruposTipos({ tipos, noAplica, render }) {
  const { pendientes, conDocumentos, noCorresponde } = agruparTipos(tipos, noAplica)
  const titulo = (texto, cantidad) => <h4 className="mb-2 mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500 first:mt-0">{texto} <span className="font-normal normal-case text-slate-400">({cantidad})</span></h4>
  return <div>
    {pendientes.length > 0 && <>{titulo('Pendientes', pendientes.length)}<ul className={lista}>{pendientes.map(render)}</ul></>}
    {conDocumentos.length > 0 && <>{titulo('Con documentos', conDocumentos.length)}<ul className={lista}>{conDocumentos.map(render)}</ul></>}
    {noCorresponde.length > 0 && <details className="mt-4">
      <summary className="cursor-pointer text-xs font-semibold uppercase tracking-wide text-slate-500">No corresponde <span className="font-normal normal-case text-slate-400">({noCorresponde.length}) · tienen documentos anteriores: se pueden consultar y subsanar</span></summary>
      <ul className={`${lista} mt-2`}>{noCorresponde.map(render)}</ul>
    </details>}
    {!pendientes.length && !conDocumentos.length && !noCorresponde.length && <p className="rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-500">No hay tipos de documento que correspondan a esta persona en este apartado.</p>}
  </div>
}
