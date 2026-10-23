import { Link } from 'react-router-dom'
import { useSesion } from '../auth/session'
import { resultados } from './config'

export default function EstadoFiscalizacion({ documento }) {
  const { permisos } = useSesion()
  const texto = documento.resultado_fiscalizacion ? `Fiscalización: ${resultados[documento.resultado_fiscalizacion] || documento.resultado_fiscalizacion}` : documento.fiscalizado ? 'Fiscalización conforme registrada' : 'Sin fiscalización registrada'
  return documento.proceso_fiscalizacion_id && permisos.includes('fiscalizacion.ver') ? <Link className="font-medium text-teal-700 underline" to={`/fiscalizacion?proceso=${documento.proceso_fiscalizacion_id}`}>{texto}</Link> : <span>{texto}</span>
}
