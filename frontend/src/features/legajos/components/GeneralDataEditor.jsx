import { useState } from 'react'
import Icon from '../../../components/ui/Icon'
import { advanceOnEnter } from '../../../shared/forms/advanceOnEnter'
import api, { apiErrorMessage } from '../../../axios'
import { useLiveQuery } from '../../../shared/queries/useLiveQuery'
import { estadosCiviles, tiposDocIdentidad } from '../config/seccion01'
import { regimenesPorTipo, regimenInicial } from '../../users/utils/regimenesLaborales'
import { Opciones } from './seccion01/Campo'
import UbigeoFields from '../../../components/ui/UbigeoFields'

const inputClass = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-teal-600 disabled:bg-slate-50 disabled:text-slate-400'
const fields = [
  ['nombres', 'Nombres', 'text', 120, true],
  ['apellido_paterno', 'Apellido paterno', 'text', 80, true],
  ['apellido_materno', 'Apellido materno', 'text', 80],
  ['tipo_doc_identidad', 'Tipo de documento de identidad', 'text', 15, true],
  ['numero_doc_identidad', 'Número de documento de identidad', 'text', 20, true],
  ['fecha_nacimiento', 'Fecha de nacimiento', 'date'],
  ['nacionalidad', 'Nacionalidad', 'text', 60],
  ['estado_civil', 'Estado civil', 'text', 15],
  ['domicilio', 'Domicilio', 'text', 250],
  ['telefono', 'Teléfono', 'tel', 9],
  ['email_institucional', 'Correo institucional', 'email', 255, true],
  ['nombre_puesto', 'Puesto', 'text', 150],
  ['regimen_laboral', 'Régimen laboral', 'text', 20, true],
]

// Listas cerradas que valida el backend.
const listas = { tipo_doc_identidad: tiposDocIdentidad, estado_civil: estadosCiviles }

const flags = [
  ['activo', 'Activo'],
  ['con_discapacidad', 'Con discapacidad'],
  ['obligado_djbr', 'Obligado a presentar declaración jurada de bienes y rentas'],
  ['exige_colegiatura', 'Requiere colegiatura'],
  ['exige_estudios_basicos', 'El puesto exige estudios básicos'],
  ['tiene_periodo_prueba', 'Tiene período de prueba'],
  ['tiene_derechohabientes', 'Tiene derechohabientes'],
]

