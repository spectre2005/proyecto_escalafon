import { useEffect, useRef, useState } from 'react'
import { anularPeriodo, editarInstitucion, mensajeError, registrarPeriodo, sesionTerminada, verPeriodos } from '../api'
import { Aviso, EstadoServicio } from '../componentes'
import { fechaCorta, proponerPeriodo, textoServicio } from '../estado'
import { useSesionPlataforma } from '../sesion'

const entrada = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-800 disabled:bg-slate-50 disabled:text-slate-500'

function Dialogo({ titulo, children, onCerrar, onEnviar, busy, error, textoBoton, peligro = false }) {
  const ref = useRef(null)
  useEffect(() => { ref.current?.showModal() }, [])
  return <dialog ref={ref} onClose={onCerrar} aria-label={titulo} className="m-auto w-full max-w-md rounded-2xl p-0 backdrop:bg-slate-900/40">
    <form onSubmit={onEnviar} aria-busy={busy} className="space-y-4 p-6">
      <h2 className="text-lg font-bold text-slate-900">{titulo}</h2>
      <Aviso>{error}</Aviso>
      {children}
      <div className="flex justify-end gap-3"><button type="button" onClick={() => ref.current.close()} disabled={busy} className="rounded-xl border border-slate-200 px-4 py-2 text-sm">Cancelar</button><button type="submit" disabled={busy} className={`rounded-xl px-4 py-2 text-sm font-semibold text-white ${peligro ? 'bg-rose-700' : 'bg-teal-700'}`}>{busy ? 'Guardando…' : textoBoton}</button></div>
    </form>
  </dialog>
}

/** Formulario de un dialogo: maneja busy/error y llama a onGuardar(datos); si falla, muestra el mensaje. */
function useEnvio(onGuardar) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function enviar(event, datos) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    try {
      await onGuardar(datos)
    } catch (failure) {
      setError(mensajeError(failure))
      setBusy(false)
    }
  }
  return { busy, error, enviar }
}

function RegistrarPago({ propuesta, onGuardar, onCerrar }) {
  const [datos, setDatos] = useState({ ...propuesta, monto: '', comprobante: '', observacion: '' })
  const { busy, error, enviar } = useEnvio(onGuardar)
  const set = campo => event => setDatos({ ...datos, [campo]: event.target.value })
  return <Dialogo titulo="Registrar pago" textoBoton="Registrar" busy={busy} error={error} onCerrar={onCerrar}
    onEnviar={event => enviar(event, { ...datos, monto: datos.monto === '' ? null : Number(datos.monto) })}>
    <p className="text-sm text-slate-600">El periodo cubre ambos días completos. Se propone desde el día siguiente al último pago, por un año.</p>
    <div className="grid gap-3 sm:grid-cols-2">
      <label className="grid gap-2 text-xs font-medium text-slate-600">Desde *<input type="date" required value={datos.desde} onChange={set('desde')} disabled={busy} className={entrada} /></label>
      <label className="grid gap-2 text-xs font-medium text-slate-600">Hasta *<input type="date" required min={datos.desde} value={datos.hasta} onChange={set('hasta')} disabled={busy} className={entrada} /></label>
    </div>
    <label className="grid gap-2 text-xs font-medium text-slate-600">Monto (S/)<input type="number" min="0" step="0.01" value={datos.monto} onChange={set('monto')} disabled={busy} className={entrada} /></label>
    <label className="grid gap-2 text-xs font-medium text-slate-600">N.º de comprobante<input maxLength={100} value={datos.comprobante} onChange={set('comprobante')} disabled={busy} className={entrada} /></label>
    <label className="grid gap-2 text-xs font-medium text-slate-600">Observación<textarea maxLength={500} rows={2} value={datos.observacion} onChange={set('observacion')} disabled={busy} className={entrada} /></label>
  </Dialogo>
}

function AnularPago({ periodo, onGuardar, onCerrar }) {
  const [motivo, setMotivo] = useState('')
  const { busy, error, enviar } = useEnvio(onGuardar)
  return <Dialogo titulo="Anular periodo" textoBoton="Anular" peligro busy={busy} error={error} onCerrar={onCerrar} onEnviar={event => enviar(event, motivo.trim())}>
    <p className="text-sm text-slate-600">Del {fechaCorta(periodo.desde)} al {fechaCorta(periodo.hasta)}. No se borra: queda en el historial como anulado. Si fue un error de datos, registra después el periodo correcto.</p>
    <label className="grid gap-2 text-xs font-medium text-slate-600">Motivo *<textarea required minLength={5} maxLength={500} rows={3} value={motivo} onChange={event => setMotivo(event.target.value)} disabled={busy} className={entrada} /></label>
  </Dialogo>
}

function DiasGracia({ actual, onGuardar, onCerrar }) {
  const [dias, setDias] = useState(String(actual))
  const { busy, error, enviar } = useEnvio(onGuardar)
  return <Dialogo titulo="Días de gracia" textoBoton="Guardar" busy={busy} error={error} onCerrar={onCerrar} onEnviar={event => enviar(event, Number(dias))}>
    <p className="text-sm text-slate-600">Días que la institución sigue funcionando después de vencer, con un aviso a sus administradores. Luego se bloquea el acceso.</p>
    <label className="grid gap-2 text-xs font-medium text-slate-600">Días (0 a 90)<input type="number" required min="0" max="90" step="1" value={dias} onChange={event => setDias(event.target.value)} disabled={busy} className={entrada} /></label>
  </Dialogo>
}

