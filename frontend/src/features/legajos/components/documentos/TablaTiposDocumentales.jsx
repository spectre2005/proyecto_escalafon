import { Fragment, useId, useState } from 'react'
import Icon from '../../../../components/ui/Icon'
import { resumenTipoDocumento } from '../../config/tablaDocumentos'

const estados = {
  si: 'Sí', no: 'No', vencido: 'Vencido', aceptado: 'Aceptado', verificado: 'Verificado', pendiente: 'En revisión', cargado: 'Cargado', observado: 'Observado',
  falta: 'Falta', no_aplica: 'No corresponde', opcional: 'Opcional', por_determinar: 'Por determinar',
  recepcionado: 'Recepcionado', en_fiscalizacion: 'En fiscalización', restringido: 'Restringido', sin_documentos: 'Sin documentos',
}
const boton = 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-teal-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-teal-800 hover:bg-teal-50 focus-visible:outline-2 focus-visible:outline-teal-700 disabled:opacity-50'

export default function TablaTiposDocumentales({ elementos, idDe = tipo => tipo.id, nombreDe = tipo => tipo.nombre, renderDetalle, onSubir, puedeSubir = () => true, onVer, mostrarDetalleAlSubir = true }) {
  const [abierto, setAbierto] = useState(null)
  const prefix = useId()
  function abrir(tipo) { setAbierto(String(idDe(tipo))); onSubir(tipo) }
  return <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white" role="region" aria-label="Tabla de tipos documentales" tabIndex={0}><table className="w-full min-w-[660px] text-left text-xs"><caption className="sr-only">Documentos por tipo, estado, cantidad, última fecha y número de folios</caption><thead className="bg-slate-50 text-slate-500"><tr>{['Tipo de documento', 'Estado', 'Cantidad', 'Última fecha', 'Número de folios', 'Acciones'].map(label => <th key={label} scope="col" className="px-3 py-3 font-medium">{label}</th>)}</tr></thead><tbody>{elementos.map(tipo => {
    const key = String(idDe(tipo))
    const resumen = resumenTipoDocumento(tipo)
    const expandido = abierto === key
    const detalleId = `${prefix}-${key}`
    const color = ['si', 'aceptado', 'verificado', 'recepcionado'].includes(resumen.estado) ? 'bg-teal-50 text-teal-900' : ['vencido', 'observado', 'falta'].includes(resumen.estado) ? 'bg-rose-50 text-rose-800' : ['pendiente', 'en_fiscalizacion', 'por_determinar'].includes(resumen.estado) ? 'bg-amber-50 text-amber-900' : 'bg-slate-100 text-slate-600'
    return <Fragment key={key}><tr id={`tabla-tipo-${key}`} className="border-t border-slate-100 align-middle"><th scope="row" className="min-w-52 px-3 py-3 font-medium text-slate-800">{nombreDe(tipo)}</th><td className="px-3 py-3"><span className={`inline-block whitespace-nowrap rounded-full px-2 py-1 text-[11px] ${color}`}>{estados[resumen.estado] || resumen.estado.replaceAll('_', ' ')}</span></td><td className="px-3 py-3 tabular-nums">{resumen.cantidad ?? '—'}</td><td className="whitespace-nowrap px-3 py-3 text-slate-600">{resumen.fecha ? String(resumen.fecha).slice(0, 10).split('-').reverse().join('/') : '—'}</td><td className="px-3 py-3 tabular-nums">{resumen.folios ?? '—'}</td><td className="px-3 py-3"><div className="flex items-center gap-2">{onSubir && !tipo.restringido && tipo.activo !== false && puedeSubir(tipo) && <button type="button" className={boton} aria-label={`Subir documento: ${nombreDe(tipo)}`} onClick={() => { if (mostrarDetalleAlSubir) abrir(tipo); else onSubir(tipo) }}><Icon name="paperclip" size={15} />Subir</button>}<button type="button" className={boton} title={expandido ? 'Cerrar detalle' : 'Ver documentos y acciones'} data-abrir-detalle="true" aria-label={`Ver documentos: ${nombreDe(tipo)}`} aria-expanded={onVer ? undefined : expandido} aria-controls={expandido ? detalleId : undefined} onClick={() => onVer ? onVer(tipo) : setAbierto(expandido ? null : key)}><Icon name="eye" size={16} /></button></div></td></tr>{renderDetalle && <tr hidden={!expandido} id={detalleId}><td colSpan={6} className="border-t border-teal-100 bg-slate-50 p-3">{renderDetalle(tipo)}</td></tr>}</Fragment>
  })}</tbody></table></div>
}
