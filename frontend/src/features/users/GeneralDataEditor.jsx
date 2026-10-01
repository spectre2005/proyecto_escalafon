import { useEffect, useState } from 'react'
import api, { apiErrorMessage } from '../../axios'
import { useLiveQuery } from './useLiveQuery'

const inputClass = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-teal-600 disabled:bg-slate-50 disabled:text-slate-400'
const fields = [
  ['tipo_doc_identidad', 'Tipo de documento de identidad', 'text', 15, true],
  ['numero_doc_identidad', 'Número de documento de identidad', 'text', 20, true],
  ['nombres', 'Nombres', 'text', 120, true],
  ['apellido_paterno', 'Apellido paterno', 'text', 80, true],
  ['apellido_materno', 'Apellido materno', 'text', 80],
  ['fecha_nacimiento', 'Fecha de nacimiento', 'date'],
  ['nacionalidad', 'Nacionalidad', 'text', 60],
  ['estado_civil', 'Estado civil', 'text', 15],
  ['domicilio', 'Domicilio', 'text', 250],
  ['telefono', 'Teléfono', 'tel', 20],
  ['email_institucional', 'Correo institucional', 'email', 255, true],
  ['nombre_puesto', 'Puesto', 'text', 150],
  ['regimen_laboral', 'Régimen laboral', 'text', 20, true],
]

const flags = [
  ['activo', 'Activo'],
  ['con_discapacidad', 'Con discapacidad'],
  ['obligado_djbr', 'Obligado a presentar declaración jurada de bienes y rentas'],
  ['exige_colegiatura', 'Requiere colegiatura'],
  ['tiene_periodo_prueba', 'Tiene período de prueba'],
  ['tiene_derechohabientes', 'Tiene derechohabientes'],
]

function LocationInput({ field, title, value, onChange, region, provincia, disabled }) {
  const query = new URLSearchParams()
  if (field !== 'region' && region) query.set('region', region)
  if (field === 'distrito' && provincia) query.set('provincia', provincia)
  const url = `/ubicaciones?${query}`
  const [debouncedUrl, setDebouncedUrl] = useState(url)
  useEffect(() => {
    const timer = setTimeout(() => setDebouncedUrl(url), 250)
    return () => clearTimeout(timer)
  }, [url])
  const request = useLiveQuery(debouncedUrl)
  return <label className="flex flex-col gap-2 text-xs font-medium text-slate-600">{title}<input className={inputClass} list={`ubicacion-${field}`} value={value} onChange={event => onChange(event.target.value)} maxLength={100} disabled={disabled} placeholder={`Selecciona o escribe ${title.toLowerCase()}`} autoComplete="off" /><datalist id={`ubicacion-${field}`}>{debouncedUrl === url && request.data?.data.map(option => <option key={option} value={option} />)}</datalist>{request.error && <span className="text-amber-700">No se cargaron las sugerencias. Puedes escribir la ubicación.</span>}</label>
}