/**
 * Tiempo de uso pagado de una institución: estado del servicio, días de gracia e historial de periodos (no se editan;
 * se anulan con motivo). Al registrar un pago, una institución vencida vuelve a operar sola. onCambio() recarga la
 * institución (estado y auditoría).
 */
export default function ServicioInstitucion({ institucion, onCambio }) {
  const { terminada } = useSesionPlataforma()
  const [estado, setEstado] = useState({ loading: true })
  const [intento, setIntento] = useState(0)
  const [dialogo, setDialogo] = useState(null)
  const [aviso, setAviso] = useState('')
  useEffect(() => {
    const controller = new AbortController()
    verPeriodos(institucion.id, controller.signal).then(data => setEstado({ data })).catch(failure => {
      if (controller.signal.aborted) return
      if (sesionTerminada(failure)) terminada()
      else setEstado({ error: mensajeError(failure) })
    })
    return () => controller.abort()
  }, [institucion.id, intento, terminada])
  async function ejecutar(operacion, mensaje) {
    try {
      await operacion()
    } catch (failure) {
      if (sesionTerminada(failure)) terminada()
      throw failure
    }
    setAviso(mensaje)
    setDialogo(null)
    setIntento(valor => valor + 1)
    onCambio()
  }
  const data = estado.data
  return <div className="mt-6 rounded-2xl border border-slate-200 bg-white p-6">
    <div className="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 className="font-semibold text-slate-800">Tiempo de uso</h2>
        {data && <div className="mt-2 flex flex-wrap items-center gap-2"><EstadoServicio servicio={data.servicio} /><span className="text-sm text-slate-600">{textoServicio(data.servicio)}</span></div>}
        {data && <p className="mt-1 text-xs text-slate-500">Días de gracia: {data.dias_gracia}. Sus administradores ven un aviso antes de vencer.</p>}
      </div>
      {data && <div className="flex flex-wrap gap-2">
        <button type="button" onClick={() => { setAviso(''); setDialogo({ tipo: 'gracia' }) }} className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm">Días de gracia</button>
        <button type="button" onClick={() => { setAviso(''); setDialogo({ tipo: 'pago' }) }} className="rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white">Registrar pago</button>
      </div>}
    </div>
    <div className="mt-4 space-y-3"><Aviso tipo="exito">{aviso}</Aviso>{estado.error && <Aviso>{estado.error}</Aviso>}</div>
    {estado.loading ? <p role="status" className="mt-4 text-sm text-slate-500">Cargando…</p> : data && <div className="mt-4 overflow-x-auto">
      {data.periodos.length === 0 ? <p className="text-sm text-slate-500">Sin pagos registrados.</p> : <table className="w-full min-w-[36rem] text-left text-sm">
        <thead><tr className="text-xs text-slate-500"><th className="py-2 font-medium">Periodo</th><th className="py-2 font-medium">Monto</th><th className="py-2 font-medium">Comprobante</th><th className="py-2 font-medium">Registró</th><th className="py-2" /></tr></thead>
        <tbody className="divide-y divide-slate-100">
          {data.periodos.map(periodo => <tr key={periodo.id} className={periodo.anulado ? 'text-slate-400' : 'text-slate-700'}>
            <td className="py-2.5">{fechaCorta(periodo.desde)} – {fechaCorta(periodo.hasta)}{periodo.anulado && <span className="block text-xs">Anulado: {periodo.motivo_anulacion}</span>}{periodo.observacion && <span className="block text-xs text-slate-500">{periodo.observacion}</span>}</td>
            <td className="py-2.5">{periodo.monto === null ? '—' : `S/ ${Number(periodo.monto).toFixed(2)}`}</td>
            <td className="py-2.5">{periodo.comprobante || '—'}</td>
            <td className="py-2.5 text-xs">{periodo.registrado_por || 'Sistema'}</td>
            <td className="py-2.5 text-right">{!periodo.anulado && <button type="button" onClick={() => { setAviso(''); setDialogo({ tipo: 'anular', periodo }) }} className="rounded-lg border border-rose-200 px-2.5 py-1 text-xs text-rose-700">Anular</button>}</td>
          </tr>)}
        </tbody>
      </table>}
    </div>}
    {dialogo?.tipo === 'pago' && <RegistrarPago propuesta={proponerPeriodo(data.periodos, data.hoy)} onCerrar={() => setDialogo(null)} onGuardar={datos => ejecutar(() => registrarPeriodo(institucion.id, datos), 'Pago registrado.')} />}
    {dialogo?.tipo === 'anular' && <AnularPago periodo={dialogo.periodo} onCerrar={() => setDialogo(null)} onGuardar={motivo => ejecutar(() => anularPeriodo(institucion.id, dialogo.periodo.id, motivo), 'Periodo anulado.')} />}
    {dialogo?.tipo === 'gracia' && <DiasGracia actual={data.dias_gracia} onCerrar={() => setDialogo(null)} onGuardar={dias => ejecutar(() => editarInstitucion(institucion.id, { dias_gracia: dias }), 'Días de gracia guardados.')} />}
  </div>
}
