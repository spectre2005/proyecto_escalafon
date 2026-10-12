import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { mensajeError, registrarInstitucion, sesionTerminada } from '../api'
import { Aviso } from '../componentes'
import { proponerSlug, proponerUbicacion } from '../estado'
import { useSesionPlataforma } from '../sesion'

const entrada = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-sm text-slate-800'

/** Registro de una institución: queda «registrada» (sin aprovisionar, no opera). La ubicación propuesta es editable. */
export default function InstitucionNuevaPage() {
  const { terminada } = useSesionPlataforma()
  const navigate = useNavigate()
  const [datos, setDatos] = useState({ nombre: '', slug: '', base_datos: '', almacenamiento: '' })
  const [slugTocado, setSlugTocado] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  useEffect(() => { document.title = 'Registrar institución · Plataforma' }, [])
  const propuesta = proponerUbicacion(datos.slug)
  const cambiar = (campo, valor) => setDatos(actual => {
    const siguiente = { ...actual, [campo]: valor }
    if (campo === 'nombre' && !slugTocado) siguiente.slug = proponerSlug(valor)
    return siguiente
  })
  async function guardar(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    try {
      const creada = await registrarInstitucion({ nombre: datos.nombre.trim(), slug: datos.slug.trim(), ...(datos.base_datos.trim() && { base_datos: datos.base_datos.trim() }), ...(datos.almacenamiento.trim() && { almacenamiento: datos.almacenamiento.trim() }) })
      navigate(`/plataforma/instituciones/${creada.id}`, { state: { aviso: 'Institución registrada. Queda pendiente de aprovisionamiento: todavía no opera.' } })
    } catch (failure) {
      if (sesionTerminada(failure)) terminada()
      else setError(mensajeError(failure))
    } finally {
      setBusy(false)
    }
  }
  return <section aria-labelledby="titulo-nueva">
    <Link to="/plataforma/instituciones" className="text-sm text-teal-700 underline">Volver a instituciones</Link>
    <h1 id="titulo-nueva" className="mt-3 text-2xl font-bold text-slate-900">Registrar institución</h1>
    <form onSubmit={guardar} aria-busy={busy} className="mt-6 max-w-2xl space-y-4 rounded-2xl border border-slate-200 bg-white p-6">
      <Aviso tipo="info">Se registra sin aprovisionar: no opera hasta que su base de datos, su almacenamiento y su configuración estén listos y verificados. Este panel no crea bases ni carpetas.</Aviso>
      <Aviso>{error}</Aviso>
      <label className="grid gap-2 text-xs font-medium text-slate-600">Nombre *<input required minLength={3} maxLength={150} value={datos.nombre} onChange={event => cambiar('nombre', event.target.value)} disabled={busy} className={entrada} /></label>
      <label className="grid gap-2 text-xs font-medium text-slate-600">Identificador *<input required pattern="[a-z0-9][a-z0-9\-]{1,38}[a-z0-9]" maxLength={40} value={datos.slug} onChange={event => { setSlugTocado(true); cambiar('slug', event.target.value.toLowerCase()) }} disabled={busy} className={entrada} /><span className="font-normal text-slate-500">Minúsculas, números y guiones (3 a 40). Será su subdominio; no se cambia una vez aprovisionada.</span></label>
      <label className="grid gap-2 text-xs font-medium text-slate-600">Base de datos<input maxLength={63} value={datos.base_datos} placeholder={propuesta.base_datos} onChange={event => cambiar('base_datos', event.target.value)} disabled={busy} className={entrada} /><span className="font-normal text-slate-500">Vacío: se usa la propuesta.</span></label>
      <label className="grid gap-2 text-xs font-medium text-slate-600">Almacenamiento<input maxLength={120} value={datos.almacenamiento} placeholder={propuesta.almacenamiento} onChange={event => cambiar('almacenamiento', event.target.value)} disabled={busy} className={entrada} /><span className="font-normal text-slate-500">Vacío: se usa la propuesta.</span></label>
      <div className="flex justify-end gap-3 pt-2"><Link to="/plataforma/instituciones" className="rounded-xl border border-slate-200 px-4 py-2 text-sm">Cancelar</Link><button type="submit" disabled={busy} className="rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white">{busy ? 'Registrando…' : 'Registrar'}</button></div>
    </form>
  </section>
}
