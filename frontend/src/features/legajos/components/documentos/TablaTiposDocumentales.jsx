import { useEffect, useRef, useState } from 'react'
import Icon from '../../../../components/ui/Icon'
import { resumenDocumental } from '../../config/resumenDocumental'
import DocumentoModal from './DocumentoModal'
import VistasDocumentales from './VistasDocumentales'

const boton = 'inline-flex items-center gap-1.5 whitespace-nowrap rounded-xl border border-teal-200 bg-teal-50 px-3 py-2 text-xs font-semibold text-teal-800 hover:bg-teal-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 disabled:opacity-50'
const botonIcono = 'inline-flex size-8 shrink-0 items-center justify-center rounded-lg border border-teal-200 bg-teal-50 text-teal-800 hover:bg-teal-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700'
const fecha = valor => valor ? valor.split('T')[0].split('-').reverse().join('/') : '—'
const identificar = item => item.id ?? item.codigo

export default function TablaTiposDocumentales({ elementos, clasificar, estado, puedeSubir, onSubir, deshabilitado, seleccionado, idDe = identificar, nombreDe, children }) {
  const [seleccion, setSeleccion] = useState(null)
  const contenido = useRef(null)
  const [previoSeleccionado, setPrevioSeleccionado] = useState(seleccionado)
  if (seleccionado !== previoSeleccionado) {
    setPrevioSeleccionado(seleccionado)
    if (seleccionado) setSeleccion(null)
  }
  const item = elementos.find(elemento => String(idDe(elemento)) === String(seleccion?.id))

  useEffect(() => {
    const consultar = event => {
      const encontrado = elementos.find(elemento => `tipo-${idDe(elemento)}` === event.detail)
      if (encontrado) setSeleccion({ id: idDe(encontrado), modo: 'ver' })
    }
    window.addEventListener('consultar-tipo-documental', consultar)
    return () => window.removeEventListener('consultar-tipo-documental', consultar)
  }, [elementos, idDe])

  function abrir(elemento, modo) {
    if (modo === 'subir') {
      setSeleccion(null)
      onSubir(elemento)
    } else {
      setSeleccion({ id: idDe(elemento), modo })
    }
  }
  function cerrar() {
    if (contenido.current?.querySelector('form[aria-busy="true"]')) return
    setSeleccion(null)
  }

  return <section className="rounded-2xl border border-slate-200 bg-white p-5" aria-label="Tipos de documentos">
    <VistasDocumentales clasica elementos={elementos} idDe={idDe} nombreDe={nombreDe} clasificar={clasificar}>
      {lista => <div className="overflow-x-auto rounded-xl border border-slate-100">
        <table className="tabla-documental w-full text-left text-sm">
          <thead className="bg-slate-50 text-xs text-slate-500"><tr>{['Tipo de documento', 'Estado', 'Cantidad', 'Última fecha', 'Número de folios', 'Acciones'].map(titulo => <th key={titulo} scope="col" className="px-4 py-3 font-medium">{titulo}</th>)}</tr></thead>
          <tbody>{lista.map(elemento => {
            const resumen = resumenDocumental(elemento)
            return <tr key={idDe(elemento)} className="border-t border-slate-100">
              <th scope="row" className="min-w-64 px-4 py-4 font-medium text-slate-800">{elemento.nombre}{elemento.activo === false && <span className="ml-2 text-xs text-slate-400">Inactivo</span>}</th>
              <td data-label="Estado" className="px-4 py-4">{estado(elemento)}</td>
              <td data-label="Cantidad" className="px-4 py-4">{elemento.restringido ? '—' : resumen.cantidad}</td>
              <td data-label="Última fecha" className="whitespace-nowrap px-4 py-4 text-slate-600">{elemento.restringido ? '—' : fecha(resumen.ultimaFecha)}</td>
              <td data-label="Número de folios" className="px-4 py-4">{elemento.restringido ? '—' : resumen.numeroFolios}</td>
              <td data-label="Acciones" className="px-4 py-4"><div className="flex items-center gap-2">
                {puedeSubir?.(elemento) && <button type="button" className={boton} disabled={deshabilitado?.(elemento)} aria-label={`Subir documento: ${elemento.nombre}`} onClick={() => abrir(elemento, 'subir')}><Icon name="paperclip" size={16} />Subir</button>}
                <button type="button" id={`item-${idDe(elemento)}`} className={botonIcono} title="Ver documentos" aria-label={`Ver documentos: ${elemento.nombre}`} onClick={() => abrir(elemento, 'ver')}><Icon name="eye" size={18} /></button>
              </div></td>
            </tr>
          })}</tbody>
        </table>
      </div>}
    </VistasDocumentales>
    {item && seleccion && <DocumentoModal titulo={`Documentos de ${item.nombre}`} onCancel={cerrar}>
      <div ref={contenido}>{children(item, seleccion.modo)}</div>
    </DocumentoModal>}
  </section>
}
