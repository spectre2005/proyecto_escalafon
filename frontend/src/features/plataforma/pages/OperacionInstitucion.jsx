import { useEffect, useState } from 'react'
import plataforma, { mensajeError } from '../api'
import { WorkflowField } from '../../../shared/WorkflowControls'

const boton = 'rounded-xl border border-teal-300 bg-white px-3 py-2 text-sm font-semibold text-teal-800 disabled:opacity-50'
const campos = [['razon_social', 'Razón social'], ['ruc', 'RUC (11 dígitos)'], ['contacto_nombre', 'Nombre del contacto'], ['contacto_email', 'Correo del contacto', 'email'], ['contacto_telefono', 'Teléfono del contacto'], ['numero_contrato', 'Número de contrato'], ['fecha_inicio_contrato', 'Fecha de inicio del contrato', 'date']]

export default function OperacionInstitucion({ institucion }) {
  const [estado, setEstado] = useState({ loading: true })
  const [intento, setIntento] = useState(0)
  const [consumo, setConsumo] = useState(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [mensaje, setMensaje] = useState('')
  useEffect(() => {
    const controller = new AbortController()
    plataforma.get(`/instituciones/${institucion.id}/contrato`, { signal: controller.signal }).then(response => setEstado({ data: response.data.data })).catch(failure => { if (!controller.signal.aborted) setEstado({ error: mensajeError(failure) }) })
    return () => controller.abort()
  }, [institucion.id, intento])
  async function guardar(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true); setError(''); setMensaje('')
    try {
      const datos = Object.fromEntries(new FormData(event.currentTarget))
      const response = await plataforma.put(`/instituciones/${institucion.id}/contrato`, { ...datos, version_contrato: estado.data.version_contrato })
      setEstado({ data: response.data.data }); setMensaje('Datos contractuales actualizados y auditados.')
    } catch (failure) { setError(mensajeError(failure)) } finally { setBusy(false) }
  }
  async function medir() {
    if (busy) return
    setBusy(true); setError('')
    try { setConsumo((await plataforma.get(`/instituciones/${institucion.id}/consumo`, { timeout: 120000 })).data.data) }
    catch (failure) { setError(mensajeError(failure)) } finally { setBusy(false) }
  }
  return <section className="my-6 space-y-4 rounded-2xl border border-slate-200 bg-white p-5"><h2 className="font-semibold">Cliente y contrato</h2><p className="text-sm text-slate-600">Datos administrativos de plataforma, separados de la identidad visual institucional. Los campos opcionales permiten conservar registros anteriores incompletos.</p>{estado.loading && <p role="status">Cargando contrato…</p>}{estado.error && <p role="alert">{estado.error} <button className={boton} onClick={() => setIntento(value => value + 1)}>Reintentar</button></p>}{mensaje && <p role="status" className="text-teal-800">{mensaje}</p>}{error && <p role="alert" className="text-rose-800">{error} <button type="button" className={boton} onClick={() => setIntento(value => value + 1)}>Recargar contrato</button></p>}{estado.data && <form key={estado.data.version_contrato} onSubmit={guardar} className="space-y-4"><fieldset disabled={busy || institucion.estado === 'archivada'} className="grid gap-4 sm:grid-cols-2">{campos.map(([name, label, type]) => <WorkflowField key={name} name={name} label={label} type={type || 'text'} defaultValue={estado.data[name] || ''} required={false} maxLength={name === 'ruc' ? 11 : name === 'contacto_telefono' ? 30 : name === 'contacto_nombre' ? 150 : name === 'numero_contrato' ? 100 : 255} />)}<WorkflowField name="motivo" label="Motivo del cambio" type="textarea" minLength={5} maxLength={1000} /></fieldset>{institucion.estado !== 'archivada' && <button className={boton} disabled={busy}>Guardar contrato</button>}</form>}<h2 className="pt-4 font-semibold">Consumo y disponibilidad</h2>{institucion.aprovisionada ? <button type="button" className={boton} disabled={busy} onClick={medir}>{busy ? 'Procesando…' : 'Medir consumo ahora'}</button> : <p className="text-sm">Disponible después del aprovisionamiento.</p>}{consumo && <dl className="grid gap-3 text-sm sm:grid-cols-2">{[['Base de datos', consumo.base_datos], ['Almacenamiento', consumo.almacenamiento], ['Personas', consumo.personas], ['Legajos', consumo.legajos], ['Documentos', consumo.documentos], ['Archivos físicos', consumo.archivos], ['Tamaño físico', consumo.bytes_archivos === null ? null : `${(consumo.bytes_archivos / 1048576).toFixed(2)} MB`], ['Último acceso institucional', consumo.ultimo_acceso], ['Medido en', consumo.medido_en], ['Respaldo', 'Sin verificación automática; comprobar la restauración con el operador']].map(([label, value]) => <div key={label}><dt className="text-slate-500">{label}</dt><dd className="mt-1">{value ?? 'No disponible'}</dd></div>)}</dl>}</section>
}
