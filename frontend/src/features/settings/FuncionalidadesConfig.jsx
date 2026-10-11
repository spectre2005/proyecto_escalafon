import { useState } from 'react'
import Icon from '../../components/ui/Icon'
import { apiErrorMessage } from '../../axios'
import { useLiveQuery } from '../../shared/queries/useLiveQuery'
import { legajoSections } from '../legajos/config/legajoSections'
import { cambiarFuncionalidad, urlFuncionalidades } from './funcionalidadesApi'

const cardClass = 'rounded-2xl border border-slate-200 bg-white p-6 sm:p-8'

// Nombre de cada sección para agrupar (las 11 implementadas y la adicional de legajos pasivos).
const nombresSeccion = { ...Object.fromEntries(legajoSections.map(seccion => [Number(seccion.number), seccion.title])), 13: 'Sección adicional: legajos pasivos' }

// Efecto específico de una opción que no tiene menú propio.
const efectos = {
  's07.evaluaciones.puntaje': {
    desactivar: 'Las evaluaciones de desempeño se siguen registrando y consultando, sin el campo «Puntaje». No se aceptan puntajes nuevos.',
    activar: 'Se vuelve a mostrar el campo «Puntaje» en las evaluaciones y se aceptan puntajes nuevos.',
  },
}

function fechaHora(valor) {
  return valor ? new Date(valor).toLocaleString('es-PE', { timeZone: 'America/Lima' }) : ''
}

function Estado({ funcionalidad }) {
  if (funcionalidad.nucleo) return <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-medium text-slate-500">Siempre activa</span>
  return <span className={`rounded-full px-2.5 py-1 text-[11px] font-medium ${funcionalidad.activa ? 'bg-teal-50 text-teal-700' : 'bg-slate-100 text-slate-500'}`}>{funcionalidad.activa ? 'Activa' : 'Desactivada'}</span>
}

// Confirmación con motivo obligatorio: el cambio queda en la auditoría con tu nombre.
function Confirmar({ funcionalidad, onCancel, onConfirm, busy }) {
  const [motivo, setMotivo] = useState('')
  const desactivar = funcionalidad.activa
  const seccion = String(funcionalidad.seccion).padStart(2, '0')
  const efecto = efectos[funcionalidad.codigo]?.[desactivar ? 'desactivar' : 'activar']
    || (desactivar ? 'Dejará de mostrarse en el legajo y no se podrán registrar ni modificar sus datos ni subir sus documentos.' : 'Volverá a mostrarse en el legajo con todo lo registrado.')
  return <form role="alertdialog" aria-label={`${desactivar ? 'Desactivar' : 'Activar'} ${funcionalidad.nombre}`} onSubmit={event => { event.preventDefault(); onConfirm(motivo.trim()) }} className={`mt-4 rounded-xl border p-4 ${desactivar ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-teal-200 bg-teal-50 text-teal-900'}`}>
    <p className="text-sm">{desactivar ? 'Desactivar' : 'Activar'} «{funcionalidad.nombre}». {efecto}</p>
    {desactivar && <ul className="mt-2 list-disc space-y-1 pl-5 text-xs">
      <li>{funcionalidad.registros > 0 ? `Tiene ${funcionalidad.registros} registro(s): quedarán ocultos, no se borran, y volverán a verse al activarla.` : 'No tiene registros guardados.'}</li>
      {funcionalidad.oculta_seccion && <li>La sección {seccion} dejará de mostrarse en el legajo.</li>}
      {funcionalidad.advertencia && <li>{funcionalidad.advertencia}</li>}
    </ul>}
    <label className="mt-3 grid gap-2 text-xs">Motivo del cambio *<textarea required minLength={5} maxLength={500} rows={2} value={motivo} onChange={event => setMotivo(event.target.value)} className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800" /></label>
    <div className="mt-3 flex gap-3"><button type="button" disabled={busy} onClick={onCancel} className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">Cancelar</button><button disabled={busy || motivo.trim().length < 5} className="rounded-lg bg-teal-700 px-3 py-2 text-sm font-semibold text-white disabled:opacity-50">{busy ? 'Guardando…' : `Confirmar: ${desactivar ? 'desactivar' : 'activar'}`}</button></div>
  </form>
}

