import { useState } from 'react'
import { asignarAdministradorExistente, buscarPersonaAdministradora, mensajeError } from '../api'

export default function AsignarAdministradorExistente({ institucion, onAssigned }) {
  const [email, setEmail] = useState('')
  const [persona, setPersona] = useState(null)
  const [motivo, setMotivo] = useState('')
  const [confirmado, setConfirmado] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  if (institucion.estado === 'archivada') return null
  async function buscar(event) {
    event.preventDefault()
    setBusy(true)
    setError('')
    setPersona(null)
    setConfirmado(false)
    try { setPersona(await buscarPersonaAdministradora(institucion.id, email.trim())) }
    catch (failure) { setError(mensajeError(failure)) }
    finally { setBusy(false) }
  }
  async function asignar(event) {
    event.preventDefault()
    if (busy || !confirmado || !persona) return
    setBusy(true)
    setError('')
    try {
      await asignarAdministradorExistente(institucion.id, { usuario_id: persona.id, email_confirmado: persona.email_institucional, identidad_confirmada: confirmado, motivo })
      setPersona(null)
      setEmail('')
      setMotivo('')
      setConfirmado(false)
      onAssigned()
    } catch (failure) { setError(mensajeError(failure)) }
    finally { setBusy(false) }
  }
  const entrada = 'rounded-lg border border-slate-300 px-3 py-2 text-sm'
  return <details className="mt-5 rounded-xl border border-slate-200 p-4"><summary className="cursor-pointer text-sm font-semibold text-teal-800">Asignar administración a una persona existente</summary><p className="my-3 text-sm text-slate-600">Busca por correo exacto y verifica su identidad antes de conceder el rol ADMIN. Conserva sus datos, legajos y contraseña. Si la cuenta está desactivada o necesita una clave nueva, gestiona esas acciones por separado.</p><form onSubmit={buscar} className="flex flex-wrap items-end gap-3" aria-busy={busy}><label className="grid gap-1 text-sm">Correo institucional exacto<input type="email" required maxLength={255} value={email} onChange={event => { setEmail(event.target.value); setPersona(null) }} className={entrada} /></label><button disabled={busy} className={entrada}>Buscar persona</button></form>{error && <p role="alert" className="mt-3 text-sm text-rose-800">{error}</p>}{persona && <form onSubmit={asignar} className="mt-4 space-y-3" aria-busy={busy}><p className="text-sm font-semibold">{[persona.nombres, persona.apellido_paterno, persona.apellido_materno].filter(Boolean).join(' ')} · {persona.email_institucional}</p>{persona.ya_administrador ? <p className="text-sm">Ya es administrador de esta institución.</p> : <><label className="grid gap-1 text-sm">Motivo de la designación<textarea required minLength={5} maxLength={500} value={motivo} onChange={event => setMotivo(event.target.value)} className={entrada} /></label><label className="flex items-start gap-2 text-sm"><input type="checkbox" required checked={confirmado} onChange={event => setConfirmado(event.target.checked)} />He verificado la identidad y la autorización para conceder ADMIN a esta persona.</label><button disabled={busy || !confirmado} className="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">{busy ? 'Asignando…' : 'Confirmar asignación de ADMIN'}</button></>}</form>}</details>
}
