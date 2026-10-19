import { useCallback, useEffect, useState } from 'react'
import { Link, useLocation, useParams, useSearchParams } from 'react-router-dom'
import ModulosSistemaConfig from '../../settings/ModulosSistemaConfig'
import { consultarModulosInstitucion, guardarModuloInstitucion, activarInstitucion, aprovisionarInstitucion, mensajeError, sesionTerminada, suspenderInstitucion, verInstitucion } from '../api'
import { Aviso, Credenciales, EstadoInstitucion, EstadoServicio, SinAprovisionar } from '../componentes'
import AdministradoresInstitucion from './AdministradoresInstitucion'
import AuditoriaInstitucion from './AuditoriaInstitucion'
import ServicioInstitucion from './ServicioInstitucion'
import ClienteContrato from './ClienteContrato'
import IdentificacionInstitucion from './IdentificacionInstitucion'
import RespaldosInstitucion from './RespaldosInstitucion'
import ResumenInstitucion from './ResumenInstitucion'
import UsoTecnico from './UsoTecnico'
import { AVISO_SUSPENSION, AVISO_SUSPENSION_BLOQUEA, accionesDisponibles, fecha } from '../estado'
import { ENTRADA, boton } from '../estilos'
import { PARAMETRO, PESTANAS, conSeccion, idPanel, idPestana, pestanaDe } from '../pestanas'
import { useSesionPlataforma } from '../sesion'
import { Campo, Dialogo, PanelPestana, Pestanas } from '../ui'
import { useEdicionIdentificacion } from '../useEdicionIdentificacion'
import { useUso } from '../useUso'

/** Confirmación de suspender o activar. Suspender exige motivo; los datos y archivos de la institución se conservan. */
function ConfirmarEstado({ accion, institucion, onConfirmar, onCerrar }) {
  const [motivo, setMotivo] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const suspender = accion === 'suspender'
  async function confirmar(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    try {
      await onConfirmar(motivo.trim())
    } catch (failure) {
      setError(mensajeError(failure))
      setBusy(false)
    }
  }
  return <Dialogo titulo={`${suspender ? 'Suspender' : accionesDisponibles(institucion).textoActivar} «${institucion.nombre}»`} onCerrar={onCerrar} onEnviar={confirmar} busy={busy}
    textoBoton={suspender ? 'Suspender' : 'Confirmar'} peligro={suspender}>
    <p className="text-sm text-slate-600">{suspender ? `Su base de datos, sus archivos y sus datos se conservan: nada se borra.${institucion.suspension_bloquea_acceso ? ` ${AVISO_SUSPENSION_BLOQUEA}` : ''}` : 'La institución quedará activa. Solo es posible porque su aprovisionamiento está verificado.'}</p>
    {suspender && !institucion.suspension_bloquea_acceso && <Aviso tipo="info">{AVISO_SUSPENSION}</Aviso>}
    <Aviso>{error}</Aviso>
    <Campo etiqueta={suspender ? 'Motivo *' : 'Motivo (opcional)'}><textarea required={suspender} minLength={suspender ? 5 : undefined} maxLength={500} rows={3} value={motivo} onChange={event => setMotivo(event.target.value)} disabled={busy} className={ENTRADA} /></Campo>
  </Dialogo>
}

/**
 * Aprovisionar: pide el primer administrador (jefe de la ORH) y crea la base, los catálogos y la carpeta. Toma unos
 * segundos; mientras tanto no se puede cerrar. Un 500 trae un mensaje propio (se retiró lo creado).
 */
function Aprovisionar({ institucion, onAprovisionada, onSesionTerminada, onCerrar }) {
  const [datos, setDatos] = useState({ nombres: '', apellido_paterno: '', email: '' })
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  async function confirmar(event) {
    event.preventDefault()
    if (busy) return
    setBusy(true)
    setError('')
    try {
      onAprovisionada(await aprovisionarInstitucion(institucion.id, { ...datos, email: datos.email.trim() }))
    } catch (failure) {
      setError(failure.response?.status === 500 && failure.response.data?.message ? failure.response.data.message : mensajeError(failure))
      setBusy(false)
      if (sesionTerminada(failure)) onSesionTerminada()
    }
  }
  const campo = (nombre, etiqueta, props = {}) => <Campo etiqueta={etiqueta}><input value={datos[nombre]} onChange={event => setDatos({ ...datos, [nombre]: event.target.value })} disabled={busy} required className={ENTRADA} {...props} /></Campo>
  return <Dialogo titulo={`Aprovisionar «${institucion.nombre}»`} onCerrar={onCerrar} onEnviar={confirmar} busy={busy} bloquearCierre
    textoBoton="Aprovisionar" textoOcupado="Creando base y catálogos…">
    <p className="text-sm text-slate-600">Se crea la base <strong>{institucion.base_datos}</strong> con los catálogos (sin datos de ejemplo), la carpeta <strong>{institucion.almacenamiento}</strong> y su primer administrador. La institución no se activa todavía.</p>
    <Aviso>{error}</Aviso>
    {campo('nombres', 'Nombres del primer administrador *', { minLength: 2, maxLength: 100, autoComplete: 'off' })}
    {campo('apellido_paterno', 'Apellido paterno *', { minLength: 2, maxLength: 100, autoComplete: 'off' })}
    {campo('email', 'Correo de acceso *', { type: 'email', maxLength: 255, autoComplete: 'off' })}
  </Dialogo>
}

