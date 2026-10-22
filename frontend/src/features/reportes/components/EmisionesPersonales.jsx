import { useState } from 'react'
import api, { apiErrorMessage } from '../../../axios'
import { usePuede } from '../../auth/session'
import { institucionParaPdf } from '../institucionPdf'

// Mismos permisos que la ruta (sistema.administrar y legajo.ver); el backend valida siempre.
const DOCUMENTOS = [
  ['informe-escalafonario', 'Informe escalafonario', 'createInformeEscalafonarioPdf', 'informe_escalafonario.pdf'],
  ['constancia-trabajo', 'Constancia de trabajo', 'createConstanciaTrabajoPdf', 'constancia_de_trabajo.pdf'],
  ['constancia-tiempo-servicios', 'Constancia de tiempo de servicios', 'createConstanciaTiempoServiciosPdf', 'constancia_tiempo_de_servicios.pdf'],
]

function noIncluido(item) {
  return `${item.puesto || item.numero_resolucion || 'Registro sin datos'}${item.inicio ? ` (desde ${item.inicio})` : ''}: ${item.motivo}`
}

export default function EmisionesPersonales({ usuario }) {
  const administra = usePuede('sistema.administrar')
  const veLegajo = usePuede('legajo.ver')
  const [busy, setBusy] = useState('')
  const [aviso, setAviso] = useState(null)
  if (!administra || !veLegajo) return null

  async function emitir([documento, nombre, crear, archivo]) {
    if (busy) return
    setBusy(documento)
    setAviso(null)
    try {
      const [{ data }, institution, modulo] = await Promise.all([api.get(`/reportes/personales/${usuario}/${documento}`), institucionParaPdf(), import('../pdf/emisionesPersonalesPdf')])
      const datos = data.data
      const omitidos = (datos.no_incluidos || []).map(noIncluido)
      if (datos.emitible === false) {
        setAviso({ error: true, texto: `No se puede emitir la ${nombre.toLowerCase()}: ${datos.motivo}`, detalle: omitidos })
        return
      }
      modulo[crear](datos, institution).save(archivo)
      if (omitidos.length) setAviso({ error: false, texto: `${nombre} descargada. No se incluyeron estos registros por falta de sustento:`, detalle: omitidos })
    } catch (failure) {
      setAviso({ error: true, texto: failure.response || failure.isAxiosError ? apiErrorMessage(failure) : 'No se pudo generar el PDF. Inténtalo nuevamente.', detalle: [] })
    } finally {
      setBusy('')
    }
  }

  return <section aria-label="Documentos para emitir" className="mt-6 border-t border-slate-100 pt-5">
    <h2 className="text-xs font-semibold text-slate-600">Documentos para emitir</h2>
    <p className="mt-1 text-[11px] text-slate-500">Se generan con los datos registrados en el legajo. Las constancias solo incluyen datos sustentados con documento aceptado.</p>
    <div className="mt-3 flex flex-wrap gap-2">
      {DOCUMENTOS.map(item => <button key={item[0]} type="button" disabled={!!busy} onClick={() => emitir(item)}
        className="whitespace-nowrap rounded-lg border border-teal-200 px-3 py-2 text-xs font-semibold text-teal-800 hover:bg-teal-50 disabled:opacity-60">
        {busy === item[0] ? 'Generando PDF…' : item[1]}
      </button>)}
    </div>
    {aviso && <div role={aviso.error ? 'alert' : 'status'} className={`mt-3 text-xs ${aviso.error ? 'text-rose-700' : 'text-slate-600'}`}>
      <p>{aviso.texto}</p>
      {aviso.detalle.length > 0 && <ul className="mt-1 list-disc pl-5">{aviso.detalle.map(linea => <li key={linea}>{linea}</li>)}</ul>}
    </div>}
  </section>
}
