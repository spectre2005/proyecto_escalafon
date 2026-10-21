import { Link } from 'react-router-dom'
import { Aviso } from '../componentes'
import { COMPROBACION, cifra, fecha, textoEspacio } from '../estado'
import { boton } from '../estilos'
import { Seccion } from '../ui'

/** Comprobaciones que piden atención: las correctas o no disponibles no se muestran (solo se avisa cuando algo falla). */
const PROBLEMAS = ['error', 'aviso', 'no_verificado']

const nombreMes = mes => new Date(`${mes}-01T12:00:00`).toLocaleDateString('es-PE', { month: 'short' }).replace('.', '')

/** Aviso técnico: solo si alguna comprobación falló, con su mensaje (que dice qué hacer). */
function AvisoTecnico({ comprobaciones }) {
  const problemas = comprobaciones.filter(c => PROBLEMAS.includes(c.estado))
  if (problemas.length === 0) return null
  const grave = problemas.some(c => c.estado === 'error')
  return <div role="alert" className={`mt-4 rounded-xl p-4 text-sm ${grave ? 'bg-rose-50 text-rose-800' : 'bg-amber-50 text-amber-900'}`}>
    <p className="font-semibold">{grave ? 'Hay un problema técnico en esta institución' : 'Algo técnico requiere atención'}</p>
    <ul className="mt-2 space-y-1">{problemas.map(c => <li key={c.clave}><strong>{COMPROBACION[c.clave] || c.clave}:</strong> {c.mensaje}</li>)}</ul>
    <Link to="/plataforma/estado-tecnico" className="mt-2 inline-block text-xs font-semibold underline">Ver el estado técnico de todas las instituciones</Link>
  </div>
}

/** Documentos cargados por mes (una sola serie): barras con el valor al pasar el cursor y tabla para lectores de pantalla. */
function CargaPorMes({ porMes }) {
  const maximo = Math.max(1, ...porMes.map(m => m.total))
  return <figure className="mt-6">
    <figcaption className="text-sm font-semibold text-slate-700">Documentos cargados por mes</figcaption>
    <ol aria-hidden="true" className="mt-3 flex h-36 items-end gap-0.5 border-b border-slate-200">
      {porMes.map(m => <li key={m.mes} title={`${nombreMes(m.mes)}: ${cifra(m.total)} documento${m.total === 1 ? '' : 's'}`} className="group flex h-full flex-1 flex-col items-center justify-end px-1.5 sm:px-3">
        <span className="mb-1 text-[11px] text-slate-500 opacity-0 group-hover:opacity-100">{cifra(m.total)}</span>
        <span className={`w-full max-w-12 rounded-t ${m.total ? 'bg-teal-600 group-hover:bg-teal-700' : 'bg-slate-200'}`} style={{ height: m.total ? `${Math.max(4, (m.total / maximo) * 100)}%` : '2px' }} />
      </li>)}
    </ol>
    <div aria-hidden="true" className="mt-1.5 flex gap-0.5 text-center text-[11px] text-slate-500">{porMes.map(m => <span key={m.mes} className="flex-1 capitalize">{nombreMes(m.mes)}</span>)}</div>
    <table className="sr-only"><caption>Documentos cargados por mes</caption><thead><tr><th>Mes</th><th>Documentos</th></tr></thead>
      <tbody>{porMes.map(m => <tr key={m.mes}><td>{nombreMes(m.mes)} {m.mes.slice(0, 4)}</td><td>{m.total}</td></tr>)}</tbody></table>
  </figure>
}

/**
 * Pestaña «Uso y respaldos»: cómo usa la institución el sistema (solo cifras, ningún dato personal). Las comprobaciones
 * técnicas no se listan: solo aparece un aviso si alguna falla (el detalle de todas está en «Estado técnico»).
 * uso: useUso() de la página (una sola medición, que también usa la franja de resumen).
 */
export default function UsoTecnico({ uso }) {
  const { data } = uso
  const espacio = textoEspacio(data?.almacenamiento)
  const ingreso = data?.ultimo_ingreso
  // texto: valores largos (fechas) en letra normal; las cifras, grandes.
  const dato = (etiqueta, valor, nota, texto = false) => <div className="rounded-xl bg-slate-50 p-4"><dt className="text-xs text-slate-500">{etiqueta}</dt><dd className={`mt-1 font-bold text-slate-900 ${texto ? 'text-base' : 'text-2xl'}`}>{valor}</dd>{nota && <dd className="mt-0.5 text-xs text-slate-500">{nota}</dd>}</div>
  return <Seccion titulo="Uso del sistema" aria-busy={Boolean(uso.loading)}
    descripcion={data ? `Medido ${fecha(data.medido_en)}. Solo cifras: ningún dato personal.` : 'Solo cifras: ningún dato personal.'}
    acciones={<button type="button" onClick={uso.actualizar} disabled={uso.loading} className={boton('secundario', 'pequeno')}>{uso.loading ? 'Midiendo…' : 'Actualizar'}</button>}>
    {uso.loading && <p role="status" className="mt-3 text-sm text-slate-500">Midiendo el uso…</p>}
    {uso.error && <div className="mt-3"><Aviso>{uso.error}</Aviso></div>}
    {data && <>
      <AvisoTecnico comprobaciones={data.comprobaciones} />
      <dl className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {dato('Personal registrado', cifra(data.personas?.total), data.personas && `${cifra(data.personas.con_acceso)} con acceso al sistema`)}
        {dato('Legajos', cifra(data.legajos?.total), data.legajos && `${cifra(data.legajos.activos)} activos · ${cifra(data.legajos.pasivos)} pasivos`)}
        {dato('Documentos cargados', cifra(data.documentos?.total), data.documentos && `${cifra(data.documentos.este_mes)} este mes`)}
        {dato('Cuentas activas', cifra(ingreso?.cuentas_recientes), ingreso && 'iniciaron sesión en los últimos 30 días')}
        {dato('Último inicio de sesión', ingreso ? (ingreso.cualquiera ? fecha(ingreso.cualquiera) : 'Nadie aún') : '—',
          ingreso && `De un administrador: ${ingreso.administrador ? fecha(ingreso.administrador) : 'ninguno'}`, true)}
        {dato('Espacio de sus archivos', espacio.texto, espacio.nota)}
      </dl>
      {data.documentos?.por_mes && <CargaPorMes porMes={data.documentos.por_mes} />}
    </>}
  </Seccion>
}
