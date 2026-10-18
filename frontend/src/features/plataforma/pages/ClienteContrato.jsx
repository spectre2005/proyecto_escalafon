import { useState } from 'react'
import { editarInstitucion, mensajeError, sesionTerminada } from '../api'
import { Aviso } from '../componentes'
import { CAMPOS_CLIENTE, cambiosCliente, fechaCorta } from '../estado'
import { useSesionPlataforma } from '../sesion'

const entrada = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-800 disabled:bg-slate-50 disabled:text-slate-500'

/** Datos del cliente y del contrato (opcionales, editables en cualquier estado). El backend valida el RUC y el resto. */
export default function ClienteContrato({ institucion, onCambio }) {
  const { terminada } = useSesionPlataforma()
  const [borrador, setBorrador] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [aviso, setAviso] = useState('')
  async function guardar(event) {
    event.preventDefault()
    const datos = cambiosCliente(institucion, borrador)
    if (Object.keys(datos).length === 0) { setBorrador(null); return }
    setBusy(true)
    setError('')
    try {
      await editarInstitucion(institucion.id, datos)
      setBorrador(null)
      setAviso('Datos del cliente guardados.')
      onCambio()
    } catch (failure) {
      if (sesionTerminada(failure)) terminada()
      else setError(mensajeError(failure))
    } finally {
      setBusy(false)
    }
  }
  const editar = () => { setAviso(''); setError(''); setBorrador(Object.fromEntries(CAMPOS_CLIENTE.map(({ campo }) => [campo, institucion[campo] ?? '']))) }
  return <div className="mt-6 rounded-2xl border border-slate-200 bg-white p-6">
    <div className="flex flex-wrap items-center justify-between gap-2">
      <h2 className="font-semibold text-slate-800">Cliente y contrato</h2>
      {!borrador && <button type="button" onClick={editar} className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm">Editar</button>}
    </div>
    <div className="mt-3"><Aviso tipo="exito">{aviso}</Aviso></div>
    {borrador ? <form onSubmit={guardar} aria-busy={busy} className="mt-3 grid gap-4 sm:grid-cols-2">
      <div className="sm:col-span-2"><Aviso>{error}</Aviso></div>
      {CAMPOS_CLIENTE.map(({ campo, etiqueta, tipo, ayuda, max }) => <label key={campo} className="grid gap-2 text-xs font-medium text-slate-600">{etiqueta}
        <input type={tipo || 'text'} value={borrador[campo]} maxLength={max} inputMode={campo === 'ruc' ? 'numeric' : undefined} onChange={event => setBorrador({ ...borrador, [campo]: event.target.value })} disabled={busy} className={entrada} />
        {ayuda && <span className="font-normal text-slate-400">{ayuda}</span>}
      </label>)}
      <div className="flex justify-end gap-3 sm:col-span-2"><button type="button" onClick={() => setBorrador(null)} disabled={busy} className="rounded-xl border border-slate-200 px-4 py-2 text-sm">Cancelar</button><button type="submit" disabled={busy} className="rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white">{busy ? 'Guardando…' : 'Guardar'}</button></div>
    </form> : <dl className="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {CAMPOS_CLIENTE.map(({ campo, etiqueta }) => <div key={campo}><dt className="text-xs text-slate-500">{etiqueta}</dt><dd className="mt-1 break-all text-sm text-slate-800">{(campo === 'contrato_inicio' ? (institucion[campo] && fechaCorta(institucion[campo])) : institucion[campo]) || '—'}</dd></div>)}
    </dl>}
  </div>
}
