import { useState } from 'react'
import DocumentoModal from './documentos/DocumentoModal'
import Icon from '../../../components/ui/Icon'
import { advanceOnEnter } from '../../../shared/forms/advanceOnEnter'
import api, { apiErrorMessage } from '../../../axios'
import { useLiveQuery } from '../../../shared/queries/useLiveQuery'
import { estadosCiviles } from '../config/seccion01'
import { leyesDelTipo } from '../../users/utils/leyes'
import { Opciones } from './seccion01/Campo'
import UbigeoFields from '../../../components/ui/UbigeoFields'
import TresEstados from '../../../components/ui/TresEstados'
import { valorBandera } from '../../../shared/banderas'
import { usePuede } from '../../auth/session'
import { cambiosInformacionPersonal, partesUbicacion } from '../config/identidadEdicion'

const inputClass = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm outline-none focus:ring-2 focus:ring-teal-600 [&[readonly]]:border-slate-100 [&[readonly]]:bg-slate-50 [&[readonly]]:text-slate-600 disabled:bg-slate-50 disabled:text-slate-500'
const fields = [
  ['nombres', 'Nombres', 'text', 120, true],
  ['apellido_paterno', 'Apellido paterno', 'text', 80, true],
  ['apellido_materno', 'Apellido materno', 'text', 80],
  ['tipo_doc_identidad', 'Tipo de identificación', 'text', 15, true],
  ['numero_doc_identidad', 'DNI', 'text', 8, true],
  ['fecha_nacimiento', 'Fecha de nacimiento', 'date'],
  ['nacionalidad', 'Nacionalidad', 'text', 60],
  ['estado_civil', 'Estado civil', 'text', 15],
  ['domicilio', 'Domicilio', 'text', 250],
  ['telefono', 'Teléfono', 'tel', 9],
  ['email_institucional', 'Correo institucional', 'email', 255, true],
  ['nombre_puesto', 'Puesto registrado en la persona', 'text', 150],
]
const flags = [
  ['tiene_derechohabientes', 'Tiene derechohabientes', 'Cónyuge, concubino/a o hijos.'],
  ['con_discapacidad', 'Con discapacidad', 'El servidor o alguno de sus hijos (Directiva, punto 1 k).'],
]

