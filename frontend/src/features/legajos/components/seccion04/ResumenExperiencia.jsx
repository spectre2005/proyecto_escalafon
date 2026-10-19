import { aniosDecimal, duracion, subtotalesSuperanTotal } from '../../config/seccion04'

const fecha = value => value ? value.split('-').reverse().join('/') : 'No registrada'

// Cuánta experiencia sustentada tiene la persona, debajo de los documentos y plegado: cerrado solo muestra el total.
// El detalle del cálculo (días calendario y configuración) queda además en «¿Cómo se calcula?».
export default function ResumenExperiencia({ tiempo, resumen, folios }) {
  const { total, publico, privado, configuracion } = tiempo
  return <details className="group rounded-2xl border border-slate-200 bg-white px-5 py-4">
    <summary className="flex cursor-pointer list-none flex-wrap items-baseline gap-x-3 gap-y-1">
      <span className="text-sm font-semibold text-slate-800">Experiencia total: {duracion(total)}</span>
      <span className="text-xs text-slate-500">{aniosDecimal(total)} · al {fecha(tiempo.fecha_corte)}</span>
      <span className="ml-auto text-xs font-semibold text-teal-700 group-open:hidden">Ver detalle +</span><span className="ml-auto hidden text-xs font-semibold text-teal-700 group-open:inline">Ocultar −</span>
    </summary>
    <div className="mt-4 grid gap-5 border-t border-slate-100 pt-4 sm:grid-cols-2">
      {[['Sector público', publico, 'bg-teal-700'], ['Sector privado', privado, 'bg-blue-700']].map(([titulo, t, color]) => <div key={titulo}>
        <p className="flex items-center gap-2 text-xs text-slate-500"><span aria-hidden="true" className={`size-2 rounded-full ${color}`} />{titulo}</p>
        <p className="mt-2 text-base font-semibold text-slate-800">{duracion(t)}</p>
        <p className="mt-1 text-xs text-slate-500">{aniosDecimal(t)}</p>
      </div>)}
    </div>
    {subtotalesSuperanTotal(tiempo) && <p className="mt-4 text-xs text-slate-500">Hay periodos simultáneos: público y privado suman más que el total, que cuenta cada día una sola vez.</p>}
    <div className="mt-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t border-slate-100 pt-3 text-xs text-slate-500">
      <span>{resumen.aceptados} de {resumen.exigidos} exigidos aceptados · {resumen.observados} observado(s) · {resumen.faltantes} faltante(s) · {folios} folio(s)</span>
      <details>
        <summary className="cursor-pointer font-semibold text-teal-700">¿Cómo se calcula?</summary>
        <div className="mt-2 space-y-1 leading-relaxed">
          <p>Total: {total.dias_totales} días calendario · Público: {publico.dias_totales} · Privado: {privado.dias_totales}.</p>
          <p>Mes de {configuracion.dias_por_mes} días y año de {configuracion.dias_por_anio} días. {configuracion.incluir_pendientes ? 'Incluye documentos en revisión.' : 'Cuenta los documentos aceptados.'} Se suma la experiencia sustentada de todos los legajos de la persona.</p>
        </div>
      </details>
    </div>
  </details>
}
