import { Link } from 'react-router-dom'
import { categoriaPorConsulta, columnasEvaluacion } from '../../legajos/config/seccion07'

const cell = 'px-5 py-4 align-top'
const muted = 'text-slate-400'

function fecha(value) {
  return value ? value.split('-').reverse().join('/') : <span className={muted}>No registrada</span>
}

function texto(value, vacio = 'No registrado') {
  return value === null || value === undefined || value === '' ? <span className={muted}>{vacio}</span> : String(value).replaceAll('_', ' ')
}

// Ficha de la persona abierta en la subsección de la 07 que corresponde a la pestaña.
const ficha = (persona, tipo) => `/legajos/${persona.id}?categoria=${categoriaPorConsulta[tipo.key]}`

function Persona({ persona, tipo }) {
  if (!persona) return <span className={muted}>Sin persona vinculada</span>
  return <><Link to={ficha(persona, tipo)} className="font-semibold text-teal-800 hover:underline">{persona.nombre}</Link><p className="mt-1 text-xs text-slate-500">DNI {persona.documento || 'no registrado'} · {persona.unidad || 'Sin unidad registrada'}</p></>
}

function Reservado() {
  return <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] text-slate-500" title="Pendiente de definir con la institución quién puede ver calificaciones y puntajes.">Reservado</span>
}

function Documento({ registro }) {
  return registro.tiene_documento ? <span className="rounded-full bg-teal-50 px-2.5 py-1 text-[11px] text-teal-700">Con documento</span> : <span className="rounded-full bg-amber-50 px-2.5 py-1 text-[11px] text-amber-700">Sin documento</span>
}

const columnas = {
  evaluaciones: [
    ['Ciclo', r => r.ciclo_anio],
    ['Fecha', r => fecha(r.fecha)],
    ['Calificación', r => r.calificacion_oculta ? <Reservado /> : texto(r.calificacion, 'No registrada')],
    ['Puntaje', r => r.calificacion_oculta ? <Reservado /> : texto(r.puntaje)],
    ['Plan de mejora', r => r.plan_mejora ? <span className="rounded-full bg-violet-50 px-2.5 py-1 text-[11px] text-violet-700">Sí</span> : 'No'],
    ['Evaluador', r => texto(r.evaluador)],
  ],
  progresiones: [
    ['Fecha efectiva', r => fecha(r.fecha)],
    ['Nivel', r => <>{texto(r.nivel_anterior, 'Sin nivel previo')} <span className={muted}>→</span> <strong className="font-semibold text-slate-800">{r.nivel_nuevo}</strong></>],
    ['Resolución', r => texto(r.numero_resolucion)],
  ],
  ratificaciones: [
    ['Fecha de resolución', r => fecha(r.fecha)],
    ['Tipo', r => texto(r.tipo)],
    ['Categoría', r => <>{texto(r.categoria_anterior, 'Sin categoría previa')} <span className={muted}>→</span> <strong className="font-semibold text-slate-800">{texto(r.categoria_nueva)}</strong></>],
    ['Período', r => <>{fecha(r.periodo_inicio)} – {fecha(r.periodo_vencimiento)}</>],
    ['Resultado', r => texto(r.resultado)],
    ['Resolución', r => texto(r.numero_resolucion)],
  ],
  desplazamientos: [
    ['Tipo', r => texto(r.tipo)],
    ['Desde', r => fecha(r.fecha)],
    ['Hasta', r => r.fecha_fin ? fecha(r.fecha_fin) : <span className={muted}>Sin fecha de fin</span>],
    ['Unidades', r => <>{texto(r.unidad_origen, 'Sin origen')} <span className={muted}>→</span> {texto(r.unidad_destino, 'Sin destino')}</>],
    ['Puesto de destino', r => texto(r.puesto_destino)],
    ['Documento de sustento', r => texto(r.numero_documento)],
  ],
}

// puntajeActivo: opción configurable s07.evaluaciones.puntaje (con false no se muestra la columna «Puntaje»).
export default function Seccion07Tabla({ tipo, registros, puntajeActivo }) {
  const cols = tipo.key === 'evaluaciones' ? columnasEvaluacion(columnas.evaluaciones, puntajeActivo) : columnas[tipo.key]
  return <div className="overflow-x-auto"><table className="w-full text-left text-sm">
    <thead className="bg-slate-50 text-xs text-slate-500"><tr><th className="px-5 py-4 font-medium">Persona</th>{cols.map(([title]) => <th key={title} className="whitespace-nowrap px-5 py-4 font-medium">{title}</th>)}<th className="px-5 py-4 font-medium">Respaldo</th><th className="px-5 py-4 font-medium"><span className="sr-only">Legajo</span></th></tr></thead>
    <tbody>{registros.map(registro => <tr key={registro.id} className="border-t border-slate-100 hover:bg-teal-50/30">
      <td className={`${cell} min-w-56`}><Persona persona={registro.persona} tipo={tipo} /></td>
      {cols.map(([title, render]) => <td key={title} className={`${cell} text-slate-700`}>{render(registro)}</td>)}
      <td className={cell}><Documento registro={registro} /></td>
      <td className={cell}>{registro.persona && <Link className="whitespace-nowrap text-xs font-semibold text-teal-700 hover:underline" to={ficha(registro.persona, tipo)}>Abrir legajo →</Link>}</td>
    </tr>)}</tbody>
  </table></div>
}
