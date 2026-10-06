const fecha = value => value.split('-').reverse().join('/')
const dia = value => Date.parse(`${value}T00:00:00Z`) / 86400000

export default function LineaTiempoExperiencia({ tiempo }) {
  const periodos = tiempo.periodos
  if (!periodos.length) return <section className="rounded-2xl border border-slate-200 bg-white p-5"><h3 className="font-semibold text-slate-800">Línea de tiempo de experiencia</h3><p className="mt-2 text-sm text-slate-500">Todavía no hay periodos sustentados. Agrega un certificado o constancia con las fechas del servicio.</p></section>
  const inicio = Math.min(...periodos.map(p => dia(p.fecha_inicio)))
  const fin = Math.max(...periodos.map(p => dia(p.hasta)))
  const escala = Math.max(1, fin - inicio + 1)
  const barra = (desde, hasta) => ({ left: `${(dia(desde) - inicio) / escala * 100}%`, width: `${(dia(hasta) - dia(desde) + 1) / escala * 100}%` })
  return <section aria-label="Línea de tiempo de experiencia" className="min-w-0 rounded-2xl border border-slate-200 bg-white p-5">
    <h3 className="font-semibold text-slate-800">Línea de tiempo de experiencia</h3>
    <p className="mt-2 text-xs text-slate-500">Todas las barras comparten la misma escala. Público: verde. Privado: azul. Se muestra la experiencia sustentada de todos los legajos de la persona.</p>
    <ol className="mt-4 space-y-4">{periodos.map(p => <li key={p.id} className="min-w-0">
      <p className="break-words text-sm font-medium text-slate-800">{p.entidad_empresa} · {p.cargo}</p>
      <p className="mt-1 text-xs text-slate-500">{fecha(p.fecha_inicio)} – {p.fecha_fin ? fecha(p.hasta) : `Hasta la fecha (${fecha(p.hasta)})`} · {p.sector === 'publico' ? 'Público' : 'Privado'} · {p.cuenta ? 'Cuenta en el cálculo' : 'Aún no cuenta en el cálculo'}</p>
      <div aria-hidden="true" className="relative mt-2 h-3 overflow-hidden rounded-full bg-slate-100"><span className={`absolute h-full rounded-full ${p.sector === 'publico' ? 'bg-teal-700' : 'bg-blue-700'}`} style={barra(p.fecha_inicio, p.hasta)} /></div>
    </li>)}</ol>
    {tiempo.solapamientos.length > 0 && <p role="status" className="mt-4 rounded-xl bg-amber-50 p-3 text-xs text-amber-900">Hay {tiempo.solapamientos.length} superposición(es). El cálculo total cuenta cada día una sola vez. RR. HH. puede solicitar una aclaración.</p>}
    {tiempo.huecos.length > 0 && <ul className="mt-4 space-y-3">{tiempo.huecos.map(h => <li key={h.desde} className={`rounded-xl p-3 text-xs ${h.largo ? 'bg-amber-50 text-amber-900' : 'bg-slate-50 text-slate-600'}`}>
      <p>{h.largo ? 'Hueco largo: ' : 'Intervalo sin experiencia declarada: '}{fecha(h.desde)} – {fecha(h.hasta)} · {h.dias} días.{h.largo && ' Aviso informativo; no invalida la experiencia.'}</p>
      <div aria-hidden="true" className="relative mt-2 h-2 overflow-hidden rounded-full bg-white"><span className="absolute h-full bg-slate-400" style={barra(h.desde, h.hasta)} /></div>
    </li>)}</ul>}
    {tiempo.periodos_invalidos > 0 && <p role="status" className="mt-4 text-xs text-amber-900">Hay {tiempo.periodos_invalidos} periodo(s) histórico(s) con fechas o sector sin clasificar. No se incluyen en el cálculo.</p>}
  </section>
}
