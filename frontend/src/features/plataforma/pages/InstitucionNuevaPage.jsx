import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { mensajeError, registrarInstitucion, sesionTerminada } from '../api'
import { Aviso } from '../componentes'
import { proponerSlug, proponerUbicacion } from '../estado'
import { ENTRADA, TARJETA, boton } from '../estilos'
import { useSesionPlataforma } from '../sesion'
import { Campo } from '../ui'

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
    <form onSubmit={guardar} aria-busy={busy} className={`${TARJETA} mt-6 max-w-2xl space-y-4`}>
      <Aviso tipo="info">Se registra sin aprovisionar: no opera hasta que su base de datos, su almacenamiento y su configuración estén listos y verificados. Este panel no crea bases ni carpetas.</Aviso>
      <Aviso>{error}</Aviso>
      <Campo etiqueta="Nombre *"><input required minLength={3} maxLength={150} value={datos.nombre} onChange={event => cambiar('nombre', event.target.value)} disabled={busy} className={ENTRADA} /></Campo>
      <Campo etiqueta="Identificador *" ayuda="Minúsculas, números y guiones (3 a 40). Será su subdominio; no se cambia una vez aprovisionada."><input required pattern="[a-z0-9][a-z0-9\-]{1,38}[a-z0-9]" maxLength={40} value={datos.slug} onChange={event => { setSlugTocado(true); cambiar('slug', event.target.value.toLowerCase()) }} disabled={busy} className={ENTRADA} /></Campo>
      <Campo etiqueta="Base de datos" ayuda="Vacío: se usa la propuesta."><input maxLength={63} value={datos.base_datos} placeholder={propuesta.base_datos} onChange={event => cambiar('base_datos', event.target.value)} disabled={busy} className={ENTRADA} /></Campo>
      <Campo etiqueta="Almacenamiento" ayuda="Vacío: se usa la propuesta."><input maxLength={120} value={datos.almacenamiento} placeholder={propuesta.almacenamiento} onChange={event => cambiar('almacenamiento', event.target.value)} disabled={busy} className={ENTRADA} /></Campo>
      <div className="flex justify-end gap-3 pt-2"><Link to="/plataforma/instituciones" className={boton('secundario')}>Cancelar</Link><button type="submit" disabled={busy} className={boton('principal')}>{busy ? 'Registrando…' : 'Registrar'}</button></div>
    </form>
  </section>
}
