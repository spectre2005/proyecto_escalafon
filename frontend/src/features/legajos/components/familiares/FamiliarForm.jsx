import { useState } from 'react'
import { apiErrorMessage } from '../../../../axios'
import { saveFamiliar } from '../../api/familiaresApi'

const control = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm'
const fields = [
  ['apellidos_nombres', 'Apellidos y nombres', 'text', 200, true],
  ['parentesco', 'Parentesco', 'text', 15, true],
  ['tipo_doc_identidad', 'Tipo de documento', 'text', 15],
  ['numero_doc_identidad', 'Número de documento', 'text', 20],
  ['fecha_nacimiento', 'Fecha de nacimiento', 'date'],
]

export default function FamiliarForm({ usuario, record, onSaved, onCancel }) {
  const [draft, setDraft] = useState(() => ({
    ...Object.fromEntries(fields.map(([key]) => [key, record?.values[key] ?? ''])),
    es_derechohabiente: record?.values.es_derechohabiente ?? false,
  }))
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function save(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    try {
      const data = Object.fromEntries(Object.entries(draft).map(([key, value]) => [key, typeof value === 'string' ? value.trim() || null : value]))
      await saveFamiliar(usuario, record?.id, data)
      onSaved()
    } catch (failure) {
      setError(apiErrorMessage(failure))
    } finally {
      setBusy(false)
    }
  }
  return <form onSubmit={save} className="mb-5 rounded-2xl border border-teal-200 bg-white p-5" aria-busy={busy}>
    <h3 className="mb-5 font-semibold text-slate-800">{record ? 'Editar familiar' : 'Agregar familiar'}</h3>
    {error && <p role="alert" className="mb-4 text-sm text-rose-700">{error}</p>}
    <fieldset disabled={busy} className="grid gap-4 sm:grid-cols-2">
      {fields.map(([key, title, type, maxLength, required]) => <label key={key} className="grid gap-2 text-xs text-slate-600">{title}{required ? ' *' : ''}<input className={control} type={type} maxLength={maxLength} required={required} value={draft[key]} onChange={event => setDraft(previous => ({ ...previous, [key]: event.target.value }))} list={key === 'parentesco' ? 'parentescos' : undefined} /></label>)}
      <datalist id="parentescos">{['Cónyuge', 'Conviviente', 'Hijo', 'Hija', 'Padre', 'Madre', 'Hermano', 'Hermana', 'Otro'].map(value => <option key={value} value={value} />)}</datalist>
      <label className="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" checked={draft.es_derechohabiente} onChange={event => setDraft(previous => ({ ...previous, es_derechohabiente: event.target.checked }))} />Es derechohabiente</label>
      {!record && <p className="col-span-full text-xs text-slate-500">El responsable se registra automáticamente con tu sesión.</p>}
      <div className="col-span-full flex justify-end gap-3 pt-3"><button type="button" onClick={onCancel} className="rounded-xl border px-4 py-2 text-sm">Cancelar</button><button className="rounded-xl bg-teal-700 px-4 py-2 text-sm font-semibold text-white">{busy ? 'Guardando…' : 'Guardar familiar'}</button></div>
    </fieldset>
  </form>
}
