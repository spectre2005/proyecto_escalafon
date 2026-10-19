import { cantidadDocumentos, ofrecerTipo } from '../../config/aplicacion'
import { vistaDe } from '../../config/vistasDocumentales'
import VistasDocumentales from './VistasDocumentales'

const lista = 'divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200 bg-white'

// Un documento vigente (no subsanado) observado pide subsanarlo.
const observado = tipo => (tipo.lista || []).some(documento => !documento.subsanado && (documento.estado_visible || documento.values?.estado) === 'observado')

/**
 * Tipos documentales de las secciones con carga común (01, 02, 05, 07, 09 y 11) en las vistas Pendientes · Con documentos
 * · Opcionales · No corresponde. aplica: false cuando toda la subsección no aplica (2.4 o 7.2), y entonces todo va a
 * «No corresponde». Los que no corresponden se siguen viendo allí, con sus documentos y su subsanación.
 * render(tipo) devuelve el <li> del tipo (cada panel conserva sus filas y acciones).
 */
export default function GruposTipos({ tipos, noAplica, render, aplica = true, onSubir, puedeSubir }) {
  const clasificar = tipo => vistaDe({
    aplica: aplica && tipo.aplica !== false && ofrecerTipo(noAplica, tipo),
    exigido: tipo.exigido === undefined ? false : tipo.exigido,
    cantidad: cantidadDocumentos(tipo),
    observado: observado(tipo),
  })
  const nombre = tipo => `${tipo.literal ? `${tipo.literal}) ` : ''}${tipo.nombre}`
  return <VistasDocumentales elementos={tipos} clasificar={clasificar} nombreDe={nombre} onSubir={onSubir} puedeSubir={puedeSubir}>
    {visibles => <ul className={lista}>{visibles.map(render)}</ul>}
  </VistasDocumentales>
}
