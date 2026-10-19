import { useId, useState } from 'react'
import { EstadoRespaldo } from '../componentes'
import { COPIA_EXTERNA, VERIFICACION, fecha, tamano } from '../estado'
import { LIMITES, MAXIMOS_BACKEND, acotar, textoConteo } from '../historial'
import { Seccion, VerTodos } from '../ui'

/**
 * Últimos respaldos de la institución (docker/produccion/respaldar.sh): solo metadatos, el contenido no pasa por el panel.
 * Llegan los más recientes primero; se ven los primeros LIMITES.respaldos y «Ver todos» muestra el resto de los recibidos.
 */
export default function RespaldosInstitucion({ institucion }) {
  const id = useId()
  const [expandido, setExpandido] = useState(false)
  const lista = institucion.respaldos || []
  const { visibles, total, hayMas } = acotar(lista, LIMITES.respaldos, expandido)
  return <Seccion titulo="Respaldos" acciones={<EstadoRespaldo institucion={institucion} />}>
    {lista.length === 0 ? <p className="mt-3 text-sm text-slate-500">Todavía no hay respaldos. Se generan en el servidor con docker/produccion/respaldar.sh (cada noche, con cron).</p> : <ol id={id} className="mt-3 divide-y divide-slate-100">
      {visibles.map(respaldo => <li key={respaldo.id} className="grid gap-1 py-3 text-sm">
        <div className="flex flex-wrap justify-between gap-2">
          <span><strong className={respaldo.estado === 'correcto' ? 'text-slate-700' : 'text-rose-700'}>{respaldo.estado === 'correcto' ? 'Correcto' : 'Fallido'}</strong>
            <span className="text-slate-500"> · base {tamano(respaldo.base_bytes)}{respaldo.archivos_bytes !== null && ` · archivos ${tamano(respaldo.archivos_bytes)}`}</span></span>
          <span className="text-xs text-slate-500">{fecha(respaldo.created_at)}</span>
        </div>
        <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs">
          <span className={COPIA_EXTERNA[respaldo.copia_externa]?.clase}>{COPIA_EXTERNA[respaldo.copia_externa]?.etiqueta || respaldo.copia_externa}</span>
          {respaldo.verificacion && <span className={VERIFICACION[respaldo.verificacion]?.clase}>{VERIFICACION[respaldo.verificacion]?.etiqueta} {fecha(respaldo.verificado_en)}</span>}
        </div>
        {respaldo.detalle && <p className="text-xs text-rose-700">{respaldo.detalle}</p>}
        {respaldo.verificacion === 'fallida' && respaldo.detalle_verificacion && <p className="text-xs text-rose-700">{respaldo.detalle_verificacion}</p>}
      </li>)}
    </ol>}
    <VerTodos hayMas={hayMas} expandido={expandido} onCambiar={setExpandido} total={total} controla={id}
      conteo={textoConteo({ visibles: visibles.length, total, nombre: 'respaldos', maximo: MAXIMOS_BACKEND.respaldos })} />
  </Seccion>
}
