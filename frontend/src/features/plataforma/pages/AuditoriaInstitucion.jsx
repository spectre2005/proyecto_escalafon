import { useId, useState } from 'react'
import { etiquetaAccion, fecha } from '../estado'
import { LIMITES, MAXIMOS_BACKEND, acotar, textoConteo } from '../historial'
import { Seccion, VerTodos } from '../ui'

/**
 * Operaciones de la plataforma sobre la institución (llegan con la institución, las más recientes primero: se actualizan
 * al recargarla). Se ven las primeras LIMITES.auditoria; «Ver todos» muestra el resto de las recibidas.
 */
export default function AuditoriaInstitucion({ institucion }) {
  const id = useId()
  const [expandido, setExpandido] = useState(false)
  const { visibles, total, hayMas } = acotar(institucion.auditoria, LIMITES.auditoria, expandido)
  return <Seccion titulo="Auditoría">
    {institucion.auditoria.length === 0 ? <p className="mt-3 text-sm text-slate-500">Sin operaciones registradas.</p> : <ol id={id} className="mt-3 divide-y divide-slate-100">
      {visibles.map(evento => <li key={evento.id} className="flex flex-wrap justify-between gap-2 py-3 text-sm"><span><strong className="text-slate-700">{etiquetaAccion(evento.accion)}</strong>{evento.datos_despues?.email && <span className="text-slate-500"> ({evento.datos_despues.email})</span>}{evento.motivo && <span className="text-slate-500"> — {evento.motivo}</span>}</span><span className="text-xs text-slate-500">{evento.administrador || 'Sistema'} · {fecha(evento.created_at)}</span></li>)}
    </ol>}
    <VerTodos hayMas={hayMas} expandido={expandido} onCambiar={setExpandido} total={total} controla={id}
      conteo={textoConteo({ visibles: visibles.length, total, nombre: 'eventos', maximo: MAXIMOS_BACKEND.auditoria })} />
  </Seccion>
}