export default function GeneralDataEditor({ usuario, data, onSaved, onCancel }) {
  const [original] = useState(() => ({ ...data.usuario.values, ...data.edit_values }))
  const [version] = useState(data.version)
  const [draft, setDraft] = useState(() => ({
    ...Object.fromEntries(fields.map(([key]) => [key, original[key] ?? ''])),
    ...Object.fromEntries(flags.map(([key]) => [key, original[key] ?? false])),
    tipo_personal: original.tipo_personal,
    unidad_organica_id: original.unidad_organica_id ?? '',
    regimen_normativo_id: original.regimen_normativo_id ?? '',
  }))
  const options = useLiveQuery('/expediente-opciones')
  const [location, setLocation] = useState(() => Object.fromEntries(['region', 'provincia', 'distrito'].map(key => [key, data.usuario.values[key] ?? ''])))
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  function changeLocation(key, value) {
    setLocation(previous => ({ ...previous, [key]: value, ...(key === 'region' ? { provincia: '', distrito: '' } : key === 'provincia' ? { distrito: '' } : {}) }))
  }
  async function save(event) {
    event.preventDefault()
    if (saving) return
    const changes = Object.fromEntries(Object.entries(draft).filter(([key, value]) => value !== (original[key] ?? '')).map(([key, value]) => [key, typeof value === 'string' ? value.trim() || null : value]))
    if (Object.entries(location).some(([key, value]) => value !== (original[key] ?? ''))) {
      const values = Object.values(location).map(value => value.trim())
      if (values.some(Boolean) && !values.every(Boolean)) {
        setError('Completa región, provincia y distrito, o deja los tres vacíos.')
        return
      }
      changes.ubicacion = values.every(Boolean) ? Object.fromEntries(Object.entries(location).map(([key, value]) => [key, value.trim()])) : null
    }
    if (!Object.keys(changes).length) { onCancel(); return }
    setSaving(true)
    setError('')
    setFieldErrors({})
    try {
      const response = await api.patch(`/expedientes/${usuario}`, changes, { headers: { 'If-Match': version } })
      onSaved(response.data)
    } catch (failure) {
      setFieldErrors(failure.response?.data?.errors || {})
      setError(failure.response?.status === 409 ? failure.response.data.message : apiErrorMessage(failure))
    } finally {
      setSaving(false)
    }
  }
  return <form onSubmit={save} aria-busy={saving}>
    <div className="mb-6 flex items-start justify-between gap-4"><div><h2 className="text-lg font-semibold text-slate-800">Editar información personal</h2><p className="mt-2 text-xs text-slate-500">Los cambios se mostrarán al guardar.</p></div><button type="button" disabled={saving} onClick={onCancel} className="text-sm text-slate-500 hover:text-slate-800">Cancelar</button></div>
    {error && <p role="alert" className="mb-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-700">{error}</p>}
    <fieldset disabled={saving} className="grid gap-5 sm:grid-cols-2">
      {fields.map(([key, title, type, maxLength, required]) => <label key={key} className="flex flex-col gap-2 text-xs font-medium text-slate-600">{title}{required ? ' *' : ''}<input name={key} className={inputClass} type={type} maxLength={maxLength} required={required} value={draft[key]} onChange={event => setDraft(previous => ({ ...previous, [key]: event.target.value }))} aria-invalid={!!fieldErrors[key]} />{fieldErrors[key] && <span className="text-rose-700">{fieldErrors[key].join(' ')}</span>}</label>)}
      <label className="flex flex-col gap-2 text-xs font-medium text-slate-600">Tipo de personal *<select name="tipo_personal" className={inputClass} value={draft.tipo_personal} onChange={event => setDraft(previous => ({ ...previous, tipo_personal: event.target.value }))}><option value="docente">Docente</option><option value="administrativo">Administrativo</option></select></label>
      {[['unidad_organica_id', 'Unidad orgánica', 'unidades'], ['regimen_normativo_id', 'Régimen normativo', 'regimenes']].map(([key, title, catalog]) => <label key={key} className="flex flex-col gap-2 text-xs font-medium text-slate-600">{title}<select name={key} className={inputClass} value={draft[key]} disabled={!options.data} onChange={event => setDraft(previous => ({ ...previous, [key]: event.target.value }))}><option value="">Sin asignar</option>{!options.data && draft[key] && <option value={draft[key]}>{data.usuario.values[key]}</option>}{options.data?.data[catalog].map(item => <option key={item.id} value={item.id}>{item.nombre}{item.activo ? '' : ' (inactivo)'}</option>)}</select>{fieldErrors[key] && <span className="text-rose-700">{fieldErrors[key].join(' ')}</span>}</label>)}
      {options.error && <p role="alert" className="col-span-full text-xs text-rose-700">No se pudieron cargar las unidades y regímenes. <button type="button" className="underline" onClick={options.retry}>Reintentar</button></p>}
      <div className="col-span-full border-t border-slate-100 pt-5"><h3 className="mb-4 text-sm font-semibold text-slate-700">Estado y condiciones del personal</h3><div className="grid gap-4 sm:grid-cols-2">{flags.map(([key, title]) => <label key={key} className="flex items-start gap-3 text-sm text-slate-700"><input type="checkbox" name={key} className="mt-0.5 size-4 accent-teal-700" checked={draft[key]} onChange={event => setDraft(previous => ({ ...previous, [key]: event.target.checked }))} />{title}{fieldErrors[key] && <span className="text-xs text-rose-700">{fieldErrors[key].join(' ')}</span>}</label>)}</div></div>
      <div className="col-span-full border-t border-slate-100 pt-5"><h3 className="mb-4 text-sm font-semibold text-slate-700">Ubicación</h3><div className="grid gap-4 sm:grid-cols-3"><LocationInput field="region" title="Región" value={location.region} onChange={value => changeLocation('region', value)} /><LocationInput field="provincia" title="Provincia" region={location.region} value={location.provincia} disabled={!location.region} onChange={value => changeLocation('provincia', value)} /><LocationInput field="distrito" title="Distrito" region={location.region} provincia={location.provincia} value={location.distrito} disabled={!location.provincia} onChange={value => changeLocation('distrito', value)} /></div><p className="mt-3 text-xs text-slate-500">Selecciona una ubicación registrada o completa una nueva.</p></div>
      <div className="col-span-full flex justify-end gap-3 border-t border-slate-100 pt-5"><button type="button" onClick={onCancel} className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm">Cancelar</button><button type="submit" className="rounded-xl bg-teal-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50">{saving ? 'Guardando…' : 'Guardar cambios'}</button></div>
    </fieldset>
  </form>
}
