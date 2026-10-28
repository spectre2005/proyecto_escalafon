import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import api, { apiErrorMessage } from '../../../axios'
import Icon from '../../../components/ui/Icon'
import { useManualQuery } from '../../../shared/queries/useManualQuery'
import { separarPropuestasIngreso } from '../utils/escanerIngreso'

export default function EscanerIngreso({ archivo, onAplicar }) {
  const servicio = useManualQuery('/documento-ingreso/escaner')
  const peticion = useRef(null)
  const boton = useRef(null)
  const [trabajo, setTrabajo] = useState(null)
  useEffect(() => () => peticion.current?.abort(), [archivo])
  const actual = trabajo?.archivo === archivo ? trabajo : null
  const busy = actual?.busy || false
  const disponible = servicio.data?.disponible
  const ayuda = !archivo ? 'Selecciona primero el PDF' : busy ? 'Escaneando documento…' : disponible ? 'Escanear y completar los tres campos' : 'Reintentar conexión con el escáner'
  async function escanear() {
    if (!archivo || busy) return
    const destino = boton.current?.closest('dialog') || document.body
    if (!disponible) {
      servicio.retry()
      setTrabajo({ archivo, destino, error: servicio.data?.mensaje || 'Comprobando la conexión. Intenta de nuevo en unos segundos.' })
      return
    }
    const controller = new AbortController()
    peticion.current?.abort(); peticion.current = controller
    setTrabajo({ archivo, destino, busy: true })
    try {
      const form = new FormData(); form.append('pdf', archivo)
      const { data } = await api.post('/documento-ingreso/escanear', form, { signal: controller.signal, timeout: 280000, sinAvisoCambios: true })
      if (controller.signal.aborted) return
      const propuestas = separarPropuestasIngreso(data.campos).vinculo
      onAplicar(propuestas)
      const cantidad = Object.keys(propuestas).length
      setTrabajo({ archivo, destino, observaciones: data.observaciones || [], aviso: cantidad ? `Se completaron ${cantidad} de 3 campos. Revisa los datos antes de continuar. Los no detectados conservan su valor.` : 'Lectura terminada. No se encontraron datos completos y verificables para los tres campos.' })
    } catch (e) {
      if (!controller.signal.aborted) setTrabajo({ archivo, destino, error: e.response?.data?.errors?.pdf?.[0] || e.response?.data?.message || apiErrorMessage(e) })
    }
  }
  const mensaje = actual?.error || actual?.aviso
  return <>
    <button ref={boton} type="button" onClick={escanear} disabled={!archivo || busy || servicio.loading}
      aria-label={busy ? 'Escaneando documento' : 'Escanear documento'} aria-busy={busy} title={ayuda}
      className="relative inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-teal-300 bg-white text-teal-800 transition-colors hover:bg-teal-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-700 disabled:opacity-60">
      {busy && <span aria-hidden="true" className="absolute inset-0 rounded-full border-2 border-transparent border-t-teal-700 motion-safe:animate-spin" />}
      <Icon name="robot" size={18} />
      <span role="status" className="sr-only">{busy ? 'Leyendo PDF. El análisis local puede tardar hasta cinco minutos.' : ''}</span>
    </button>
    {mensaje && createPortal(<div role={actual.error ? 'alert' : 'status'} className="fixed bottom-6 right-6 z-[100] flex max-h-[60dvh] max-w-sm overflow-y-auto items-start gap-3 rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-800 shadow-xl">
      <div><p>{mensaje}</p>{actual.observaciones?.length > 0 && <ul className="mt-2 list-disc space-y-2 pl-4 text-xs text-slate-600">{actual.observaciones.map(item => <li key={item.campo}>{item.mensaje}</li>)}</ul>}</div>
      <button type="button" aria-label="Cerrar aviso del escáner" onClick={() => setTrabajo(null)} className="shrink-0 rounded p-1 text-slate-500 hover:bg-slate-100"><Icon name="close" size={16} /></button>
    </div>, actual.destino)}
  </>
}
