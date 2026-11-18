import { useState } from 'react'
import { etiquetaCampo } from '../../components/pdf/escanerCampos'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'
import { fraseAcierto, ordenarCampos, tramos, veredicto } from './lecturaIa'

// Oculto por ahora (decisión del 18/11/2026): no se muestra en Configuración. Para volver a mostrarlo, agrégalo a las
// secciones de SettingsPage.jsx. El acierto se sigue registrando (lecturas_ia) y el endpoint de resumen sigue activo.
// Qué tan bien lee la IA los PDF, en palabras de quien la usa. Sale de lo que el personal guardó después de leer un PDF
// con IA: solo resultados (guardado sin cambios, corregido…), nunca los datos de los documentos.

const PERIODOS = [[30, 'Últimos 30 días'], [90, 'Últimos 90 días'], [365, 'Último año']]
const TONOS = {
  bien: { clase: 'bg-emerald-50 text-emerald-800', icono: '✓' },
  aviso: { clase: 'bg-amber-50 text-amber-800', icono: '!' },
  mal: { clase: 'bg-rose-50 text-rose-800', icono: '✕' },
  neutro: { clase: 'bg-slate-100 text-slate-600', icono: '–' },
}
// Tramos de la barra de cada dato (colores validados para daltonismo; siempre con su cifra en texto al lado).
const TRAMOS = {
  aceptado: { nombre: 'Guardado sin cambios', color: 'bg-emerald-500' },
  corregido: { nombre: 'Corregido por el personal', color: 'bg-amber-500' },
  borrado: { nombre: 'Borrado por el personal', color: 'bg-rose-500' },
}
const TITULAR = [
  ['distinto', 'mal', 'Parecían de otra persona', 'El DNI leído era de otra persona y su nombre no aparecía.'],
  ['dni_distinto', 'aviso', 'DNI distinto, mismo nombre', 'Probablemente un error al leer el escaneo.'],
  ['sin_datos', 'aviso', 'Sin DNI ni nombre', 'El documento no mostraba a quién pertenece.'],
]

