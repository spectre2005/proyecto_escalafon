import { OPCIONES_POR_PAGINA, textoRango } from './paginacionUrl'

// Pie de paginación de los listados. Personal es la referencia del formato y de la paleta; cada pantalla conserva las
// dimensiones que ya tenía su paginación (tamano). Solo presenta: cada listado le pasa sus números, pagine en el
// servidor o en el navegador.
const TAMANOS = {
  // Personal: 12 px, botones bajos.
  compacto: { pie: 'gap-4 border-t border-[#edf2ee] px-4 py-4 text-xs md:px-6', boton: 'rounded-lg px-3 py-2' },
  // Requerimientos: 14 px y botones en negrita.
  normal: { pie: 'gap-3 border-t border-[#edf2ee] p-5 text-sm', boton: 'rounded-xl px-4 py-2 font-semibold' },
  // Vencimientos: como normal, con botones más altos y anillo de foco.
  amplio: { pie: 'gap-3 border-t border-[#edf2ee] p-5 text-sm', boton: 'rounded-xl px-4 py-2.5 font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700' },
  // Reportes: contador de 12 px y botones de 14 px en negrita, con anillo de foco.
  reporte: { pie: 'gap-3 border-t border-[#edf2ee] p-4 text-xs', boton: 'inline-flex items-center justify-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-teal-700' },
  // Legajos (directorio y registros): sin borde ni relleno propios, 20 px sobre el pie, texto de 12 px y botones de 14 px.
  legajo: { pie: 'mt-5 gap-3 text-xs', boton: 'rounded-xl px-3 py-2.5 text-sm' },
}

export default function Paginacion({ etiqueta, sustantivo, desde, hasta, total, pagina, paginas, porPagina, opciones = OPCIONES_POR_PAGINA, onPagina, onPorPagina, extra = '', ocupado = false, tamano = 'compacto' }) {
  const medidas = TAMANOS[tamano] ?? TAMANOS.compacto
  const boton = `${medidas.boton} border border-[#dbe6e1] bg-white text-[#4f6d63] hover:bg-[#edf6f2] disabled:cursor-not-allowed disabled:opacity-50`
  const titulo = sustantivo.charAt(0).toUpperCase() + sustantivo.slice(1)
  return <footer className={`flex flex-wrap items-center justify-between text-[#60796c] ${medidas.pie}`}>
    <div className="flex flex-wrap items-center gap-3">
      {onPorPagina && <label className="flex items-center gap-2">
        {titulo} por página
        <select value={porPagina} disabled={ocupado} onChange={event => onPorPagina(Number(event.target.value))}>
          {opciones.map(size => <option key={size} value={size}>{size}</option>)}
        </select>
      </label>}
      <span aria-live="polite">{textoRango({ desde, hasta, total, sustantivo })}{extra}</span>
    </div>
    <nav aria-label={etiqueta} className="flex items-center gap-3">
      <button type="button" onClick={() => onPagina(pagina - 1)} disabled={ocupado || pagina <= 1} className={boton}>Anterior</button>
      <span>Página {pagina} de {paginas}</span>
      <button type="button" onClick={() => onPagina(pagina + 1)} disabled={ocupado || pagina >= paginas} className={boton}>Siguiente</button>
    </nav>
  </footer>
}
