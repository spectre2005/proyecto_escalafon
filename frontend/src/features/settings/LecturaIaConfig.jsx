import { useState } from 'react'
import { etiquetaCampo } from '../../components/pdf/escanerCampos'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'

// Cuánto acierta la lectura de PDF con IA, por campo. Sale de lo que cada persona guardó tras leer un PDF: solo
// resultados (aceptado, corregido…), nunca los datos del documento.

const PERIODOS = [30, 90, 365]
const TITULAR = {
  distinto: 'Parecían de otra persona', dni_distinto: 'DNI leído distinto (mismo nombre)', sin_datos: 'Sin DNI ni nombre de la persona',
  coincide: 'Coincide el DNI', coincide_nombre: 'Coincide el nombre',
}
const porcentaje = valor => valor === null || valor === undefined ? '—' : `${valor} %`
const colorAcierto = valor => valor === null ? 'text-slate-400' : valor >= 80 ? 'text-emerald-700' : valor >= 60 ? 'text-amber-700' : 'text-rose-700'

function Dato({ titulo, valor, ayuda }) {
  return <div className="rounded-xl border border-slate-200 p-4">
    <p className="text-xs text-slate-500">{titulo}</p>
    <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-800">{valor}</p>
    {ayuda && <p className="mt-1 text-[11px] text-slate-400">{ayuda}</p>}
  </div>
}

export default function LecturaIaConfig() {
  const [dias, setDias] = useState(90)
  const consulta = useLiveQuery(`/documentos/escaner/precision?dias=${dias}`)
  const datos = consulta.data?.data

  return <section aria-label="Acierto de la lectura con IA" className="rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
    <div className="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h2 className="font-semibold text-slate-800">Acierto de la lectura con IA</h2>
        <p className="mt-2 max-w-2xl text-sm text-slate-500">Qué hizo el personal con cada dato que propuso la IA al guardar el documento. Acierto = datos aceptados tal cual entre todos los que propuso. No se guarda ningún dato de los documentos.</p>
      </div>
      <div role="group" aria-label="Periodo" className="flex gap-1">
        {PERIODOS.map(periodo => <button key={periodo} type="button" aria-pressed={dias === periodo} onClick={() => setDias(periodo)}
          className={`rounded-full px-3 py-1.5 text-xs font-semibold ${dias === periodo ? 'bg-teal-700 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}>{periodo === 365 ? '1 año' : `${periodo} días`}</button>)}
      </div>
    </div>

    {consulta.error && <p role="alert" className="mt-6 rounded-xl bg-rose-50 p-4 text-sm text-rose-700">No se pudo cargar el resumen.</p>}
    {!datos && !consulta.error && <p className="mt-6 text-sm text-slate-500">Cargando…</p>}
    {datos && <>
      <div className="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Dato titulo="Acierto general" valor={porcentaje(datos.acierto_general)} />
        <Dato titulo="Lecturas" valor={datos.lecturas} ayuda={`${datos.evaluadas} con el documento guardado`} />
        <Dato titulo="Tiempo promedio" valor={datos.segundos_promedio === null ? '—' : `${datos.segundos_promedio} s`} />
        <Dato titulo="Posible documento de otra persona" valor={datos.titular?.distinto || 0} ayuda="Avisos mostrados antes de guardar" />
      </div>

      {datos.por_campo.length === 0
        ? <p className="mt-6 rounded-xl bg-slate-50 p-4 text-sm text-slate-500">Todavía no hay lecturas con el documento guardado en este periodo. El resumen aparece cuando el personal lea un PDF con IA y guarde el formulario.</p>
        : <div className="mt-6 overflow-x-auto">
          <table className="w-full min-w-[640px] text-left text-sm">
            <thead className="text-xs text-slate-500"><tr>
              <th className="py-2 pr-3 font-medium">Campo</th>
              <th className="px-3 py-2 text-right font-medium">Acierto</th>
              <th className="px-3 py-2 text-right font-medium">Aceptados</th>
              <th className="px-3 py-2 text-right font-medium">Corregidos</th>
              <th className="px-3 py-2 text-right font-medium">Borrados</th>
              <th className="px-3 py-2 text-right font-medium" title="La IA no lo completó y la persona sí lo llenó">No detectados</th>
              <th className="py-2 pl-3 text-right font-medium" title="Se guardó el «posible dato» que ofreció la IA">Sugerencias usadas</th>
            </tr></thead>
            <tbody className="divide-y divide-slate-100 tabular-nums text-slate-700">
              {datos.por_campo.map(fila => <tr key={fila.campo}>
                <td className="py-2 pr-3 capitalize">{etiquetaCampo(fila.campo)}</td>
                <td className={`px-3 py-2 text-right font-semibold ${colorAcierto(fila.acierto)}`}>{porcentaje(fila.acierto)}</td>
                <td className="px-3 py-2 text-right">{fila.aceptado}</td>
                <td className="px-3 py-2 text-right">{fila.corregido}</td>
                <td className="px-3 py-2 text-right">{fila.borrado}</td>
                <td className="px-3 py-2 text-right">{fila.no_detectado}</td>
                <td className="py-2 pl-3 text-right">{fila.sugerencia_usada}</td>
              </tr>)}
            </tbody>
          </table>
        </div>}

      {Object.keys(datos.titular || {}).length > 0 && <div className="mt-6">
        <h3 className="text-sm font-semibold text-slate-700">Comprobación del titular</h3>
        <ul className="mt-2 flex flex-wrap gap-2 text-xs">
          {Object.entries(datos.titular).map(([estado, total]) => <li key={estado} className="rounded-full bg-slate-100 px-3 py-1 text-slate-600">{TITULAR[estado] || estado}: <strong className="tabular-nums">{total}</strong></li>)}
        </ul>
      </div>}
      {Object.keys(datos.modelos || {}).length > 0 && <p className="mt-4 text-xs text-slate-400">Modelo: {Object.entries(datos.modelos).map(([modelo, total]) => `${modelo} (${total})`).join(', ')}</p>}
    </>}
  </section>
}