// Funcionalidades de la institución, agrupadas por sección. Separado de la configuración institucional: lo usa quien
// tiene configuracion.modulos, aunque no tenga sistema.administrar. El núcleo (identidad y vínculo) no se desactiva.
export default function FuncionalidadesConfig() {
  const consulta = useLiveQuery(urlFuncionalidades)
  const [cambiando, setCambiando] = useState(null)
  const [busy, setBusy] = useState(false)
  const [notice, setNotice] = useState(null)
  const todas = consulta.data?.data || []
  const porSeccion = [...new Set(todas.map(funcionalidad => funcionalidad.seccion))].map(seccion => [seccion, todas.filter(funcionalidad => funcionalidad.seccion === seccion)])

  async function confirmar(motivo) {
    if (busy || !cambiando) return
    setBusy(true)
    setNotice(null)
    try {
      const { data } = await cambiarFuncionalidad(cambiando.codigo, !cambiando.activa, motivo)
      setNotice({ text: `«${data.nombre}» quedó ${data.activa ? 'activada' : 'desactivada'}. El cambio quedó registrado en la auditoría.` })
      setCambiando(null)
      consulta.retry()
    } catch (error) {
      setNotice({ error: true, text: apiErrorMessage(error) })
    } finally {
      setBusy(false)
    }
  }

  return <section aria-label="Funcionalidades" className={cardClass}>
    <div className="mb-6 flex items-center gap-3"><span className="grid size-11 place-items-center rounded-xl bg-teal-50 text-teal-700"><Icon name="settings" /></span><div><h2 className="font-semibold text-slate-800">Funcionalidades</h2><p className="mt-1 text-xs text-slate-500">Desactiva lo que la institución no utiliza: deja de mostrarse en el legajo y no admite registros nuevos. Desactivar no elimina datos y se puede revertir.</p></div></div>
    {!consulta.data && !consulta.error && <p role="status" className="text-sm text-slate-500">Cargando funcionalidades…</p>}
    {consulta.error && <p role="alert" className="rounded-xl bg-rose-50 p-4 text-sm text-rose-700">{apiErrorMessage(consulta.error)} <button type="button" onClick={consulta.retry} className="font-semibold underline">Reintentar</button></p>}
    {notice && <p role={notice.error ? 'alert' : 'status'} className={`mb-5 rounded-xl p-4 text-sm ${notice.error ? 'bg-rose-50 text-rose-700' : 'bg-teal-50 text-teal-800'}`}>{notice.text}</p>}
    {consulta.data && <div className="space-y-6">{porSeccion.map(([seccion, funcionalidades]) => <section key={seccion} aria-label={nombresSeccion[seccion] || `Sección ${seccion}`}>
      <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">{seccion === 13 ? '' : `${String(seccion).padStart(2, '0')} · `}{nombresSeccion[seccion] || `Sección ${seccion}`}</h3>
      <ul className="divide-y divide-slate-100 rounded-xl border border-slate-100">{funcionalidades.map(funcionalidad => <li key={funcionalidad.codigo} className="px-4 py-3">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="min-w-0">
            <p className="text-sm text-slate-800">{funcionalidad.nombre} <Estado funcionalidad={funcionalidad} /></p>
            {funcionalidad.ultimo_cambio && <p className="mt-1 text-xs text-slate-500">Último cambio: {fechaHora(funcionalidad.ultimo_cambio.fecha)}{funcionalidad.ultimo_cambio.por ? ` · ${funcionalidad.ultimo_cambio.por}` : ''} · Motivo: {funcionalidad.ultimo_cambio.motivo}</p>}
            {!funcionalidad.nucleo && funcionalidad.bloqueos.length > 0 && <p className="mt-1 text-xs text-amber-700">{funcionalidad.bloqueos.join(' ')}</p>}
          </div>
          {funcionalidad.configurable && cambiando?.codigo !== funcionalidad.codigo && <button type="button" disabled={busy || !!cambiando || funcionalidad.bloqueos.length > 0} onClick={() => { setCambiando(funcionalidad); setNotice(null) }} className="rounded-xl border border-teal-200 px-3 py-1.5 text-sm font-semibold text-teal-800 disabled:opacity-50">{funcionalidad.activa ? 'Desactivar' : 'Activar'}</button>}
        </div>
        {cambiando?.codigo === funcionalidad.codigo && <Confirmar funcionalidad={funcionalidad} busy={busy} onCancel={() => setCambiando(null)} onConfirm={confirmar} />}
      </li>)}</ul>
    </section>)}</div>}
  </section>
}
