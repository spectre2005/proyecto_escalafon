import { useRef, useState } from 'react'
import { useOutletContext } from 'react-router-dom'
import Icon from '../../components/ui/Icon'
import { normalizeInstitution } from '../../config/institution'
import { apiErrorMessage } from '../../axios'
import { usePuede } from '../auth/session'

const inputClass = 'mt-2 block w-full rounded-xl border border-slate-200 bg-slate-50/50 px-4 py-3 font-normal text-slate-800 outline-none focus:ring-2 focus:ring-teal-600'
const cardClass = 'rounded-2xl border border-slate-200 bg-white p-6 sm:p-8'

export default function SettingsPage() {
  const { institution, updateInstitution, institutionLoading, institutionError, institutionUpdatedAt, reloadInstitution } = useOutletContext()
  const canEdit = usePuede('sistema.administrar')
  const [localDraft, setDraft] = useState(null)
  const draft = localDraft || institution
  const [saving, setSaving] = useState(false)
  const [logoBusy, setLogoBusy] = useState(false)
  const [previewBusy, setPreviewBusy] = useState(false)
  const [notice, setNotice] = useState(null)
  const [conflict, setConflict] = useState(false)
  const logoInput = useRef(null)
  function change(key, value) {
    setDraft(previous => ({ ...(previous || institution), [key]: value }))
    setNotice(null)
  }
  async function selectLogo(event) {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!file) return
    setNotice(null)
    if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
      setNotice({ error: true, text: 'Selecciona un logo PNG, JPG o WebP de hasta 2 MB.' })
      return
    }
    setLogoBusy(true)
    let bitmap
    try {
      bitmap = await createImageBitmap(file)
      if (bitmap.width > 6000 || bitmap.height > 6000) throw new Error('El logo admite imágenes de hasta 6.000 × 6.000 píxeles.')
      const scale = Math.min(1, 384 / bitmap.width, 384 / bitmap.height)
      const canvas = document.createElement('canvas')
      canvas.width = Math.max(1, Math.round(bitmap.width * scale))
      canvas.height = Math.max(1, Math.round(bitmap.height * scale))
      canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height)
      const logo = canvas.toDataURL('image/png')
      if (logo.length > 750000) throw new Error('La imagen del logo es demasiado compleja. Selecciona una versión más pequeña.')
      change('logo', logo)
    } catch (error) {
      setNotice({ error: true, text: error.message || 'No se pudo procesar el logo. Selecciona otra imagen.' })
    } finally {
      bitmap?.close()
      setLogoBusy(false)
    }
  }
  async function submit(event) {
    event.preventDefault()
    if (saving || logoBusy || institutionLoading || institutionError || conflict) return
    if (['name', 'initials', 'area', 'reportTitle'].some(key => !draft[key].trim())) {
      setNotice({ error: true, text: 'Completa el nombre, las siglas, el área responsable y el título del reporte.' })
      return
    }
    setSaving(true)
    setNotice(null)
    try {
      await updateInstitution(normalizeInstitution(draft))
      setDraft(null)
      setNotice({ text: 'Configuración guardada. La identidad institucional está disponible para todo el equipo.' })
    } catch (error) {
      const isConflict = error.response?.status === 409
      setConflict(isConflict)
      setNotice({ error: true, text: isConflict ? error.response.data.message : apiErrorMessage(error) })
    } finally {
      setSaving(false)
    }
  }
  async function preview() {
    setPreviewBusy(true)
    setNotice(null)
    try {
      const { createPersonalPdf } = await import('../reportes/pdf/fichaPersonalPdf')
      createPersonalPdf({ blank: true, institution: normalizeInstitution(draft) }).save('vista_previa_institucional.pdf')
    } catch {
      setNotice({ error: true, text: 'No se pudo generar la vista previa. Revisa el logo e inténtalo nuevamente.' })
    } finally {
      setPreviewBusy(false)
    }
  }
  async function reload() {
    setDraft(null)
    setConflict(false)
    setNotice(null)
    await reloadInstitution()
  }
  if (!canEdit) return <p role="alert" className={cardClass}>La configuración institucional requiere permisos de administración.</p>
  return <div>
    <p className="text-[10px] font-semibold uppercase tracking-[.2em] text-teal-700">Administración</p>
    <h1 className="mt-2 text-3xl font-bold tracking-tight text-slate-800">Configuración institucional</h1>
    <p className="mt-3 max-w-2xl text-sm leading-relaxed text-slate-500">Define la identidad de tu institución y la presentación de las fichas PDF. Los cambios se comparten con todo el equipo.</p>
    {institutionLoading && <p role="status" className="mt-5 text-sm text-slate-500">Cargando configuración…</p>}
    {institutionError && <div role="alert" className="mt-5 rounded-xl bg-rose-50 p-4 text-sm text-rose-700">{institutionError}<button type="button" onClick={reload} className="ml-2 font-semibold underline">Reintentar</button></div>}
    <div className="mt-8 grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
      <form onSubmit={submit} className="space-y-6" aria-busy={saving || logoBusy}>
        <fieldset disabled={saving || logoBusy || institutionLoading || !!institutionError || conflict} className="space-y-6 disabled:opacity-70">
          <section className={cardClass}>
            <div className="mb-6 flex items-center gap-3"><span className="grid size-11 place-items-center rounded-xl bg-teal-50 text-teal-700"><Icon name="building" /></span><div><h2 className="font-semibold text-slate-800">Identidad y logo</h2><p className="mt-1 text-xs text-slate-500">Identificación del panel y encabezado de los reportes.</p></div></div>
            <div className="mb-6 flex flex-wrap items-center gap-5">
              <div className="grid size-24 shrink-0 place-items-center overflow-hidden rounded-2xl border border-slate-200 bg-slate-50 p-2">{draft.logo ? <img src={draft.logo} alt="Vista previa del logo institucional" className="size-full object-contain" /> : <Icon name="building" size={36} />}</div>
              <div><div className="flex flex-wrap gap-3"><button type="button" onClick={() => logoInput.current?.click()} className="rounded-xl border border-teal-200 px-4 py-2 text-sm font-semibold text-teal-800">{logoBusy ? 'Procesando…' : draft.logo ? 'Cambiar logo' : 'Subir logo'}</button>{draft.logo && <button type="button" onClick={() => change('logo', '')} className="text-sm font-medium text-slate-500">Quitar logo</button>}</div><p className="mt-3 text-xs text-slate-500">PNG, JPG o WebP · Hasta 2 MB. Se conserva la proporción.</p><input ref={logoInput} type="file" accept="image/png,image/jpeg,image/webp" aria-label="Seleccionar logo institucional" hidden onChange={selectLogo} /></div>
            </div>
            <div className="grid gap-5 sm:grid-cols-2">{[
              ['name', 'Nombre de la institución', 150, true], ['initials', 'Siglas', 12, true], ['area', 'Área responsable', 150, true], ['ruc', 'RUC', 11, false],
            ].map(([key, label, max, required]) => <label key={key} className="text-sm font-medium text-slate-600">{label}{required ? ' *' : ''}<input required={required} maxLength={max} inputMode={key === 'ruc' ? 'numeric' : undefined} pattern={key === 'ruc' ? '[0-9]{11}' : undefined} title={key === 'ruc' ? 'El RUC debe contener 11 dígitos.' : undefined} value={draft[key]} onChange={event => change(key, event.target.value)} className={inputClass} /></label>)}</div>
          </section>
          <section className={cardClass}>
            <h2 className="font-semibold text-slate-800">Dirección y contacto</h2><p className="mt-1 mb-6 text-xs text-slate-500">Datos opcionales que acompañarán los reportes institucionales.</p>
            <div className="grid gap-5 sm:grid-cols-2">{[
              ['address', 'Dirección institucional', 'text', 250], ['email', 'Correo de contacto', 'email', 255], ['phone', 'Teléfono de contacto', 'tel', 20], ['website', 'Sitio web', 'url', 255],
            ].map(([key, label, type, max]) => <label key={key} className="text-sm font-medium text-slate-600">{label}<input type={type} maxLength={max} placeholder={key === 'website' ? 'https://www.institucion.edu.pe' : undefined} value={draft[key]} onChange={event => change(key, event.target.value)} className={inputClass} /></label>)}</div>
          </section>
          <section className={cardClass}>
            <h2 className="font-semibold text-slate-800">Presentación de los PDF</h2><p className="mt-1 mb-6 text-xs text-slate-500">Personaliza el encabezado y el pie de las fichas generadas.</p>
            <div className="space-y-5"><label className="block text-sm font-medium text-slate-600">Título del reporte *<input required maxLength={100} value={draft.reportTitle} onChange={event => change('reportTitle', event.target.value)} className={inputClass} /></label><label className="block text-sm font-medium text-slate-600">Pie de página<textarea rows={2} maxLength={180} value={draft.reportFooter} onChange={event => change('reportFooter', event.target.value)} className={inputClass} /></label><label className="flex items-center justify-between gap-4 text-sm font-medium text-slate-600">Color institucional de los reportes<span className="flex items-center gap-3"><span className="text-xs font-normal text-slate-500">{draft.reportColor.toUpperCase()}</span><input type="color" value={draft.reportColor} onChange={event => change('reportColor', event.target.value)} className="h-10 w-14 cursor-pointer rounded-lg border border-slate-200" /></span></label>
              {[
                ['showLogo', 'Mostrar logo en los reportes'], ['showEmissionDate', 'Mostrar fecha de emisión'], ['showPageNumbers', 'Numerar las páginas'],
              ].map(([key, label]) => <label key={key} className="flex items-center gap-3 text-sm text-slate-600"><input type="checkbox" checked={draft[key]} onChange={event => change(key, event.target.checked)} className="size-4 accent-teal-700" />{label}</label>)}
            </div>
          </section>
          <div className="flex flex-wrap items-center gap-3"><button type="submit" className="rounded-xl bg-teal-700 px-5 py-3 text-sm font-semibold text-white hover:bg-teal-800">{saving ? 'Guardando…' : 'Guardar configuración'}</button><button type="button" onClick={() => { setDraft(null); setNotice(null) }} disabled={!localDraft} className="rounded-xl border border-slate-200 px-4 py-3 text-sm text-slate-600 disabled:opacity-40">Descartar cambios</button>{localDraft && <span className="text-xs text-amber-700">Cambios sin guardar</span>}</div>
        </fieldset>
        {notice && <p role={notice.error ? 'alert' : 'status'} className={`rounded-xl p-4 text-sm ${notice.error ? 'bg-rose-50 text-rose-700' : 'bg-teal-50 text-teal-800'}`}>{notice.text}</p>}
        {conflict && <button type="button" onClick={reload} className="text-sm font-semibold text-teal-700 underline">Recargar configuración y descartar este borrador</button>}
        {institutionUpdatedAt && <p className="text-xs text-slate-500">Última actualización: {new Date(institutionUpdatedAt).toLocaleString('es-PE', { timeZone: 'America/Lima' })}</p>}
      </form>
      <aside className={`${cardClass} xl:sticky xl:top-6`}>
        <h2 className="font-semibold text-slate-800">Vista previa institucional</h2><p className="mt-2 text-xs leading-relaxed text-slate-500">Descarga una ficha de muestra con los cambios de este borrador.</p>
        <div className="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4"><div className="min-h-64 rounded-lg border border-slate-200 bg-white p-4 shadow-sm">{draft.showLogo && draft.logo && <img src={draft.logo} alt="Logo en el reporte" className="mx-auto mb-3 h-14 max-w-full object-contain" />}<p className="break-words text-center text-xs font-bold" style={{ color: draft.reportColor }}>{draft.name}</p><p className="mt-1 text-center text-[10px] text-slate-500">{draft.area}</p><div className="my-3 border-t-2" style={{ borderColor: draft.reportColor }} /><p className="text-center text-[10px] font-semibold uppercase text-slate-700">{draft.reportTitle}</p><div className="mt-5 grid grid-cols-2 gap-2" aria-hidden="true">{Array.from({ length: 6 }, (_, index) => <div key={index} className="h-8 rounded border border-slate-200 bg-slate-50" />)}</div><p className="mt-6 break-words text-center text-[9px] text-slate-500">{draft.reportFooter}</p>{draft.showPageNumbers && <p className="mt-2 text-right text-[9px] text-slate-400">Página 1 de 1</p>}</div></div>
        <button type="button" onClick={preview} disabled={previewBusy || logoBusy || institutionLoading} className="mt-5 w-full rounded-xl border border-teal-200 px-4 py-3 text-sm font-semibold text-teal-800 disabled:opacity-50">{previewBusy ? 'Generando…' : 'Descargar vista previa PDF'}</button>
      </aside>
    </div>
  </div>
}
