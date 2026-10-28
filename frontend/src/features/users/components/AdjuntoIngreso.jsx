import EscanerIngreso from './EscanerIngreso'

export default function AdjuntoIngreso({ archivo, onArchivo, maximoKb, puedeSubir, errorArchivo, onAplicarEscaneo }) {
  return <div className="col-span-full space-y-3 rounded-xl border border-[#dce7e0] bg-[#f7faf8] p-4">
    <div className="flex items-center justify-between gap-3">
      <h4 className="text-xs font-semibold text-[#3f6251]">Leer resolución o contrato (PDF)</h4>
      {puedeSubir && <EscanerIngreso archivo={archivo} onAplicar={onAplicarEscaneo} />}
    </div>
    {!puedeSubir ? <p className="text-xs text-[#7b9181]">Tu cuenta no tiene permiso para utilizar el escáner. Puedes completar los tres campos manualmente.</p> : <>
      <label>Documento PDF (opcional)<input type="file" accept="application/pdf,.pdf" onChange={event => { onArchivo(event.target.files?.[0] || null); event.target.value = '' }} className="mt-2 block w-full text-xs" /></label>
      <p className="text-[11px] text-[#7b9181]">Máximo {Math.round(maximoKb / 1024 * 10) / 10} MB. Se leen hasta 3 páginas para completar los campos superiores. Este PDF no se guarda en el legajo.</p>
      {errorArchivo && <p role="alert" className="text-xs text-[#ac4d3a]">{errorArchivo}</p>}
      {archivo && <div className="flex items-center justify-between gap-3 text-xs"><span className="truncate font-semibold" title={archivo.name}>{archivo.name}</span><button type="button" onClick={() => onArchivo(null)} className="shrink-0 text-[#ac4d3a] underline">Quitar archivo</button></div>}
    </>}
  </div>
}
