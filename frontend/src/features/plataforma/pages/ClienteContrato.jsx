import { useState } from 'react'
import { editarInstitucion, mensajeError, sesionTerminada } from '../api'
import { Aviso } from '../componentes'
import CampoRuc, { DatosTributarios } from '../CampoRuc'
import { CAMPOS_CLIENTE, cambiosCliente, fecha, fechaCorta } from '../estado'
import { ENTRADA, boton } from '../estilos'
import { useSesionPlataforma } from '../sesion'
import { useIdentidadRuc } from '../useIdentidadRuc'
import { Campo, Seccion } from '../ui'

/**
 * Datos del cliente y del contrato (opcionales, editables en cualquier estado). El backend valida el RUC y el resto, y
 * guarda los datos tributarios desde su propia consulta del RUC: aquí solo se muestran.
 */
export default function ClienteContrato({ institucion, onCambio }) {
  const { terminada } = useSesionPlataforma()
  const [borrador, setBorrador] = useState(null)
  const consulta = useIdentidadRuc(borrador?.ruc || '', setBorrador, { habilitado: !!borrador })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [aviso, setAviso] = useState('')
  async function guardar(event) {
    event.preventDefault()
    if (busy || consulta.consultando) return
    const datos = cambiosCliente(institucion, borrador)
    // Con una consulta del mismo RUC más reciente que la guardada, se envía el RUC para que el servidor la guarde.
    const empresa = consulta.estado.empresa
    if (empresa && empresa.ruc === borrador.ruc && !('ruc' in datos) && !(institucion.sunat && institucion.sunat.consultado_en >= empresa.consultado_en)) datos.ruc = borrador.ruc
    if (Object.keys(datos).length === 0) { setBorrador(null); return }
    setBusy(true)
    setError('')
    try {
      await editarInstitucion(institucion.id, datos)
      setBorrador(null)
      setAviso('Datos del cliente guardados.')
      onCambio()
    } catch (failure) {
      if (sesionTerminada(failure)) terminada()
      else setError(mensajeError(failure))
    } finally {
      setBusy(false)
    }
  }
  const editar = () => { setAviso(''); setError(''); setBorrador(Object.fromEntries(CAMPOS_CLIENTE.map(({ campo }) => [campo, institucion[campo] ?? '']))) }
  return <Seccion titulo="Cliente y contrato" acciones={!borrador && <button type="button" onClick={editar} className={boton('secundario')}>Editar</button>}>
    <div className="mt-3"><Aviso tipo="exito">{aviso}</Aviso></div>
    {borrador ? <form onSubmit={guardar} aria-busy={busy} className="mt-3 grid gap-4 sm:grid-cols-2">
      <div className="sm:col-span-2"><Aviso>{error}</Aviso></div>
      <div className="sm:col-span-2"><CampoRuc value={borrador.ruc} consulta={consulta} disabled={busy}>
        <Campo etiqueta="Nombre de la institución" ayuda="El nombre para mostrar se cambia desde «Editar datos» de la institución."><input value={institucion.nombre} readOnly className={`${ENTRADA} bg-slate-50`} /></Campo>
        <Campo etiqueta="Razón social" ayuda="Nombre legal de la institución, según el RUC."><input minLength={3} maxLength={200} value={borrador.razon_social} onChange={event => setBorrador(actual => ({ ...actual, razon_social: event.target.value }))} disabled={busy || consulta.consultando} className={ENTRADA} /></Campo>
      </CampoRuc></div>
      {CAMPOS_CLIENTE.filter(({ campo }) => !['ruc', 'razon_social'].includes(campo)).map(({ campo, etiqueta, tipo, ayuda, max }) => <Campo key={campo} etiqueta={etiqueta} ayuda={ayuda}>
        <input type={tipo || 'text'} value={borrador[campo]} maxLength={max} onChange={event => setBorrador({ ...borrador, [campo]: event.target.value })} disabled={busy} className={ENTRADA} />
      </Campo>)}
      <div className="flex justify-end gap-3 sm:col-span-2"><button type="button" onClick={() => setBorrador(null)} disabled={busy} className={boton('secundario')}>Cancelar</button><button type="submit" disabled={busy || consulta.consultando} className={boton('principal')}>{busy ? 'Guardando…' : consulta.consultando ? 'Consultando RUC…' : 'Guardar'}</button></div>
    </form> : <>
      <dl className="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {CAMPOS_CLIENTE.map(({ campo, etiqueta }) => <div key={campo}><dt className="text-xs text-slate-500">{etiqueta}</dt><dd className="mt-1 break-all text-sm text-slate-800">{(campo === 'contrato_inicio' ? (institucion[campo] && fechaCorta(institucion[campo])) : institucion[campo]) || '—'}</dd></div>)}
      </dl>
      {institucion.sunat ? <div className="mt-6 border-t border-slate-100 pt-5">
        <h3 className="text-sm font-semibold text-slate-800">Datos tributarios del RUC</h3>
        <p className="mt-1 text-xs text-slate-500">Según la consulta del {fecha(institucion.sunat.consultado_en)}. Para actualizarlos, usa «Editar» y «Actualizar consulta».</p>
        <div className="mt-3 grid items-start gap-4 lg:grid-cols-2"><DatosTributarios empresa={institucion.sunat} /></div>
      </div> : institucion.ruc && <p className="mt-6 border-t border-slate-100 pt-5 text-xs text-slate-500">Sin datos tributarios guardados para este RUC. Usa «Editar» para consultarlo y guarda.</p>}
    </>}
  </Seccion>
}
