import { useOutletContext } from 'react-router-dom'
import { defaultInstitution } from '../../../../config/institution'
import { folioFinalPorPaginas } from '../../config/documentos'

const enlace = 'font-semibold text-teal-700 underline-offset-2 hover:underline'

// Ayudas de los formularios de carga: proponen un valor que el usuario acepta con un clic o ignora. No son reglas ni
// validaciones nuevas; el backend valida lo de siempre.

// Folio final si cada página del PDF es un folio (regla de foliado pendiente de confirmar, P3): solo con «Usar».
export function SugerenciaFolio({ inicio, paginas, valor, onUsar }) {
  const sugerido = folioFinalPorPaginas(inicio, paginas)
  if (!sugerido || String(valor) === String(sugerido)) return null
  return <p role="note" className="text-[11px] text-slate-500 sm:col-span-2">
    El PDF tiene {paginas} página(s). Si cada página es un folio, el folio final sería {sugerido}.{' '}
    <button type="button" onClick={() => onUsar(String(sugerido))} className={enlace}>Usar {sugerido}</button>
  </p>
}

// Emisor = la propia institución (resoluciones, contratos y cargos que ella emite): su nombre sale de la configuración
// institucional, nunca del código. Sin nombre configurado no se propone nada.
export function EmisorInstitucion({ valor, onUsar }) {
  const nombre = useOutletContext()?.institution?.name?.trim()
  if (!nombre || nombre === defaultInstitution.name || valor === nombre) return null
  return <button type="button" onClick={() => onUsar(nombre)} className={`justify-self-start text-[11px] ${enlace}`}>Usar «{nombre}»</button>
}
