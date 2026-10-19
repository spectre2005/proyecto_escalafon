import { Fragment } from 'react'
import { cantidadDocumentos, ofrecerTipo } from '../../config/aplicacion'
import { vistaDe } from '../../config/vistasDocumentales'
import TablaTiposDocumentales from './TablaTiposDocumentales'

const observado = tipo => (tipo.lista || []).some(documento => !documento.subsanado && (documento.estado_visible || documento.values?.estado) === 'observado')

export default function GruposTipos({ tipos, noAplica, render, flujos, aplica = true, puedeSubir, onSubir, deshabilitado, seleccionado }) {
  const clasificar = tipo => vistaDe({
    aplica: aplica && tipo.aplica !== false && ofrecerTipo(noAplica, tipo),
    exigido: tipo.exigido === undefined ? false : tipo.exigido,
    cantidad: cantidadDocumentos(tipo),
    observado: observado(tipo),
  })
  function estado(tipo) {
    const estados = {
      pendientes: [observado(tipo) ? 'Observado' : tipo.exigido === null ? 'Revisar datos' : cantidadDocumentos(tipo) === 0 ? 'Falta documento' : 'Revisar datos', 'bg-slate-100 text-slate-600'],
      conDocumentos: ['Con documentos', 'bg-teal-50 text-teal-800'],
      opcionales: ['Opcional', 'bg-slate-100 text-slate-600'],
      noCorresponde: ['No corresponde', 'bg-slate-100 text-slate-600'],
    }
    const [texto, color] = estados[clasificar(tipo)]
    return <span className={`whitespace-nowrap rounded-full px-2.5 py-1 text-xs ${color}`}>{texto}</span>
  }
  return <>
  <TablaTiposDocumentales elementos={tipos} clasificar={clasificar} estado={estado}
    nombreDe={tipo => `${tipo.literal ? `${tipo.literal}) ` : ''}${tipo.nombre}`}
    puedeSubir={puedeSubir} onSubir={onSubir} deshabilitado={deshabilitado} seleccionado={seleccionado}>
    {(tipo, modo) => <ul className="list-none">{render(tipo, modo)}</ul>}
  </TablaTiposDocumentales>
  {flujos && tipos.map(tipo => <Fragment key={tipo.id}>{flujos(tipo)}</Fragment>)}
  </>
}
