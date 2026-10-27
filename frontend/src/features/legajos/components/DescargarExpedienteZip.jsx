import { useState } from 'react'
import api, { apiErrorMessage } from '../../../axios'
import { useFuncionalidades } from '../../../shared/useFuncionalidades'
import { usePuede } from '../../auth/session'
import DocumentoModal from './documentos/DocumentoModal'
import { opcionesVisibles, SIEMPRE, urlZip } from '../expedienteImprimible'

const boton = 'whitespace-nowrap rounded-lg border border-teal-200 px-3 py-2 text-xs font-semibold text-teal-800 hover:bg-teal-50 disabled:opacity-60'

// «Descargar ZIP» del legajo: secciones 01 a 04 siempre; los demás grupos, solo si se marcan. El servidor arma el ZIP
// (originales por sección y folio, con 00_INDICE.html) y vuelve a aplicar permisos y configuración.
export default function DescargarExpedienteZip({ usuario }) {
  const [abierto, setAbierto] = useState(false)
  const [seleccion, setSeleccion] = useState([])
  const [estado, setEstado] = useState({ ocupado: false, error: '' })
  const opciones = opcionesVisibles(useFuncionalidades(), usePuede('datos_sensibles.ver'))
  function cerrar() { if (!estado.ocupado) { setAbierto(false); setSeleccion([]); setEstado({ ocupado: false, error: '' }) } }
  async function descargar() {
    setEstado({ ocupado: true, error: '' })
    try {
      const respuesta = await api.get(urlZip(usuario, seleccion), { responseType: 'blob', timeout: 120000 })
      const nombre = /filename="?([^";]+)"?/.exec(respuesta.headers['content-disposition'] || '')?.[1] || 'expediente.zip'
      const url = URL.createObjectURL(respuesta.data)
      Object.assign(document.createElement('a'), { href: url, download: nombre }).click()
      URL.revokeObjectURL(url)
      setEstado({ ocupado: false, error: '' })
      setAbierto(false)
      setSeleccion([])
    } catch (e) {
      // Con responseType blob, el mensaje del servidor llega dentro del Blob.
      let mensaje = apiErrorMessage(e)
      try { mensaje = JSON.parse(await e.response.data.text()).message || mensaje } catch { /* se queda el mensaje general */ }
      setEstado({ ocupado: false, error: mensaje })
    }
  }
  return <>
    <button type="button" className={`mt-4 ${boton}`} onClick={() => setAbierto(true)}>Descargar ZIP</button>
    {abierto && <DocumentoModal titulo="Descargar expediente en ZIP" busy={estado.ocupado} onCancel={cerrar} compacto>
      <div className="space-y-4 text-sm text-slate-700">
        <p><span className="font-semibold">Se incluye siempre:</span> {SIEMPRE}</p>
        {opciones.length > 0 && <fieldset className="space-y-2"><legend className="font-semibold">Añadir también (opcional)</legend>
          {opciones.map(opcion => <label key={opcion.clave} className="flex items-start gap-2"><input type="checkbox" className="mt-0.5 accent-teal-700" disabled={estado.ocupado}
            checked={seleccion.includes(opcion.clave)} onChange={e => setSeleccion(actual => e.target.checked ? [...actual, opcion.clave] : actual.filter(clave => clave !== opcion.clave))} /><span>{opcion.etiqueta}</span></label>)}
        </fieldset>}
        <p className="text-xs text-slate-500">El ZIP trae los PDF originales ordenados por sección y folio, y un índice (00_INDICE.html) para imprimir que indica lo incluido y lo que no.</p>
        {estado.error && <p role="alert" className="text-rose-800">{estado.error}</p>}
        <div className="flex flex-wrap gap-2">
          <button type="button" className={boton} disabled={estado.ocupado} onClick={descargar}>{estado.ocupado ? 'Preparando ZIP…' : 'Descargar'}</button>
          <button type="button" className={boton} disabled={estado.ocupado} onClick={cerrar}>Cancelar</button>
        </div>
      </div>
    </DocumentoModal>}
  </>
}
