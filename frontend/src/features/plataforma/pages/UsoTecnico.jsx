import { Aviso, EstadoTecnico } from '../componentes'
import { COMPROBACION, cifra, fecha, resumenTecnico, textoEspacio } from '../estado'
import { boton } from '../estilos'
import { Seccion } from '../ui'

/**
 * Sección del detalle de la institución: cifras de uso y cada comprobación técnica con su estado. uso: useUso() de la
 * página (una sola medición, que también usa la franja de resumen).
 */
export default function UsoTecnico({ uso }) {
  const { data } = uso
  const espacio = textoEspacio(data?.almacenamiento)
  const dato = (etiqueta, valor, nota) => <div><dt className="text-xs text-slate-500">{etiqueta}</dt><dd className="mt-1 text-sm text-slate-800">{valor}</dd>{nota && <dd className="text-xs text-slate-500">{nota}</dd>}</div>
  return <Seccion titulo="Uso y estado técnico" aria-busy={Boolean(uso.loading)}
    acciones={<>{data && <EstadoTecnico {...resumenTecnico(data)} />}<button type="button" onClick={uso.actualizar} disabled={uso.loading} className={boton('secundario', 'pequeno')}>{uso.loading ? 'Midiendo…' : 'Actualizar'}</button></>}>
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
  </Seccion>
}