export default function GeneralDataEditor({ usuario, data, onSaved, onCancel, onUploadDni, onUploadFicha }) {
  const puedeDiscapacidad = usePuede('discapacidad.editar')
  const [original] = useState(() => ({ ...data.usuario.values, ...data.edit_values }))
  const [version] = useState(data.version)
  const [bloqueados] = useState(() => data.campos_identidad_bloqueados || ['tipo_doc_identidad'])
  const bloqueado = campo => bloqueados.includes(campo)
  const [draft, setDraft] = useState(() => ({
    ...Object.fromEntries(fields.map(([key]) => [key, original[key] ?? ''])),
    ...Object.fromEntries(flags.map(([key]) => [key, valorBandera(original[key])])),
    tipo_personal: original.tipo_personal,
    unidad_organica_id: original.unidad_organica_id ?? '',
    regimen_normativo_id: original.regimen_normativo_id ?? '',
  }))
  const [location, setLocation] = useState(() => partesUbicacion(original))
  const options = useLiveQuery('/expediente-opciones')
  const leyes = options.data?.data.regimenes
  const opcionesDe = (catalog, actual) => catalog === 'regimenes'
    ? (leyes || []).filter(ley => ley.id === actual || leyesDelTipo(leyes, draft.tipo_personal).includes(ley))
    : options.data?.data[catalog] || []
  const leyDelTipo = (id, tipo) => (leyes || []).some(ley => ley.id === id && ley.aplica_a === tipo) ? id : ''
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [fieldErrors, setFieldErrors] = useState({})

  async function save(event) {
    event.preventDefault()
    if (saving) return
    const values = Object.values(location).map(value => value.trim())
    if (values.some(Boolean) && !values.every(Boolean)) {
      setError('Completa región, provincia y distrito, o deja los tres vacíos.')
      return
    }
    const changes = cambiosInformacionPersonal(original, draft, location, bloqueados)
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

  function campo(key) {
    const [, title, type, maxLength, required] = fields.find(([nombre]) => nombre === key)
    const registrado = bloqueado(key)
    const digits = key === 'numero_doc_identidad' ? 8 : key === 'telefono' ? 9 : null
    const props = { name: key, className: inputClass, required, value: key === 'tipo_doc_identidad' && draft[key] === 'dni' ? 'DNI' : draft[key],
      inputMode: digits ? 'numeric' : undefined, pattern: digits ? `[0-9]{${digits}}` : undefined,
      title: registrado ? 'Dato de identidad registrado' : digits ? `Debe contener ${digits} dígitos.` : undefined,
      'aria-invalid': !!fieldErrors[key],
      onChange: event => {
        if (registrado) return
        const value = digits ? event.target.value.replace(/\D/g, '').slice(0, digits) : event.target.value
        setDraft(actual => ({ ...actual, [key]: value }))
      } }
    return <div key={key} className="flex min-w-0 flex-col gap-2 text-xs font-medium text-slate-600">
      <label htmlFor={`personal-${key}`} className="flex flex-wrap items-center justify-between gap-2"><span>{title}{required ? ' *' : ''}</span>{registrado && <span className="text-[10px] font-normal text-slate-400">Registrado</span>}</label>
      {key === 'estado_civil'
        ? <select {...props} id={`personal-${key}`}><Opciones lista={estadosCiviles} actual={draft[key]} vacio="No registrado" /></select>
        : <input {...props} id={`personal-${key}`} type={type} maxLength={maxLength} readOnly={registrado} aria-readonly={registrado || undefined} />}
      {fieldErrors[key] && <span className="text-rose-700">{fieldErrors[key].join(' ')}</span>}
    </div>
  }

  return <DocumentoModal titulo="Editar información personal" busy={saving} onCancel={onCancel}>
    <form onSubmit={save} onKeyDown={advanceOnEnter} aria-busy={saving}>
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <p className="max-w-xl text-xs leading-relaxed text-slate-500">Los datos de identidad ya registrados están bloqueados. Completa la información pendiente y actualiza tus datos de contacto.</p>
        <div className="flex flex-wrap gap-2">{[[onUploadFicha, 'Subir ficha personal'], [onUploadDni, 'Subir copia de DNI']].map(([accion, titulo]) => accion && <button key={titulo} type="button" disabled={saving} onClick={accion} className="inline-flex items-center gap-2 rounded-xl border border-teal-200 bg-white px-3 py-2 text-xs font-semibold text-teal-800 hover:bg-teal-50"><Icon name="upload" size={16} />{titulo}</button>)}</div>
      </div>
      {error && <p role="alert" className="mb-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-700">{error}</p>}
      <fieldset disabled={saving} className="grid min-w-0 gap-x-5 gap-y-5 sm:grid-cols-2 lg:grid-cols-3">
        {['nombres', 'apellido_paterno', 'apellido_materno', 'tipo_doc_identidad', 'numero_doc_identidad', 'fecha_nacimiento', 'nacionalidad', 'estado_civil', 'domicilio'].map(campo)}
        {['region', 'provincia', 'distrito'].every(key => bloqueado(`ubicacion.${key}`))
          ? <div className="col-span-full grid gap-5 sm:grid-cols-3">{[['region', 'Región'], ['provincia', 'Provincia'], ['distrito', 'Distrito']].map(([key, label]) => <label key={key} className="flex min-w-0 flex-col gap-2 text-xs font-medium text-slate-600"><span className="flex justify-between gap-2">{label}<span className="text-[10px] font-normal text-slate-400">Registrado</span></span><input name={key} className={inputClass} value={location[key]} readOnly aria-readonly="true" /></label>)}</div>
          : <UbigeoFields value={location} onChange={setLocation} disabled={saving} bloqueados={['region', 'provincia', 'distrito'].filter(key => bloqueado(`ubicacion.${key}`))} />}
        {Object.entries(fieldErrors).filter(([key]) => key === 'ubicacion' || key.startsWith('ubicacion.')).map(([key, mensajes]) => <p key={key} className="col-span-full text-xs text-rose-700">{mensajes.join(' ')}</p>)}
        {campo('telefono')}
        {campo('email_institucional')}
        <label className="flex flex-col gap-2 text-xs font-medium text-slate-600">Tipo de personal *<select name="tipo_personal" className={inputClass} value={draft.tipo_personal} onChange={event => setDraft(previous => ({ ...previous, tipo_personal: event.target.value, regimen_normativo_id: leyDelTipo(previous.regimen_normativo_id, event.target.value) }))}><option value="docente">Docente</option><option value="administrativo">Administrativo</option></select></label>
        {[['unidad_organica_id', 'Unidad orgánica registrada en la persona', 'unidades', 'Sin asignar'], ['regimen_normativo_id', 'Ley / régimen normativo', 'regimenes', 'Sin definir']].map(([key, title, catalog, vacio]) => <label key={key} className="flex flex-col gap-2 text-xs font-medium text-slate-600">{title}<select name={key} className={inputClass} value={draft[key]} disabled={!options.data} onChange={event => setDraft(previous => ({ ...previous, [key]: event.target.value }))}><option value="">{vacio}</option>{!options.data && draft[key] && <option value={draft[key]}>{data.usuario.values[key]}</option>}{opcionesDe(catalog, draft[key]).map(item => <option key={item.id} value={item.id}>{item.nombre}{item.activo ? '' : ' (inactivo)'}</option>)}</select>{fieldErrors[key] && <span className="text-rose-700">{fieldErrors[key].join(' ')}</span>}</label>)}
        {campo('nombre_puesto')}
        {options.error && <p role="alert" className="col-span-full text-xs text-rose-700">No se pudieron cargar las unidades y regímenes. <button type="button" className="underline" onClick={options.retry}>Reintentar</button></p>}
        {flags.map(([key, title, ayuda]) => {
          const bloqueada = key === 'con_discapacidad' && !puedeDiscapacidad
          return <TresEstados key={key} name={key} label={title} ayuda={bloqueada ? `${ayuda} Solo administración de la ORH puede registrarlo.` : ayuda} value={draft[key]} disabled={bloqueada} error={fieldErrors[key]} onChange={value => setDraft(previous => ({ ...previous, [key]: value }))} />
        })}
        <p className="col-span-full text-xs text-slate-500">El puesto, la unidad y las condiciones laborales actuales se registran en el vínculo laboral de la sección 02.</p>
        <div className="col-span-full flex justify-end gap-3 border-t border-slate-100 pt-5"><button type="button" onClick={onCancel} className="rounded-xl border border-slate-200 px-4 py-2.5 text-sm">Cancelar</button><button type="submit" className="rounded-xl bg-teal-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-teal-800">{saving ? 'Guardando…' : 'Guardar cambios'}</button></div>
      </fieldset>
    </form>
  </DocumentoModal>
}