/**
 * Con key={id}: al pasar directamente a otra institución, todo empieza de cero (estado, ediciones, resumen) y no se ven
 * como actuales los datos de la anterior; sus peticiones en curso se cancelan al desmontarse.
 */
export default function InstitucionDetallePage() {
  const { id } = useParams()
  return <DetalleInstitucion key={id} id={id} />
}

/**
 * Detalle de una institución: cabecera con sus acciones, franja de resumen y pestañas (parámetro «seccion»). Los cinco
 * paneles están siempre montados (el inactivo, oculto): al volver a una pestaña conserva su estado, sus ediciones y sus
 * contraseñas temporales, y cada sección hace sus peticiones una sola vez.
 */
function DetalleInstitucion({ id }) {
  const [params, setParams] = useSearchParams()
  const valorSeccion = params.get(PARAMETRO)
  const seccion = pestanaDe(valorSeccion)
  // Un valor desconocido se corrige sin añadir una entrada al historial; sin parámetro, la URL no cambia.
  useEffect(() => {
    if (valorSeccion !== null && valorSeccion !== seccion) setParams(conSeccion(params, seccion), { replace: true })
  }, [valorSeccion, seccion, params, setParams])
  const cambiarSeccion = clave => { if (clave !== seccion) setParams(conSeccion(params, clave)) }
  const irA = clave => { cambiarSeccion(clave); document.getElementById(idPestana(clave))?.focus() }
  const consultarModulos = useCallback(signal => consultarModulosInstitucion(id, signal), [id])
  const guardarModulo = useCallback((codigo, datos) => guardarModuloInstitucion(id, codigo, datos), [id])
  const { state } = useLocation()
  const { terminada } = useSesionPlataforma()
  const [estado, setEstado] = useState({ loading: true })
  const [intento, setIntento] = useState(0)
  const [aviso, setAviso] = useState(state?.aviso || '')
  const [confirmar, setConfirmar] = useState(null)
  const [credenciales, setCredenciales] = useState(null)
  // Para la franja de resumen, sin peticiones propias: la medición de uso (la misma que muestra UsoTecnico; como antes,
  // se pide una vez cargada la institución) y lo que cargó AdministradoresInstitucion.
  const uso = useUso(estado.data ? id : null)
  const [administradores, setAdministradores] = useState({ loading: true })
  useEffect(() => {
    const controller = new AbortController()
    verInstitucion(id, controller.signal).then(data => { setEstado({ data }); document.title = `${data.nombre} · Plataforma` }).catch(failure => {
      if (controller.signal.aborted) return
      if (sesionTerminada(failure)) terminada()
      else setEstado({ error: mensajeError(failure) })
    })
    return () => controller.abort()
  }, [id, intento, terminada])
  const recargar = () => setIntento(valor => valor + 1)
  const institucion = estado.data
  const edicion = useEdicionIdentificacion(institucion, () => {
    setAviso('Datos guardados.')
    recargar()
  })
  if (estado.loading) return <p role="status" className="text-sm text-slate-500">Cargando…</p>
  if (estado.error) return <div className="space-y-3"><Link to="/plataforma/instituciones" className="text-sm text-teal-700 underline">Volver a instituciones</Link><Aviso>{estado.error}</Aviso><button type="button" onClick={() => { setEstado({ loading: true }); recargar() }} className={boton('secundario')}>Reintentar</button></div>
  const acciones = accionesDisponibles(institucion)
  async function cambiarEstado(motivo) {
    try {
      if (confirmar === 'suspender') await suspenderInstitucion(institucion.id, motivo)
      else await activarInstitucion(institucion.id, motivo)
    } catch (failure) {
      if (sesionTerminada(failure)) terminada()
      throw failure
    }
    setAviso(confirmar === 'suspender' ? 'Institución suspendida. Sus datos se conservan.' : 'Institución activa.')
    setConfirmar(null)
    recargar()
  }
  return <section aria-labelledby="titulo-institucion">
    <Link to="/plataforma/instituciones" className="text-sm text-teal-700 underline">Volver a instituciones</Link>
    <div className="mt-3 flex flex-wrap items-start justify-between gap-4">
      <div><h1 id="titulo-institucion" className="text-2xl font-bold text-slate-900">{institucion.nombre}</h1><div className="mt-2 flex flex-wrap gap-2"><EstadoInstitucion estado={institucion.estado} /><EstadoServicio servicio={institucion.servicio} /><SinAprovisionar institucion={institucion} /></div></div>
      <div className="grid justify-items-end gap-2">
        <div className="flex flex-wrap justify-end gap-2">
          {acciones.aprovisionar && <button type="button" onClick={() => { setAviso(''); setConfirmar('aprovisionar') }} className={boton('principal')}>Aprovisionar</button>}
          {!edicion.borrador && <button type="button" onClick={() => { setAviso(''); edicion.editar() }} className={boton('secundario')}>Editar datos</button>}
          <button type="button" disabled={!acciones.activar} title={institucion.motivo_no_activable || ''} onClick={() => { setAviso(''); setConfirmar('activar') }} className={boton('principal')}>{acciones.textoActivar}</button>
          <button type="button" disabled={!acciones.suspender} title={institucion.motivo_no_suspendible || ''} onClick={() => { setAviso(''); setConfirmar('suspender') }} className={boton('peligroContorno')}>Suspender</button>
        </div>
        {!institucion.suspension_bloquea_acceso && <p role="note" className="max-w-xs rounded-lg bg-amber-50 px-3 py-2 text-right text-xs text-amber-900">{AVISO_SUSPENSION}</p>}
      </div>
    </div>
    <div className="mt-5 space-y-3">
      <Aviso tipo="exito">{aviso}</Aviso>
      {credenciales && <Credenciales titulo="Primer administrador creado." credenciales={credenciales} onCerrar={() => setCredenciales(null)} />}
      {!institucion.aprovisionada && <Aviso tipo="info">{institucion.motivo_no_activable} Usa «Aprovisionar» para crear su base, sus catálogos, su primer administrador y su carpeta.</Aviso>}
      {institucion.estado === 'suspendida' && <Aviso tipo="info">Suspendida {fecha(institucion.suspendida_en)}{institucion.motivo_suspension ? `: ${institucion.motivo_suspension}` : ''}. Sus datos y archivos se conservan.</Aviso>}
    </div>
    <ResumenInstitucion institucion={institucion} uso={uso} administradores={administradores} onIr={irA} />
    <Pestanas pestanas={PESTANAS} activa={seccion} onCambiar={cambiarSeccion} etiqueta="Apartados de la institución" idPestana={idPestana} idPanel={idPanel} />
    <PanelPestana id={idPanel('servicio')} idPestana={idPestana('servicio')} activo={seccion === 'servicio'}>
      <ServicioInstitucion institucion={institucion} onCambio={recargar} />
    </PanelPestana>
    <PanelPestana id={idPanel('administradores')} idPestana={idPestana('administradores')} activo={seccion === 'administradores'}>
      {institucion.aprovisionada ? <AdministradoresInstitucion institucion={institucion} onCambio={recargar} onLista={setAdministradores} />
        : <p className="mt-6 text-sm text-slate-500">Los administradores se crean al aprovisionar la institución: el primero se indica en «Aprovisionar».</p>}
    </PanelPestana>
    <PanelPestana id={idPanel('datos')} idPestana={idPestana('datos')} activo={seccion === 'datos'}>
      <IdentificacionInstitucion institucion={institucion} edicion={edicion} />
      <ClienteContrato institucion={institucion} onCambio={recargar} />
      <div className="mt-6"><ModulosSistemaConfig consultar={consultarModulos} guardar={guardarModulo} onCambio={recargar} /></div>
    </PanelPestana>
    <PanelPestana id={idPanel('tecnico')} idPestana={idPestana('tecnico')} activo={seccion === 'tecnico'}>
      <UsoTecnico uso={uso} />
      {institucion.aprovisionada && <RespaldosInstitucion institucion={institucion} />}
    </PanelPestana>
    <PanelPestana id={idPanel('auditoria')} idPestana={idPestana('auditoria')} activo={seccion === 'auditoria'}>
      <AuditoriaInstitucion institucion={institucion} />
    </PanelPestana>
    {confirmar === 'aprovisionar' && <Aprovisionar institucion={institucion} onCerrar={() => setConfirmar(null)} onSesionTerminada={terminada} onAprovisionada={data => {
      setCredenciales(data.administrador_inicial)
      setAviso('Institución aprovisionada. Ya se puede activar.')
      setConfirmar(null)
      recargar()
    }} />}
    {confirmar && confirmar !== 'aprovisionar' && <ConfirmarEstado accion={confirmar} institucion={institucion} onConfirmar={cambiarEstado} onCerrar={() => setConfirmar(null)} />}
  </section>
}