export default function GeneralDataEditor({ usuario, data, onSaved, onCancel, onUploadDni, onUploadFicha }) {
  const [original] = useState(() => ({ ...data.usuario.values, ...data.edit_values }))
  const [version] = useState(data.version)
  const [draft, setDraft] = useState(() => ({
    ...Object.fromEntries(fields.map(([key]) => [key, original[key] ?? ''])),
    ...Object.fromEntries(flags.map(([key]) => [key, original[key] ?? false])),
    tipo_personal: original.tipo_personal,
    unidad_organica_id: original.unidad_organica_id ?? '',
    regimen_normativo_id: original.regimen_normativo_id ?? '',
    nacionalidad: !original.nacionalidad || original.nacionalidad === 'Peruana' ? 'Peruano' : original.nacionalidad,
    regimen_laboral: (regimenesPorTipo[original.tipo_personal] || []).some(option => option.value === original.regimen_laboral) ? original.regimen_laboral : regimenInicial(original.tipo_personal),
  }))
  const options = useLiveQuery('/expediente-opciones')
  const [location, setLocation] = useState(() => Object.fromEntries(['region', 'provincia', 'distrito'].map(key => [key, data.usuario.values[key] ?? ''])))
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})
  async function save(event) {
    event.preventDefault()
    if (saving) return
    const changes = Object.fromEntries(Object.entries(draft).filter(([key, value]) => key !== 'nacionalidad' && value !== (original[key] ?? '')).map(([key, value]) => [key, typeof value === 'string' ? value.trim() || null : value]))
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
  return <form onSubmit={save} onKeyDown={advanceOnEnter} aria-busy={saving}>
    <div className="mb-6 flex flex-wrap items-start justify-between gap-4"><div><h2 className="text-lg font-semibold text-slate-800">Editar información personal</h2><p className="mt-2 text-xs text-slate-500">Los cambios se mostrarán al guardar.</p></div><div className="flex flex-wrap items-center gap-3">{onUploadFicha && <button type="button" disabled={saving} onClick={onUploadFicha} className="inline-flex items-center gap-2 rounded-xl border border-teal-200 bg-teal-50 px-4 py-2 text-sm font-semibold text-teal-800 hover:bg-teal-100"><Icon name="upload" size={17} />Subir ficha personal</button>}<button type="button" disabled={saving} onClick={onCancel} className="text-sm text-slate-500 hover:text-slate-800">Cancelar</button></div></div>
    {error && <p role="alert" className="mb-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-700">{error}</p>}
    <fieldset disabled={saving} className="grid gap-5 sm:grid-cols-2">
      {fields.map(([key, title, type, maxLength, required]) => {
        const isDni = key === 'numero_doc_identidad' && draft.tipo_doc_identidad === 'dni'
        const digits = isDni ? 8 : key === 'telefono' ? 9 : null
        const props = { name: key, className: inputClass, required, value: draft[key], inputMode: digits ? 'numeric' : undefined, pattern: digits ? `[0-9]{${digits}}` : undefined, title: digits ? `Debe contener ${digits} dígitos.` : undefined, onChange: event => setDraft(previous => ({ ...previous, [key]: digits ? event.target.value.replace(/\D/g, '').slice(0, digits) : event.target.value })), 'aria-invalid': !!fieldErrors[key] }
        const lista = key === 'regimen_laboral' ? Object.fromEntries((regimenesPorTipo[draft.tipo_personal] || []).map(option => [option.value, option.label])) : listas[key]
        return <div key={key} className="flex flex-col gap-2 text-xs font-medium text-slate-600">
          <label htmlFor={`personal-${key}`}>{isDni ? 'DNI' : title}{required ? ' *' : ''}</label>
          <div className="flex items-center gap-2">
            {lista ? <select {...props} id={`personal-${key}`}>{key === 'regimen_laboral' && <option value="" disabled>Selecciona un régimen</option>}<Opciones lista={lista} actual={draft[key]} vacio={required ? undefined : 'No registrado'} /></select> : <input {...props} id={`personal-${key}`} type={type} maxLength={digits || maxLength} readOnly={key === 'nacionalidad'} aria-readonly={key === 'nacionalidad' ? true : undefined} />}
            {key === 'numero_doc_identidad' && onUploadDni && <span className="group relative shrink-0">
              <button type="button" onClick={onUploadDni} aria-label={isDni ? 'Subir copia de DNI' : 'Subir copia de documento de identidad'} aria-describedby="identity-upload-tooltip" className="grid size-11 place-items-center rounded-xl border border-teal-200 bg-teal-50 text-teal-700 hover:bg-teal-100 focus-visible:outline-2 focus-visible:outline-teal-600"><Icon name="upload" size={19} /></button>
              <span id="identity-upload-tooltip" role="tooltip" className="pointer-events-none absolute right-0 bottom-full z-10 mb-2 hidden whitespace-nowrap rounded-lg bg-slate-800 px-3 py-2 text-[11px] text-white group-hover:block group-focus-within:block">{isDni ? 'Subir copia de DNI' : 'Subir archivo de identidad'}</span>
            </span>}
          </div>
          {fieldErrors[key] && <span className="text-rose-700">{fieldErrors[key].join(' ')}</span>}
        </div>
      })}
      <label className="flex flex-col gap-2 text-xs font-medium text-slate-600">Tipo de personal *<select name="tipo_personal" className={inputClass} value={draft.tipo_personal} onChange={event => setDraft(previous => ({ ...previous, tipo_personal: event.target.value, regimen_laboral: regimenInicial(event.target.value) }))}><option value="docente">Docente</option><option value="administrativo">Administrativo</option></select></label>
      {[['unidad_organica_id', 'Unidad orgánica', 'unidades'], ['regimen_normativo_id', 'Régimen normativo', 'regimenes']].map(([key, title, catalog]) => <label key={key} className="flex flex-col gap-2 text-xs font-medium text-slate-600">{title}<select name={key} className={inputClass} value={draft[key]} disabled={!options.data} onChange={event => setDraft(previous => ({ ...previous, [key]: event.target.value }))}><option value="">Sin asignar</option>{!options.data && draft[key] && <option value={draft[key]}>{data.usuario.values[key]}</option>}{options.data?.data[catalog].map(item => <option key={item.id} value={item.id}>{item.nombre}{item.activo ? '' : ' (inactivo)'}</option>)}</select>{fieldErrors[key] && <span className="text-rose-700">{fieldErrors[key].join(' ')}</span>}</label>)}
      {options.error && <p role="alert" className="col-span-full text-xs text-rose-700">No se pudieron cargar las unidades y regímenes. <button type="button" className="underline" onClick={options.retry}>Reintentar</button></p>}
      <div className="col-span-full border-t border-slate-100 pt-5"><h3 className="mb-4 text-sm font-semibold text-slate-700">Estado y condiciones del personal</h3><div className="grid gap-4 sm:grid-cols-2">{flags.map(([key, title]) => <label key={key} className="flex items-start gap-3 text-sm text-slate-700"><input type="checkbox" name={key} className="mt-0.5 size-4 accent-teal-700" checked={draft[key]} onChange={event => setDraft(previous => ({ ...previous, [key]: event.target.checked }))} />{title}{fieldErrors[key] && <span className="text-xs text-rose-700">{fieldErrors[key].join(' ')}</span>}</label>)}</div></div>
      <div className="col-span-full border-t border-slate-100 pt-5"><h3 className="mb-4 text-sm font-semibold text-slate-700">Ubicación</h3><UbigeoFields value={location} onChange={setLocation} disabled={saving} /><p className="mt-3 text-xs text-slate-500">Selecciona región, provincia y distrito del catálogo.</p></div>
      <div className="col-span-full flex justify-end gap-3 border-t border-slate-100 pt-5"><button type="button" onClick={onCancel} className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm">Cancelar</button><button type="submit" className="rounded-xl bg-teal-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50">{saving ? 'Guardando…' : 'Guardar cambios'}</button></div>
    </fieldset>
  </form>
}
