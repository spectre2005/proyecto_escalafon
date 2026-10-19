import { useState } from 'react'
import { editarInstitucion, mensajeError, sesionTerminada } from '../api'
import { Aviso } from '../componentes'
import { CAMPOS_CLIENTE, cambiosCliente, fechaCorta } from '../estado'
import { ENTRADA, boton } from '../estilos'
import { useSesionPlataforma } from '../sesion'
import { Campo, Seccion } from '../ui'

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
  return <Seccion titulo="Cliente y contrato" acciones={!borrador && <button type="button" onClick={editar} className={boton('secundario')}>Editar</button>}>
    <div className="mt-3"><Aviso tipo="exito">{aviso}</Aviso></div>
    {borrador ? <form onSubmit={guardar} aria-busy={busy} className="mt-3 grid gap-4 sm:grid-cols-2">
      <div className="sm:col-span-2"><Aviso>{error}</Aviso></div>
      {CAMPOS_CLIENTE.map(({ campo, etiqueta, tipo, ayuda, max }) => <Campo key={campo} etiqueta={etiqueta} ayuda={ayuda}>
        <input type={tipo || 'text'} value={borrador[campo]} maxLength={max} inputMode={campo === 'ruc' ? 'numeric' : undefined} onChange={event => setBorrador({ ...borrador, [campo]: event.target.value })} disabled={busy} className={ENTRADA} />
      </Campo>)}
      <div className="flex justify-end gap-3 sm:col-span-2"><button type="button" onClick={() => setBorrador(null)} disabled={busy} className={boton('secundario')}>Cancelar</button><button type="submit" disabled={busy} className={boton('principal')}>{busy ? 'Guardando…' : 'Guardar'}</button></div>
    </form> : <dl className="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {CAMPOS_CLIENTE.map(({ campo, etiqueta }) => <div key={campo}><dt className="text-xs text-slate-500">{etiqueta}</dt><dd className="mt-1 break-all text-sm text-slate-800">{(campo === 'contrato_inicio' ? (institucion[campo] && fechaCorta(institucion[campo])) : institucion[campo]) || '—'}</dd></div>)}
    </dl>}
  </Seccion>
}