function Veredicto({ acierto, propuestos }) {
  const { texto, tono } = veredicto(acierto, propuestos)
  return <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-semibold ${TONOS[tono].clase}`}>
    <span aria-hidden="true">{TONOS[tono].icono}</span>{texto}
  </span>
}

function Dato({ titulo, valor, ayuda }) {
  return <div className="rounded-xl bg-slate-50 p-4">
    <p className="text-xs text-slate-500">{titulo}</p>
    <p className="mt-1 text-xl font-semibold text-slate-800">{valor}</p>
    {ayuda && <p className="mt-1 text-xs text-slate-500">{ayuda}</p>}
  </div>
}

function FilaCampo({ fila }) {
  const partes = tramos(fila)
  const extras = [
    fila.no_detectado > 0 && `${fila.no_detectado} ${fila.no_detectado === 1 ? 'vez' : 'veces'} no lo encontró y el personal lo llenó a mano`,
    fila.sugerencia_usada > 0 && `${fila.sugerencia_usada} ${fila.sugerencia_usada === 1 ? 'vez' : 'veces'} se usó su «posible dato»`,
  ].filter(Boolean)
  return <li className="py-3">
    <div className="flex flex-wrap items-center justify-between gap-2">
      <p className="text-sm font-medium text-slate-800">{etiquetaCampo(fila.campo)}</p>
      {fila.propuestos > 0 && <Veredicto acierto={fila.acierto} propuestos={fila.propuestos} />}
    </div>
    {fila.propuestos > 0 ? <>
      <div className="mt-2 flex h-2.5 gap-0.5 overflow-hidden rounded-full bg-slate-100" role="img"
        aria-label={partes.map(parte => `${TRAMOS[parte.clave].nombre}: ${parte.valor}`).join(', ')}>
        {partes.map(parte => <span key={parte.clave} title={`${TRAMOS[parte.clave].nombre}: ${parte.valor}`} className={`h-full ${TRAMOS[parte.clave].color}`} style={{ width: `${parte.ancho}%` }} />)}
      </div>
      <p className="mt-1.5 text-xs text-slate-600">
        <strong className="font-semibold text-slate-800">{fila.aceptado} de {fila.propuestos}</strong> sin cambios
        {fila.corregido > 0 && ` · ${fila.corregido} corregido${fila.corregido === 1 ? '' : 's'}`}
        {fila.borrado > 0 && ` · ${fila.borrado} borrado${fila.borrado === 1 ? '' : 's'}`}
      </p>
    </> : <p className="mt-1 text-xs text-slate-500">La IA no lo completó en ningún documento.</p>}
    {extras.length > 0 && <p className="mt-1 text-xs text-slate-500">{extras.join(' · ')}.</p>}
  </li>
}

export default function LecturaIaConfig() {
  const [dias, setDias] = useState(90)
  const consulta = useLiveQuery(`/documentos/escaner/precision?dias=${dias}`)
  const datos = consulta.data?.data
  const campos = ordenarCampos(datos?.por_campo)
  const propuestos = campos.reduce((total, fila) => total + (fila.propuestos || 0), 0)
  const avisosTitular = TITULAR.filter(([estado]) => datos?.titular?.[estado])

  return <section aria-label="Lectura de documentos con IA" className="rounded-2xl border border-slate-200 bg-white p-6 sm:p-8">
    <div className="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h2 className="font-semibold text-slate-800">Lectura de documentos con IA</h2>
        <p className="mt-2 max-w-2xl text-sm text-slate-500">Muestra si los datos que propone la IA al leer un PDF son confiables, para saber en cuáles conviene revisar con más cuidado antes de guardar.</p>
      </div>
      <label className="text-xs text-slate-500">Periodo
        <select value={dias} onChange={evento => setDias(Number(evento.target.value))}
          className="mt-1 block rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-teal-600">
          {PERIODOS.map(([valor, texto]) => <option key={valor} value={valor}>{texto}</option>)}
        </select>
      </label>
    </div>

    {consulta.error && <p role="alert" className="mt-6 rounded-xl bg-rose-50 p-4 text-sm text-rose-700">No se pudo cargar el resumen. Vuelve a intentarlo en unos segundos.</p>}
    {!datos && !consulta.error && <p className="mt-6 text-sm text-slate-500">Cargando…</p>}
    {datos && datos.lecturas === 0 && <div className="mt-6 rounded-xl bg-slate-50 p-5 text-sm text-slate-600">
      <p className="font-semibold text-slate-800">Todavía no hay lecturas en este periodo.</p>
      <p className="mt-1">Cuando el personal suba un PDF, pulse el botón del robot y guarde el documento, aquí verás qué tan bien lee la IA cada dato.</p>
    </div>}

    {datos && datos.lecturas > 0 && <>
      {/* 1. ¿Es confiable? */}
      <div className="mt-6 rounded-2xl border border-slate-200 p-5 sm:p-6">
        <p className="text-sm font-semibold text-slate-700">¿Qué tan bien lee la IA?</p>
        <div className="mt-3 flex flex-wrap items-center gap-4">
          <p className="text-5xl font-bold text-slate-800">{datos.acierto_general === null ? '—' : `${datos.acierto_general} %`}</p>
          <Veredicto acierto={datos.acierto_general} propuestos={propuestos} />
        </div>
        <p className="mt-3 text-sm text-slate-600">{fraseAcierto(datos.acierto_general)}</p>
        <p className="mt-1 text-xs text-slate-500">Calculado con {datos.evaluadas} {datos.evaluadas === 1 ? 'documento guardado' : 'documentos guardados'} de {datos.lecturas} {datos.lecturas === 1 ? 'leído' : 'leídos'}. Las lecturas que no terminaron en un documento guardado no cuentan.</p>
      </div>

      <div className="mt-4 grid gap-3 sm:grid-cols-3">
        <Dato titulo="Documentos leídos con IA" valor={datos.lecturas} />
        <Dato titulo="Tiempo promedio por lectura" valor={datos.segundos_promedio === null ? '—' : `${Math.round(datos.segundos_promedio)} s`} ayuda="Depende del equipo y de las páginas escaneadas" />
        <Dato titulo="Avisos de documento de otra persona" valor={datos.titular?.distinto || 0} ayuda="Se avisó antes de guardar" />
      </div>

      {/* 2. ¿Qué datos lee bien? */}
      <div className="mt-8">
        <h3 className="text-sm font-semibold text-slate-800">¿Qué datos lee bien y cuáles hay que revisar?</h3>
        <p className="mt-1 text-xs text-slate-500">Arriba, los que más se corrigen. Revisa esos con más cuidado al leer un documento.</p>
        {campos.length === 0
          ? <p className="mt-3 rounded-xl bg-slate-50 p-4 text-sm text-slate-500">Aún no hay documentos guardados después de leerlos con IA.</p>
          : <>
            <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600" aria-label="Leyenda">
              {Object.values(TRAMOS).map(tramo => <li key={tramo.nombre} className="flex items-center gap-1.5"><span aria-hidden="true" className={`size-2.5 rounded-full ${tramo.color}`} />{tramo.nombre}</li>)}
            </ul>
            <ul className="mt-2 divide-y divide-slate-100">{campos.map(fila => <FilaCampo key={fila.campo} fila={fila} />)}</ul>
            <details className="mt-3 text-sm">
              <summary className="cursor-pointer text-xs font-semibold text-teal-800">Ver como tabla</summary>
              <div className="mt-2 overflow-x-auto">
                <table className="w-full min-w-[560px] text-left text-xs">
                  <thead className="text-slate-500"><tr>
                    <th className="py-2 pr-3 font-medium">Dato</th><th className="px-2 py-2 text-right font-medium">Acierto</th>
                    <th className="px-2 py-2 text-right font-medium">Sin cambios</th><th className="px-2 py-2 text-right font-medium">Corregidos</th>
                    <th className="px-2 py-2 text-right font-medium">Borrados</th><th className="px-2 py-2 text-right font-medium">No los encontró</th>
                    <th className="py-2 pl-2 text-right font-medium">Posible dato usado</th>
                  </tr></thead>
                  <tbody className="divide-y divide-slate-100 tabular-nums text-slate-700">{campos.map(fila => <tr key={fila.campo}>
                    <td className="py-2 pr-3">{etiquetaCampo(fila.campo)}</td><td className="px-2 py-2 text-right">{fila.acierto === null ? '—' : `${fila.acierto} %`}</td>
                    <td className="px-2 py-2 text-right">{fila.aceptado}</td><td className="px-2 py-2 text-right">{fila.corregido}</td>
                    <td className="px-2 py-2 text-right">{fila.borrado}</td><td className="px-2 py-2 text-right">{fila.no_detectado}</td>
                    <td className="py-2 pl-2 text-right">{fila.sugerencia_usada}</td>
                  </tr>)}</tbody>
                </table>
              </div>
            </details>
          </>}
      </div>

      {/* 3. ¿Hubo documentos de otra persona? */}
      <div className="mt-8">
        <h3 className="text-sm font-semibold text-slate-800">¿Hubo documentos que no eran de la persona?</h3>
        <p className="mt-1 text-xs text-slate-500">Al leer, la IA compara el DNI y el nombre del documento con la persona del legajo y avisa antes de guardar.</p>
        {avisosTitular.length === 0
          ? <p className="mt-3 flex items-center gap-2 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-800"><span aria-hidden="true">✓</span>Ningún aviso en este periodo: los documentos leídos coincidían con su persona.</p>
          : <ul className="mt-3 grid gap-2 sm:grid-cols-3">{avisosTitular.map(([estado, tono, titulo, explicacion]) => <li key={estado} className={`rounded-xl p-3 ${TONOS[tono].clase}`}>
            <p className="flex items-center gap-1.5 text-sm font-semibold"><span aria-hidden="true">{TONOS[tono].icono}</span>{titulo}: {datos.titular[estado]}</p>
            <p className="mt-1 text-xs">{explicacion}</p>
          </li>)}</ul>}
      </div>

      {/* 4. Cómo se calcula */}
      <details className="mt-8 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
        <summary className="cursor-pointer text-xs font-semibold text-slate-700">¿Cómo se calcula?</summary>
        <ul className="mt-3 list-disc space-y-1.5 pl-5 text-xs">
          <li><strong>Acierto:</strong> de los datos que completó la IA, cuántos se guardaron sin cambios.</li>
          <li><strong>Corregido:</strong> el personal cambió lo que propuso la IA. <strong>Borrado:</strong> lo dejó vacío.</li>
          <li><strong>No lo encontró:</strong> la IA no completó el dato y el personal lo escribió a mano.</li>
          <li><strong>Posible dato usado:</strong> la IA no pudo comprobarlo, lo ofreció como posible y el personal lo eligió.</li>
          <li>Con menos de 5 datos el resultado se marca «Pocos datos aún». Nunca se guardan los datos de los documentos, solo lo que pasó con cada uno.</li>
        </ul>
      </details>
      {Object.keys(datos.modelos || {}).length > 0 && <p className="mt-4 text-xs text-slate-500">Modelo de IA: {Object.keys(datos.modelos).join(', ')} (local, sin enviar datos a internet).</p>}
    </>}
  </section>
}
