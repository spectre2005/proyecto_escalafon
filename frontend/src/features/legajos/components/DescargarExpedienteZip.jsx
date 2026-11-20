import { useState } from 'react'
import api, { apiErrorMessage } from '../../../axios'
import { useFuncionalidades } from '../../../shared/useFuncionalidades'
import { usePuede } from '../../auth/session'
import DocumentoModal from './documentos/DocumentoModal'
import { opcionesVisibles, seleccionInicial, urlZip } from '../expedienteImprimible'

const boton = 'whitespace-nowrap rounded-lg border border-teal-200 px-3 py-2 text-xs font-semibold text-teal-800 hover:bg-teal-50 disabled:opacity-60'
const enlace = 'text-xs font-semibold text-teal-700 underline disabled:opacity-60'

// «Descargar ZIP» de un legajo: quien descarga elige los grupos (las secciones 01 a 04 vienen marcadas y se pueden quitar).
// Con legajo, descarga ese legajo (también uno pasivo); sin él, el activo. El servidor arma el ZIP (originales por sección y
// folio, con 00_INDICE.html) y vuelve a aplicar permisos y configuración.
export default function DescargarExpedienteZip({ usuario, legajo = null, etiqueta = 'Descargar ZIP', className = 'mt-4' }) {
  const opciones = opcionesVisibles(useFuncionalidades(), usePuede('datos_sensibles.ver'))
  const [abierto, setAbierto] = useState(false)
  const [seleccion, setSeleccion] = useState([])
  const [estado, setEstado] = useState({ ocupado: false, error: '' })
  const todas = opciones.map(opcion => opcion.clave)
  const todoMarcado = todas.length > 0 && todas.every(clave => seleccion.includes(clave))
  function abrir() { setSeleccion(seleccionInicial(opciones)); setEstado({ ocupado: false, error: '' }); setAbierto(true) }
  function cerrar() { if (!estado.ocupado) setAbierto(false) }
  async function descargar() {
    if (!seleccion.length) return setEstado({ ocupado: false, error: 'Marca al menos un grupo para descargar.' })
    setEstado({ ocupado: true, error: '' })
    try {
      const respuesta = await api.get(urlZip(usuario, seleccion, legajo), { responseType: 'blob', timeout: 120000 })
      const nombre = /filename="?([^";]+)"?/.exec(respuesta.headers['content-disposition'] || '')?.[1] || 'expediente.zip'
      const url = URL.createObjectURL(respuesta.data)
      Object.assign(document.createElement('a'), { href: url, download: nombre }).click()
      URL.revokeObjectURL(url)
      setEstado({ ocupado: false, error: '' })
      setAbierto(false)
    } catch (e) {
      // Con responseType blob, el mensaje del servidor llega dentro del Blob.
      let mensaje = apiErrorMessage(e)
      try { mensaje = JSON.parse(await e.response.data.text()).message || mensaje } catch { /* se queda el mensaje general */ }
      setEstado({ ocupado: false, error: mensaje })
    }
  }
  return <>
    <button type="button" className={`${className} ${boton}`} onClick={abrir}>{etiqueta}</button>
    {abierto && <DocumentoModal titulo={legajo ? 'Descargar este legajo en ZIP' : 'Descargar expediente en ZIP'} busy={estado.ocupado} onCancel={cerrar} compacto>
      <div className="space-y-4 text-sm text-slate-700">
        {opciones.length > 0 ? <fieldset className="space-y-2">
          <legend className="font-semibold">¿Qué incluir en la copia?</legend>
          <div className="flex flex-wrap gap-3 pb-1">
            <button type="button" className={enlace} disabled={estado.ocupado || todoMarcado} onClick={() => { setSeleccion(todas); setEstado(actual => ({ ...actual, error: '' })) }}>Seleccionar todo</button>
            <button type="button" className={enlace} disabled={estado.ocupado || !seleccion.length} onClick={() => setSeleccion([])}>Quitar todo</button>
          </div>
          {opciones.map(opcion => <label key={opcion.clave} className="flex items-start gap-2"><input type="checkbox" className="mt-0.5 accent-teal-700" disabled={estado.ocupado}
            checked={seleccion.includes(opcion.clave)} onChange={e => { setEstado(actual => ({ ...actual, error: '' })); setSeleccion(actual => e.target.checked ? [...actual, opcion.clave] : actual.filter(clave => clave !== opcion.clave)) }} /><span>{opcion.etiqueta}</span></label>)}
        </fieldset> : <p>No hay grupos disponibles para tu perfil en esta institución.</p>}
        <p className="text-xs text-slate-500">El ZIP trae los PDF originales ordenados por sección y folio, y un índice (00_INDICE.html) para imprimir que indica lo incluido y lo que no.</p>
        {estado.error && <p role="alert" className="text-rose-800">{estado.error}</p>}
        <div className="flex flex-wrap gap-2">
          <button type="button" className={boton} disabled={estado.ocupado || !seleccion.length} onClick={descargar}>{estado.ocupado ? 'Preparando ZIP…' : `Descargar${seleccion.length ? ` (${seleccion.length})` : ''}`}</button>
          <button type="button" className={boton} disabled={estado.ocupado} onClick={cerrar}>Cancelar</button>
        </div>
      </div>
    </DocumentoModal>}
  </>
}
