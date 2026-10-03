import { useState } from 'react'
import { useOutletContext } from 'react-router-dom'
import Icon from '../../components/ui/Icon'

export default function SettingsPage() {
  const { institution, updateInstitution } = useOutletContext()
  const [draft, setDraft] = useState(institution)
  const [message, setMessage] = useState('')
  function submit(event) {
    event.preventDefault()
    const value = Object.fromEntries(Object.entries(draft).map(([key, text]) => [key, text.trim()]))
    if (Object.values(value).some(text => !text)) return setMessage('Completa todos los campos.')
    try { updateInstitution(value); setMessage('Identidad actualizada en este navegador.') }
    catch { setMessage('El navegador no permitió guardar la configuración.') }
  }
  return <div className="max-w-3xl"><p className="text-[10px] font-semibold uppercase tracking-[.2em] text-teal-700">Administración</p><h1 className="mt-2 text-3xl font-bold tracking-tight text-slate-800">La identidad de tu institución</h1><p className="mt-3 text-sm leading-relaxed text-slate-500">Adapta el espacio de trabajo a tu organización.</p><form onSubmit={submit} className="mt-8 rounded-2xl border border-slate-200 bg-white p-6 sm:p-8"><div className="mb-7 flex items-center gap-4"><span className="grid size-14 place-items-center rounded-2xl bg-teal-50 text-teal-700"><Icon name="building" size={27} /></span><div><h2 className="font-semibold text-slate-800">Información institucional</h2><p className="mt-1 text-xs text-slate-500">Esta configuración se guarda solo en este navegador.</p></div></div><div className="space-y-5">{[['name', 'Nombre de la institución', 80], ['initials', 'Siglas', 4], ['area', 'Área responsable', 80]].map(([key, label, max]) => <label key={key} className="block text-sm font-medium text-slate-600">{label}<input required maxLength={max} value={draft[key]} onChange={event => setDraft({ ...draft, [key]: event.target.value })} className="mt-2 block w-full rounded-xl border border-slate-200 bg-slate-50/50 px-4 py-3 font-normal text-slate-800" /></label>)}</div><div className="mt-7 border-t border-slate-100 pt-6"><button type="submit" className="rounded-xl bg-teal-700 px-5 py-3 text-sm font-semibold text-white hover:bg-teal-800">Guardar cambios</button>{message && <p role="status" className="mt-4 text-sm text-teal-800">{message}</p>}</div></form></div>
}
